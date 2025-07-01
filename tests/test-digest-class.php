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
		global $wpdb;

		// Create a post that was modified in the last week
		$four_days_ago = strtotime( '-4 days' );
		$post = self::post_factory( [
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
			'post_content'  => 'Original content',
		] );

		// Create a revision for this post
		$revision_id = wp_save_post_revision( $post->ID );
		if ( $revision_id ) {
			$wpdb->update( $wpdb->posts, [
				'post_content' => 'Updated content with changes',
				'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago + 3600 ), // 1 hour later
			], [
				'ID' => $revision_id,
			] );
		}

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
}