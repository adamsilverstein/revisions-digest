<?php
/**
 * WP-CLI command for the Revisions Digest plugin.
 *
 * @package   revisions-digest
 */

declare( strict_types=1 );

namespace RevisionsDigest;

/**
 * Generate and manage revisions digests from the command line.
 */
class CLI_Command {

	/**
	 * List recent content changes.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : How far back to look. One of day, week, month.
	 * ---
	 * default: week
	 * options:
	 *   - day
	 *   - week
	 *   - month
	 * ---
	 *
	 * [--group-by=<group_by>]
	 * : How to group results. One of post, date, user, taxonomy.
	 * ---
	 * default: post
	 * options:
	 *   - post
	 *   - date
	 *   - user
	 *   - taxonomy
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output as a table, JSON, CSV, YAML, or count.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp revisions-digest list --period=month --format=json
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$period   = self::normalize_period( (string) ( $assoc_args['period'] ?? Digest::PERIOD_WEEK ) );
		$group_by = self::normalize_group_by( (string) ( $assoc_args['group-by'] ?? Digest::GROUP_BY_POST ) );

		if ( null === $period ) {
			\WP_CLI::error( 'Invalid --period. Use day, week, or month.' );
		}
		if ( null === $group_by ) {
			\WP_CLI::error( 'Invalid --group-by. Use post, date, user, or taxonomy.' );
		}

		$digest = new Digest( $period, $group_by );
		$rows   = self::to_rows( $digest->get_changes() );

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No content changes in the selected period.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			[ 'post_id', 'title', 'authors', 'modified' ]
		);
	}

	/**
	 * Clear all cached digests.
	 *
	 * ## EXAMPLES
	 *
	 *     wp revisions-digest flush-cache
	 *
	 * @subcommand flush-cache
	 *
	 * @param string[] $args       Positional arguments (unused).
	 * @param array    $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function flush_cache( array $args, array $assoc_args ): void {
		Digest::flush_cache();
		\WP_CLI::success( 'Revisions digest cache cleared.' );
	}

	/**
	 * Flatten a (possibly grouped) changes structure into table rows.
	 *
	 * @param array $changes Output of Digest::get_changes() or get_grouped_changes().
	 * @return array<int, array{post_id: int, title: string, authors: int, modified: string}>
	 */
	public static function to_rows( array $changes ): array {
		$rows = [];

		foreach ( self::collect_changes( $changes ) as $change ) {
			$latest = $change['latest'] ?? null;

			$rows[] = [
				'post_id'  => (int) ( $change['post_id'] ?? 0 ),
				'title'    => $latest instanceof \WP_Post ? $latest->post_title : '',
				'authors'  => count( (array) ( $change['authors'] ?? [] ) ),
				'modified' => $latest instanceof \WP_Post ? $latest->post_modified : '',
			];
		}

		return $rows;
	}

	/**
	 * Recursively collect individual change entries (arrays with a post_id).
	 *
	 * @param array $node A changes array or nested group.
	 * @return array<int, array> List of change entries.
	 */
	private static function collect_changes( array $node ): array {
		if ( isset( $node['post_id'] ) ) {
			return [ $node ];
		}

		$found = [];
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$found = array_merge( $found, self::collect_changes( $value ) );
			}
		}

		return $found;
	}

	/**
	 * Validate a period string against the Digest constants.
	 *
	 * @param string $period Candidate period.
	 * @return string|null The period, or null if invalid.
	 */
	public static function normalize_period( string $period ): ?string {
		$allowed = [ Digest::PERIOD_DAY, Digest::PERIOD_WEEK, Digest::PERIOD_MONTH ];

		return in_array( $period, $allowed, true ) ? $period : null;
	}

	/**
	 * Validate a group-by string against the Digest constants.
	 *
	 * @param string $group_by Candidate grouping.
	 * @return string|null The grouping, or null if invalid.
	 */
	public static function normalize_group_by( string $group_by ): ?string {
		$allowed = [
			Digest::GROUP_BY_POST,
			Digest::GROUP_BY_DATE,
			Digest::GROUP_BY_USER,
			Digest::GROUP_BY_TAXONOMY,
		];

		return in_array( $group_by, $allowed, true ) ? $group_by : null;
	}
}
