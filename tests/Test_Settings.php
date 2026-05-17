<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\Digest;

/**
 * @group settings
 */
class Test_Settings extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'revisions_digest_period' );
	}

	public function test_configured_period_defaults_to_week() {
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\get_configured_period() );
	}

	public function test_configured_period_returns_saved_value() {
		update_option( 'revisions_digest_period', Digest::PERIOD_DAY );
		$this->assertSame( Digest::PERIOD_DAY, \RevisionsDigest\get_configured_period() );

		update_option( 'revisions_digest_period', Digest::PERIOD_MONTH );
		$this->assertSame( Digest::PERIOD_MONTH, \RevisionsDigest\get_configured_period() );
	}

	public function test_configured_period_falls_back_for_invalid_value() {
		update_option( 'revisions_digest_period', 'fortnight' );
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\get_configured_period() );
	}

	public function test_sanitize_period_setting_accepts_valid_periods() {
		$this->assertSame( Digest::PERIOD_DAY, \RevisionsDigest\sanitize_period_setting( 'day' ) );
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\sanitize_period_setting( 'week' ) );
		$this->assertSame( Digest::PERIOD_MONTH, \RevisionsDigest\sanitize_period_setting( 'month' ) );
	}

	public function test_sanitize_period_setting_rejects_invalid_value() {
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\sanitize_period_setting( 'year' ) );
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\sanitize_period_setting( '' ) );
		$this->assertSame( Digest::PERIOD_WEEK, \RevisionsDigest\sanitize_period_setting( [ 'week' ] ) );
	}

	/**
	 * The dashboard widget / RSS entry point honours the configured period:
	 * a day-period digest must not include a change made five days ago that a
	 * week-period digest would include.
	 */
	public function test_get_digest_changes_honours_configured_period() {
		$five_days_ago = strtotime( '-5 days' );

		$page = self::post_factory( [
			'post_content'  => 'Original content',
			'post_modified' => date( 'Y-m-d H:i:s', $five_days_ago ),
		] );
		wp_save_post_revision( $page->ID );
		wp_update_post( [
			'ID'           => $page->ID,
			'post_content' => 'Updated content',
		] );
		wp_save_post_revision( $page->ID );

		global $wpdb;
		// Backdate the page's publish date too so it isn't picked up as a
		// newly "added" post, isolating the configured-period (modified) path.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_date'     => date( 'Y-m-d H:i:s', $five_days_ago ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $five_days_ago ),
			],
			[ 'ID' => $page->ID ]
		);
		$ids = array_merge( [ $page->ID ], wp_list_pluck( wp_get_post_revisions( $page->ID ), 'ID' ) );
		foreach ( $ids as $id ) {
			$wpdb->update(
				$wpdb->posts,
				[
					'post_modified'     => date( 'Y-m-d H:i:s', $five_days_ago ),
					'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $five_days_ago ),
				],
				[ 'ID' => $id ]
			);
			clean_post_cache( $id );
		}

		update_option( 'revisions_digest_period', Digest::PERIOD_WEEK );
		Digest::flush_cache();
		$this->assertNotEmpty( \RevisionsDigest\get_digest_changes(), 'Week period should include a 5-day-old change.' );

		update_option( 'revisions_digest_period', Digest::PERIOD_DAY );
		Digest::flush_cache();
		$this->assertEmpty( \RevisionsDigest\get_digest_changes(), 'Day period should exclude a 5-day-old change.' );
	}
}
