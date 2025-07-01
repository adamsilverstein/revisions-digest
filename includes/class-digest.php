<?php
/**
 * Revisions Digest helper class
 *
 * @package   revisions-digest
 */

declare( strict_types=1 );

namespace RevisionsDigest;

use WP_Query;
use WP_Post;
use Text_Diff;
use Text_Diff_Renderer;
use WP_Text_Diff_Renderer_Table;

/**
 * Helper class that encapsulates the Revisions Digest functionality
 */
class Digest {

	/**
	 * Time period constants
	 */
	const PERIOD_DAY   = 'day';
	const PERIOD_WEEK  = 'week';
	const PERIOD_MONTH = 'month';

	/**
	 * Grouping constants
	 */
	const GROUP_BY_DATE     = 'date';
	const GROUP_BY_USER     = 'user';
	const GROUP_BY_POST     = 'post';
	const GROUP_BY_TAXONOMY = 'taxonomy';

	/**
	 * Default time period
	 *
	 * @var string
	 */
	private $period = self::PERIOD_WEEK;

	/**
	 * Grouping method
	 *
	 * @var string
	 */
	private $group_by = self::GROUP_BY_POST;

	/**
	 * Custom timeframe timestamp
	 *
	 * @var int|null
	 */
	private $custom_timeframe = null;

	/**
	 * Constructor
	 *
	 * @param string   $period    Time period (day, week, month).
	 * @param string   $group_by  How to group results.
	 * @param int|null $timeframe Custom timeframe timestamp.
	 */
	public function __construct( string $period = self::PERIOD_WEEK, string $group_by = self::GROUP_BY_POST, int $timeframe = null ) {
		$this->period           = $period;
		$this->group_by         = $group_by;
		$this->custom_timeframe = $timeframe;
	}

	/**
	 * Get digest changes
	 *
	 * @return array[] {
	 *     Array of data about the changes.
	 *
	 *     @type array ...$0 {
	 *         Data about the changes
	 *
	 *         @type int       $post_id  The post ID
	 *         @type WP_Post   $earliest The earliest revision.
	 *         @type WP_Post   $latest   The latest revision.
	 *         @type Text_Diff $diff     The diff object.
	 *         @type string    $rendered The rendered diff.
	 *         @type int[]     $authors  The IDs of authors of the changes.
	 *     }
	 * }
	 */
	public function get_changes() : array {
		$timeframe = $this->get_timeframe();
		$modified  = $this->get_updated_posts( $timeframe );
		$changes   = [];

		foreach ( $modified as $modified_post_id ) {
			$revisions = $this->get_post_revisions( $modified_post_id, $timeframe );
			if ( empty( $revisions ) ) {
				continue;
			}

			if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
				require_once ABSPATH . WPINC . '/wp-diff.php';
			}

			// @TODO this includes the author of the first revision, which it should not
			$authors = array_unique( array_map( 'intval', wp_list_pluck( $revisions, 'post_author' ) ) );
			$bounds  = $this->get_bound_revisions( $revisions );
			$diff    = $this->get_diff( $bounds['latest'], $bounds['earliest'] );

			$renderer = new WP_Text_Diff_Renderer_Table( [
				'show_split_view'        => false,
				'leading_context_lines'  => 1,
				'trailing_context_lines' => 1,
			] );
			$rendered = $this->render_diff( $diff, $renderer );

			$data = [
				'post_id'  => $modified_post_id,
				'latest'   => $bounds['latest'],
				'earliest' => $bounds['earliest'],
				'diff'     => $diff,
				'rendered' => $rendered,
				'authors'  => $authors,
			];

			$changes[] = $data;
		}

		return $this->group_changes( $changes );
	}

	/**
	 * Get grouped changes with intelligent descriptions
	 *
	 * @return array Grouped changes with descriptions.
	 */
	public function get_grouped_changes() : array {
		$changes = $this->get_changes();
		return $this->add_intelligent_descriptions( $changes );
	}

	/**
	 * Get timeframe timestamp based on period
	 *
	 * @return int Timestamp.
	 */
	private function get_timeframe() : int {
		if ( null !== $this->custom_timeframe ) {
			return $this->custom_timeframe;
		}

		switch ( $this->period ) {
			case self::PERIOD_DAY:
				return strtotime( '-1 day' );
			case self::PERIOD_MONTH:
				return strtotime( '-1 month' );
			case self::PERIOD_WEEK:
			default:
				return strtotime( '-1 week' );
		}
	}

	/**
	 * Get updated posts
	 *
	 * @param int $timeframe Fetch posts which have been modified since this timestamp.
	 * @return int[] Array of post IDs.
	 */
	private function get_updated_posts( int $timeframe ) : array {
		$earliest = date( 'Y-m-d H:i:s', $timeframe );

		// Fetch IDs of all posts that have been modified within the time period.
		$modified = new WP_Query( [
			'fields'      => 'ids',
			'post_type'   => 'page', // Just Pages for now.
			'post_status' => 'publish',
			'date_query'  => [
				'after'  => $earliest,
				'column' => 'post_modified',
			],
		] );

		return $modified->posts;
	}

	/**
	 * Get post revisions
	 *
	 * @param int $post_id   A post ID.
	 * @param int $timeframe Fetch revisions since this timestamp.
	 * @return WP_Post[] Array of post revisions.
	 */
	private function get_post_revisions( int $post_id, int $timeframe ) : array {
		$earliest      = date( 'Y-m-d H:i:s', $timeframe );
		$revisions     = wp_get_post_revisions( $post_id );
		$use_revisions = [];

		foreach ( $revisions as $revision_id => $revision ) {
			// @TODO this needs to exclude revisions that occured before the post published date
			$use_revisions[] = $revision;

			// this allows the first revision before the date range to also be included.
			if ( $revision->post_modified < $earliest ) {
				break;
			}
		}

		if ( count( $use_revisions ) < 2 ) {
			return [];
		}

		return $use_revisions;
	}

	/**
	 * Get bound revisions
	 *
	 * @param WP_Post[] $revisions Array of post revisions.
	 * @return WP_Post[] {
	 *     Associative array of the latest and earliest revisions.
	 *
	 *     @type WP_Post $latest   The latest revision.
	 *     @type WP_Post $earliest The earliest revision.
	 * }
	 */
	private function get_bound_revisions( array $revisions ) : array {
		$latest   = reset( $revisions );
		$earliest = end( $revisions );

		return compact( 'latest', 'earliest' );
	}

	/**
	 * Get diff between revisions
	 *
	 * @param WP_Post $latest   The latest revision.
	 * @param WP_Post $earliest The earliest revision.
	 * @return Text_Diff The diff object.
	 */
	private function get_diff( WP_Post $latest, WP_Post $earliest ) : Text_Diff {
		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$left_string  = normalize_whitespace( $earliest->post_content );
		$right_string = normalize_whitespace( $latest->post_content );
		$left_lines   = explode( "\n", $left_string );
		$right_lines  = explode( "\n", $right_string );

		return new Text_Diff( $left_lines, $right_lines );
	}

	/**
	 * Render diff
	 *
	 * @param Text_Diff          $text_diff The diff object.
	 * @param Text_Diff_Renderer $renderer  The diff renderer.
	 * @return string The rendered diff.
	 */
	private function render_diff( Text_Diff $text_diff, Text_Diff_Renderer $renderer ) : string {
		return $renderer->render( $text_diff );
	}

	/**
	 * Group changes based on the grouping method
	 *
	 * @param array $changes Array of changes.
	 * @return array Grouped changes.
	 */
	private function group_changes( array $changes ) : array {
		if ( self::GROUP_BY_POST === $this->group_by ) {
			// Default grouping - no change needed
			return $changes;
		}

		$grouped = [];

		foreach ( $changes as $change ) {
			$key = $this->get_grouping_key( $change );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = [];
			}
			$grouped[ $key ][] = $change;
		}

		return $grouped;
	}

	/**
	 * Get grouping key for a change
	 *
	 * @param array $change Change data.
	 * @return string Grouping key.
	 */
	private function get_grouping_key( array $change ) : string {
		switch ( $this->group_by ) {
			case self::GROUP_BY_DATE:
				return date( 'Y-m-d', strtotime( $change['latest']->post_modified ) );

			case self::GROUP_BY_USER:
				// Group by the first author (primary contributor)
				return (string) $change['authors'][0];

			case self::GROUP_BY_TAXONOMY:
				// For now, group by post categories
				$categories = get_the_category( $change['post_id'] );
				return ! empty( $categories ) ? $categories[0]->name : 'uncategorized';

			case self::GROUP_BY_POST:
			default:
				return (string) $change['post_id'];
		}
	}

	/**
	 * Add intelligent descriptions to changes
	 *
	 * @param array $changes Array of changes.
	 * @return array Changes with descriptions.
	 */
	private function add_intelligent_descriptions( array $changes ) : array {
		$described = [];

		foreach ( $changes as $key => $change_group ) {
			if ( is_array( $change_group ) && isset( $change_group[0] ) ) {
				// This is a grouped set of changes
				$described[ $key ] = [
					'changes'     => $change_group,
					'description' => $this->generate_group_description( $change_group ),
				];
			} else {
				// This is a single change
				$described[ $key ] = [
					'changes'     => [ $change_group ],
					'description' => $this->generate_single_description( $change_group ),
				];
			}
		}

		return $described;
	}

	/**
	 * Generate description for a group of changes
	 *
	 * @param array $changes Array of changes.
	 * @return string Description.
	 */
	private function generate_group_description( array $changes ) : string {
		$count   = count( $changes );
		$authors = [];

		foreach ( $changes as $change ) {
			foreach ( $change['authors'] as $author_id ) {
				$user = get_userdata( $author_id );
				if ( $user ) {
					$authors[ $author_id ] = $user->display_name;
				}
			}
		}

		$unique_authors = array_unique( $authors );
		$author_count   = count( $unique_authors );

		if ( 1 === $count ) {
			return $this->generate_single_description( $changes[0] );
		}

		$period_desc = $this->get_period_description();

		if ( 1 === $author_count ) {
			return sprintf(
				'%s made %d changes %s',
				reset( $unique_authors ),
				$count,
				$period_desc
			);
		}

		return sprintf(
			'%d authors made %d changes %s',
			$author_count,
			$count,
			$period_desc
		);
	}

	/**
	 * Generate description for a single change
	 *
	 * @param array $change Change data.
	 * @return string Description.
	 */
	private function generate_single_description( array $change ) : string {
		$authors = [];
		foreach ( $change['authors'] as $author_id ) {
			$user = get_userdata( $author_id );
			if ( $user ) {
				$authors[] = $user->display_name;
			}
		}

		$author_list = implode( ' and ', $authors );
		$time_desc   = $this->get_time_description( $change['latest']->post_modified );
		$size_desc   = $this->get_change_size_description( $change['diff'] );

		return sprintf(
			'%s made %s %s',
			$author_list,
			$size_desc,
			$time_desc
		);
	}

	/**
	 * Get period description
	 *
	 * @return string Period description.
	 */
	private function get_period_description() : string {
		switch ( $this->period ) {
			case self::PERIOD_DAY:
				return 'in the last day';
			case self::PERIOD_MONTH:
				return 'in the last month';
			case self::PERIOD_WEEK:
			default:
				return 'in the last week';
		}
	}

	/**
	 * Get time description for a change
	 *
	 * @param string $modified_time Modified time.
	 * @return string Time description.
	 */
	private function get_time_description( string $modified_time ) : string {
		$time_diff = time() - strtotime( $modified_time );

		if ( $time_diff < DAY_IN_SECONDS ) {
			return 'today';
		} elseif ( $time_diff < 2 * DAY_IN_SECONDS ) {
			return 'yesterday';
		} elseif ( $time_diff < WEEK_IN_SECONDS ) {
			$days = floor( $time_diff / DAY_IN_SECONDS );
			return sprintf( '%d days ago', $days );
		} else {
			$weeks = floor( $time_diff / WEEK_IN_SECONDS );
			return sprintf( '%d week%s ago', $weeks, $weeks > 1 ? 's' : '' );
		}
	}

	/**
	 * Get change size description
	 *
	 * @param Text_Diff $diff Diff object.
	 * @return string Size description.
	 */
	private function get_change_size_description( Text_Diff $diff ) : string {
		$edits = $diff->getEdits();
		$total_changes = 0;

		foreach ( $edits as $edit ) {
			if ( method_exists( $edit, 'orig' ) && method_exists( $edit, 'final' ) ) {
				$total_changes += max( count( $edit->orig ), count( $edit->final ) );
			}
		}

		if ( $total_changes < 5 ) {
			return 'small changes';
		} elseif ( $total_changes < 20 ) {
			return 'several changes';
		} elseif ( $total_changes < 50 ) {
			return 'substantial changes';
		} else {
			return 'major changes';
		}
	}
}