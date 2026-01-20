<?php
/**
 * Revisions Digest plugin for WordPress
 *
 * @package   revisions-digest
 * @link      https://github.com/johnbillion/revisions-digest
 * @author    John Blackbourn <john@johnblackbourn.com>
 * @copyright 2017 John Blackbourn
 * @license   GPL v2 or later
 *
 * Plugin Name:     Revisions Digest
 * Plugin URI:      https://wordpress.org/plugins/revisions-digest
 * Description:     Digests of revisions.
 * Version:         0.1.0
 * Author:          John Blackbourn
 * Author URI:      https://johnblackbourn.com
 * Text Domain:     revisions-digest
 * Domain Path:     /languages
 * Requires PHP:    7.0
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

declare( strict_types=1 );

namespace RevisionsDigest;

use WP_Query;
use WP_Post;
use WP_Error;
use Text_Diff;
use Text_Diff_Renderer;
use WP_Text_Diff_Renderer_Table;

// Include the Digest helper class
require_once __DIR__ . '/includes/class-digest.php';

// Include the REST controller class
require_once __DIR__ . '/includes/class-rest-controller.php';

add_action( 'wp_dashboard_setup', function() {
	add_meta_box(
		'revisions_digest_dashboard',
		__( 'Recent Changes', 'revisions-digest' ),
		__NAMESPACE__ . '\widget',
		'index.php',
		'column3',
		'high'
	);
} );

// Register REST API routes.
add_action( 'rest_api_init', function() {
	$controller = new REST_Controller();
	$controller->register_routes();
} );

// Enqueue dashboard assets.
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_dashboard_assets' );

/**
 * Enqueue dashboard widget assets.
 *
 * @param string $hook_suffix The current admin page.
 * @return void
 */
function enqueue_dashboard_assets( string $hook_suffix ) : void {
	if ( 'index.php' !== $hook_suffix ) {
		return;
	}

	$plugin_url = plugin_dir_url( __FILE__ );

	wp_enqueue_style(
		'revisions-digest-widget',
		$plugin_url . 'assets/css/widget.css',
		[],
		'0.1.0'
	);

	wp_enqueue_script(
		'revisions-digest-widget',
		$plugin_url . 'assets/js/widget.js',
		[ 'wp-api-fetch', 'wp-i18n' ],
		'0.1.0',
		true
	);

	wp_localize_script(
		'revisions-digest-widget',
		'revisionsDigestData',
		[
			'restUrl' => rest_url( 'revisions-digest/v1/digest' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'periods' => [
				Digest::PERIOD_DAY   => __( 'Today', 'revisions-digest' ),
				Digest::PERIOD_WEEK  => __( 'This Week', 'revisions-digest' ),
				Digest::PERIOD_MONTH => __( 'This Month', 'revisions-digest' ),
			],
		]
	);
}

/**
 * Dashboard widget callback.
 *
 * @param mixed $no_idea  The object passed to the callback.
 * @param array $meta_box The meta box arguments.
 * @return void
 */
function widget( $no_idea, array $meta_box ) : void {
	$default_period = Digest::PERIOD_WEEK;
	?>
	<div class="revisions-digest-widget">
		<div class="revisions-digest-period-selector">
			<button type="button" class="button revisions-digest-period-btn" data-period="<?php echo esc_attr( Digest::PERIOD_DAY ); ?>">
				<?php esc_html_e( 'Today', 'revisions-digest' ); ?>
			</button>
			<button type="button" class="button revisions-digest-period-btn active" data-period="<?php echo esc_attr( Digest::PERIOD_WEEK ); ?>">
				<?php esc_html_e( 'This Week', 'revisions-digest' ); ?>
			</button>
			<button type="button" class="button revisions-digest-period-btn" data-period="<?php echo esc_attr( Digest::PERIOD_MONTH ); ?>">
				<?php esc_html_e( 'This Month', 'revisions-digest' ); ?>
			</button>
		</div>

		<div class="revisions-digest-loading" style="display: none;">
			<span class="spinner is-active"></span>
			<span><?php esc_html_e( 'Loading...', 'revisions-digest' ); ?></span>
		</div>

		<div class="revisions-digest-error" style="display: none;"></div>

		<div class="revisions-digest-results">
			<?php render_widget_content( get_digest_changes() ); ?>
		</div>
	</div>
	<?php
}

/**
 * Render the widget content from changes array.
 *
 * @param array $changes Array of changes to render.
 * @return void
 */
function render_widget_content( array $changes ) : void {
	if ( empty( $changes ) ) {
		echo '<p class="revisions-digest-empty">';
		esc_html_e( 'There have been no content changes in this period.', 'revisions-digest' );
		echo '</p>';
		return;
	}

	foreach ( $changes as $change ) {
		echo '<div class="activity-block">';

		printf(
			'<h3><a href="%1$s">%2$s</a> <a href="%3$s" class="revisions-digest-edit-link">%4$s</a></h3>',
			esc_url( get_permalink( $change['post_id'] ) ),
			esc_html( get_the_title( $change['post_id'] ) ),
			esc_url( get_edit_post_link( $change['post_id'] ) ),
			esc_html__( 'Edit', 'revisions-digest' )
		);

		$authors = array_filter( array_map( function ( int $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return false;
			}

			return $user->display_name;
		}, $change['authors'] ) );

		/* translators: %l: comma-separated list of author names */
		$changes_by = wp_sprintf(
			__( 'Changed by %l', 'revisions-digest' ),
			$authors
		);
		printf(
			'<p>%1$s</p>',
			esc_html( $changes_by )
		);

		echo '<table class="diff">';
		echo $change['rendered']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is pre-escaped by WP_Text_Diff_Renderer_Table.
		echo '</table>';

		echo '</div>';
	}
}

/**
 * Get digest changes (backward compatibility function)
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
function get_digest_changes() : array {
	$digest = new Digest();
	return $digest->get_changes();
}

/**
 * Get digest changes for a specific period
 *
 * @param string $period Time period (day, week, month).
 * @param string $group_by How to group results.
 * @return array Digest changes.
 */
function get_digest_changes_for_period( string $period = Digest::PERIOD_WEEK, string $group_by = Digest::GROUP_BY_POST ) : array {
	$digest = new Digest( $period, $group_by );
	return $digest->get_changes();
}

/**
 * Get digest changes with intelligent descriptions
 *
 * @param string $period Time period (day, week, month).
 * @param string $group_by How to group results.
 * @return array Digest changes with descriptions.
 */
function get_digest_with_descriptions( string $period = Digest::PERIOD_WEEK, string $group_by = Digest::GROUP_BY_POST ) : array {
	$digest = new Digest( $period, $group_by );
	return $digest->get_grouped_changes();
}

/**
 * Undocumented function
 *
 * @param int $timeframe Fetch posts which have been modified since this timestamp.
 * @return int[] Array of post IDs.
 */
function get_updated_posts( int $timeframe ) : array {
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

	// @TODO this might prime the post cache
	/**
	 * $revisions = new WP_Query( [
	 * 'post_type'       => 'revision',
	 * 'post_status'     => 'all',
	 * 'post_parent__in' => $modified->posts,
	 * ] );
	 */

	return $modified->posts;
}

/**
 * Undocumented function
 *
 * @param int $post_id   A post ID.
 * @param int $timeframe Fetch revisions since this timestamp.
 * @return WP_Post[] Array of post revisions.
 */
function get_post_revisions( int $post_id, int $timeframe ) : array {
	$earliest      = date( 'Y-m-d H: i: s', $timeframe );
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
 * Undocumented function
 *
 * @param WP_Post[] $revisions Array of post revisions.
 * @return WP_Post[] {
 *     Associative array of the latest and earliest revisions.
 *
 *     @type WP_Post $latest   The latest revision.
 *     @type WP_Post $earliest The earlist revision.
 * }
 */
function get_bound_revisions( array $revisions ) : array {
	$latest   = reset( $revisions );
	$earliest = end( $revisions );

	return compact( 'latest', 'earliest' );
}

/**
 * Undocumented function
 *
 * @param WP_Post $latest   The latest revision.
 * @param WP_Post $earliest The earliest revision.
 * @return Text_Diff The diff object.
 */
function get_diff( WP_Post $latest, WP_Post $earliest ) : Text_Diff {
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
 * Undocumented function
 *
 * @param Text_Diff          $text_diff The diff object.
 * @param Text_Diff_Renderer $renderer  The diff renderer.
 * @return string The rendered diff.
 */
function render_diff( Text_Diff $text_diff, Text_Diff_Renderer $renderer ) : string {
	$diff = $renderer->render( $text_diff );

	return $diff;
}
