<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\Digest;

class Test_Digest_Class extends TestCase {

	public function test_digest_class_constructor() {
		$digest = new Digest();
		$this->assertInstanceOf( Digest::class, $digest );

		$digest_with_params = new Digest( Digest::PERIOD_DAY, Digest::GROUP_BY_USER );
		$this->assertInstanceOf( Digest::class, $digest_with_params );
	}

	public function test_digest_class_get_changes_returns_array() {
		$digest = new Digest();
		$changes = $digest->get_changes();
		$this->assertIsArray( $changes );
	}

	public function test_digest_class_with_custom_timeframe() {
		$custom_time = strtotime( '-3 days' );
		$digest = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST, $custom_time );
		$changes = $digest->get_changes();
		$this->assertIsArray( $changes );
	}

	public function test_digest_class_get_grouped_changes() {
		$digest = new Digest();
		$grouped_changes = $digest->get_grouped_changes();
		$this->assertIsArray( $grouped_changes );
	}

	public function test_digest_periods_constants() {
		$this->assertEquals( 'day', Digest::PERIOD_DAY );
		$this->assertEquals( 'week', Digest::PERIOD_WEEK );
		$this->assertEquals( 'month', Digest::PERIOD_MONTH );
	}

	public function test_digest_grouping_constants() {
		$this->assertEquals( 'date', Digest::GROUP_BY_DATE );
		$this->assertEquals( 'user', Digest::GROUP_BY_USER );
		$this->assertEquals( 'post', Digest::GROUP_BY_POST );
		$this->assertEquals( 'taxonomy', Digest::GROUP_BY_TAXONOMY );
	}

	public function test_digest_with_posts_and_revisions() {
		// Create a post that was modified in the last week
		$four_days_ago = strtotime( '-4 days' );
		$post = self::post_factory( [
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
			'post_content'  => 'Original content',
		] );

		// Create two revisions for this post
		wp_update_post( [
			'ID'            => $post->ID,
			'post_content'  => 'Updated content v1',
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago + 3600 ),
		] );
		wp_save_post_revision( $post->ID );

		wp_update_post( [
			'ID'            => $post->ID,
			'post_content'  => 'Updated content v2',
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago + 7200 ),
		] );
		wp_save_post_revision( $post->ID );

		$digest = new Digest();
		$changes = $digest->get_changes();

		// We should get changes for our post
		$this->assertNotEmpty( $changes );
	}

	public function test_digest_different_periods() {
		$day_digest = new Digest( Digest::PERIOD_DAY );
		$week_digest = new Digest( Digest::PERIOD_WEEK );
		$month_digest = new Digest( Digest::PERIOD_MONTH );

		$day_changes = $day_digest->get_changes();
		$week_changes = $week_digest->get_changes();
		$month_changes = $month_digest->get_changes();

		$this->assertIsArray( $day_changes );
		$this->assertIsArray( $week_changes );
		$this->assertIsArray( $month_changes );
	}

	public function test_digest_different_groupings() {
		$date_digest = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_DATE );
		$user_digest = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_USER );
		$post_digest = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST );
		$taxonomy_digest = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_TAXONOMY );

		$date_changes = $date_digest->get_changes();
		$user_changes = $user_digest->get_changes();
		$post_changes = $post_digest->get_changes();
		$taxonomy_changes = $taxonomy_digest->get_changes();

		$this->assertIsArray( $date_changes );
		$this->assertIsArray( $user_changes );
		$this->assertIsArray( $post_changes );
		$this->assertIsArray( $taxonomy_changes );
	}

	public function test_digest_backward_compatibility_functions() {
		// Test that the old functions still work
		$changes = \RevisionsDigest\get_digest_changes();
		$this->assertIsArray( $changes );

		// Test new convenience functions
		$period_changes = \RevisionsDigest\get_digest_changes_for_period( Digest::PERIOD_DAY );
		$this->assertIsArray( $period_changes );

		$described_changes = \RevisionsDigest\get_digest_with_descriptions();
		$this->assertIsArray( $described_changes );
	}

	/**
	 * Helper: create a page with two revisions at specific timestamps.
	 * Returns the page post object.
	 *
	 * @param string $old_content   Content of the older revision.
	 * @param string $new_content   Content of the newer revision.
	 * @param int    $old_timestamp Timestamp for the old revision.
	 * @param int    $new_timestamp Timestamp for the new revision.
	 * @return \WP_Post The page.
	 */
	private function create_page_with_revisions( string $old_content, string $new_content, int $old_timestamp, int $new_timestamp ): \WP_Post {
		// Create with old content first.
		$page = self::post_factory( [
			'post_content'  => $old_content,
			'post_modified' => date( 'Y-m-d H:i:s', $old_timestamp ),
		] );

		// Save a revision with old content/date.
		wp_save_post_revision( $page->ID );

		// Update to new content.
		wp_update_post( [
			'ID'           => $page->ID,
			'post_content' => $new_content,
		] );
		wp_save_post_revision( $page->ID );

		// Fix the page post_modified to the desired timestamp.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => date( 'Y-m-d H:i:s', $new_timestamp ),
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $new_timestamp ),
			],
			[ 'ID' => $page->ID ]
		);
		clean_post_cache( $page->ID );

		// Fix the newest revision's date too.
		$revisions = wp_get_post_revisions( $page->ID );
		$newest    = reset( $revisions );
		if ( $newest ) {
			$wpdb->update(
				$wpdb->posts,
				[
					'post_modified'     => date( 'Y-m-d H:i:s', $new_timestamp ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $new_timestamp ),
					'post_date'         => date( 'Y-m-d H:i:s', $new_timestamp ),
					'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $new_timestamp ),
				],
				[ 'ID' => $newest->ID ]
			);
			clean_post_cache( $newest->ID );
		}

		return get_post( $page->ID );
	}

	/**
	 * @group digest
	 */
	public function test_day_period_excludes_older_changes() {
		$this->create_page_with_revisions(
			'Original',
			'Updated',
			strtotime( '-10 days' ),
			strtotime( '-3 days' )
		);

		$digest = new Digest( Digest::PERIOD_DAY );
		$this->assertEmpty( $digest->get_changes() );
	}

	/**
	 * @group digest
	 */
	public function test_month_period_includes_three_week_old_changes() {
		$page = $this->create_page_with_revisions(
			'Content v1',
			'Content v2',
			strtotime( '-2 months' ),
			strtotime( '-3 weeks' )
		);

		// Week should miss it.
		$week = new Digest( Digest::PERIOD_WEEK );
		$this->assertEmpty( $week->get_changes() );

		// Month should find it.
		$month = new Digest( Digest::PERIOD_MONTH );
		$changes = $month->get_changes();
		$this->assertNotEmpty( $changes );
		$this->assertEquals( $page->ID, $changes[0]['post_id'] );
	}

	/**
	 * @group digest
	 */
	public function test_custom_timeframe_overrides_period() {
		$this->create_page_with_revisions(
			'Old content',
			'New content',
			strtotime( '-20 days' ),
			strtotime( '-5 days' )
		);

		// 3-day custom timeframe should miss the 5-day-old post.
		$short = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST, strtotime( '-3 days' ) );
		$this->assertEmpty( $short->get_changes() );

		// 10-day custom timeframe should find it.
		$long = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST, strtotime( '-10 days' ) );
		$this->assertNotEmpty( $long->get_changes() );
	}

	/**
	 * @group digest
	 */
	public function test_changes_include_expected_fields() {
		$page = $this->create_page_with_revisions(
			'Original',
			'Updated',
			strtotime( '-10 days' ),
			strtotime( '-2 days' )
		);

		$digest  = new Digest( Digest::PERIOD_WEEK );
		$changes = $digest->get_changes();

		$this->assertNotEmpty( $changes );
		$change = $changes[0];

		$this->assertArrayHasKey( 'post_id', $change );
		$this->assertArrayHasKey( 'rendered', $change );
		$this->assertArrayHasKey( 'authors', $change );
		$this->assertEquals( $page->ID, $change['post_id'] );
		$this->assertNotEmpty( $change['rendered'] );
	}

	/**
	 * @group digest
	 */
	public function test_group_by_date_keys_by_date_string() {
		$this->create_page_with_revisions(
			'Original content',
			'Grouped content',
			strtotime( '-10 days' ),
			strtotime( '-2 days' )
		);

		$digest  = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_DATE );
		$changes = $digest->get_changes();

		$this->assertNotEmpty( $changes );
		$keys = array_keys( $changes );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $keys[0] );
	}

	/**
	 * The cache key is stable across instances built with the same parameters.
	 *
	 * @group cache
	 */
	public function test_cache_key_is_stable_for_same_parameters() {
		$a = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST );
		$b = new Digest( Digest::PERIOD_WEEK, Digest::GROUP_BY_POST );
		$c = new Digest( Digest::PERIOD_DAY, Digest::GROUP_BY_USER );

		$this->assertSame( $a->get_cache_key(), $b->get_cache_key() );
		$this->assertNotSame( $a->get_cache_key(), $c->get_cache_key() );
	}

	/**
	 * get_changes() writes its result to a transient.
	 *
	 * @group cache
	 */
	public function test_get_changes_writes_transient_cache() {
		$digest = new Digest();
		$this->assertFalse( get_transient( $digest->get_cache_key() ) );

		$digest->get_changes();

		// A miss returns false; an array (even empty) means it was cached.
		$this->assertIsArray( get_transient( $digest->get_cache_key() ) );
	}

	/**
	 * A second call returns the cached payload rather than recomputing.
	 *
	 * @group cache
	 */
	public function test_get_changes_serves_from_cache() {
		$digest = new Digest();
		$digest->get_changes();

		// Poison the cache with a sentinel; a cached read returns it verbatim.
		set_transient( $digest->get_cache_key(), [ 'sentinel' => true ], HOUR_IN_SECONDS );

		$this->assertSame( [ 'sentinel' => true ], ( new Digest() )->get_changes() );
	}

	/**
	 * flush_cache() invalidates previously cached results.
	 *
	 * @group cache
	 */
	public function test_flush_cache_invalidates_cached_results() {
		$digest = new Digest();
		$digest->get_changes();
		set_transient( $digest->get_cache_key(), [ 'sentinel' => true ], HOUR_IN_SECONDS );

		Digest::flush_cache();

		// After a flush the key changes, so the poisoned entry is no longer read.
		$this->assertNotSame( [ 'sentinel' => true ], ( new Digest() )->get_changes() );
	}

	/**
	 * A cache hit returns real objects (not __PHP_Incomplete_Class), so
	 * get_grouped_changes() can still read the diff. This is the round-trip
	 * the stored payload must survive.
	 *
	 * @group cache
	 */
	public function test_cached_payload_round_trips_real_objects() {
		$this->create_page_with_revisions(
			'Original body',
			'Rewritten body with changes',
			strtotime( '-10 days' ),
			strtotime( '-2 days' )
		);

		// First call computes and caches the full object payload.
		$first = ( new Digest() )->get_changes();
		$this->assertNotEmpty( $first );

		// Second call is served from the transient (unserialized objects).
		$cached = ( new Digest() )->get_changes();
		$this->assertNotEmpty( $cached );

		$change = $cached[0];
		$this->assertInstanceOf( \Text_Diff::class, $change['diff'] );
		$this->assertInstanceOf( \WP_Post::class, $change['latest'] );
		$this->assertInstanceOf( \WP_Post::class, $change['earliest'] );
		$this->assertNotInstanceOf( \__PHP_Incomplete_Class::class, $change['diff'] );
		$this->assertSame( $first[0]['post_id'], $change['post_id'] );
		$this->assertSame( $first[0]['rendered'], $change['rendered'] );
	}

	/**
	 * Caching can be disabled via the TTL filter returning a non-positive value.
	 *
	 * @group cache
	 */
	public function test_cache_can_be_disabled_via_ttl_filter() {
		add_filter( 'revisions_digest_cache_ttl', '__return_zero' );

		$digest = new Digest();
		$digest->get_changes();

		$this->assertFalse( get_transient( $digest->get_cache_key() ) );

		remove_filter( 'revisions_digest_cache_ttl', '__return_zero' );
	}

	/**
	 * Text_Diff::getEdits() is not available in all WP test environments.
	 *
	 * @group digest
	 * @requires function Text_Diff::getEdits
	 */
	public function test_grouped_changes_include_descriptions() {
		$this->create_page_with_revisions(
			'Old text',
			'New text for descriptions',
			strtotime( '-10 days' ),
			strtotime( '-2 days' )
		);

		$digest  = new Digest( Digest::PERIOD_WEEK );
		$grouped = $digest->get_grouped_changes();

		$this->assertNotEmpty( $grouped );
		$first = reset( $grouped );
		$this->assertArrayHasKey( 'description', $first );
		$this->assertArrayHasKey( 'changes', $first );
		$this->assertNotEmpty( $first['description'] );
	}
}