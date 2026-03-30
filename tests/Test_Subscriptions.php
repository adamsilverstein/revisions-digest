<?php

namespace RevisionsDigest\Tests;

/**
 * @group subscriptions
 */
class Test_Subscriptions extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'revisions_digest_subscriptions' );
	}

	public function test_add_subscription_returns_id() {
		$result = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'weekly',
		] );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'sub_', $result );
	}

	public function test_add_subscription_invalid_email_returns_error() {
		$result = \RevisionsDigest\add_email_subscription( [
			'email'     => 'not-an-email',
			'frequency' => 'weekly',
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_email', $result->get_error_code() );
	}

	public function test_add_subscription_empty_email_returns_error() {
		$result = \RevisionsDigest\add_email_subscription( [] );

		$this->assertWPError( $result );
	}

	public function test_add_subscription_invalid_frequency_defaults_to_weekly() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'hourly',
		] );

		$sub = \RevisionsDigest\get_subscription( $id );
		$this->assertEquals( 'weekly', $sub['frequency'] );
	}

	public function test_add_subscription_stores_data_correctly() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'daily',
		] );

		$sub = \RevisionsDigest\get_subscription( $id );

		$this->assertEquals( 'test@example.com', $sub['email'] );
		$this->assertEquals( 'daily', $sub['frequency'] );
		$this->assertEquals( 0, $sub['last_sent'] );
		$this->assertArrayHasKey( 'created', $sub );
		$this->assertArrayHasKey( 'user_id', $sub );
	}

	public function test_get_subscription_returns_null_for_missing_id() {
		$this->assertNull( \RevisionsDigest\get_subscription( 'nonexistent' ) );
	}

	public function test_get_email_subscriptions_returns_all() {
		\RevisionsDigest\add_email_subscription( [
			'email'     => 'a@example.com',
			'frequency' => 'daily',
		] );
		\RevisionsDigest\add_email_subscription( [
			'email'     => 'b@example.com',
			'frequency' => 'weekly',
		] );

		$all = \RevisionsDigest\get_email_subscriptions();
		$this->assertCount( 2, $all );
	}

	public function test_update_subscription_changes_email() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'old@example.com',
			'frequency' => 'weekly',
		] );

		$result = \RevisionsDigest\update_email_subscription( $id, [
			'email' => 'new@example.com',
		] );

		$this->assertTrue( $result );
		$sub = \RevisionsDigest\get_subscription( $id );
		$this->assertEquals( 'new@example.com', $sub['email'] );
	}

	public function test_update_subscription_changes_frequency() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'weekly',
		] );

		\RevisionsDigest\update_email_subscription( $id, [
			'frequency' => 'monthly',
		] );

		$sub = \RevisionsDigest\get_subscription( $id );
		$this->assertEquals( 'monthly', $sub['frequency'] );
	}

	public function test_update_subscription_invalid_email_returns_error() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'weekly',
		] );

		$result = \RevisionsDigest\update_email_subscription( $id, [
			'email' => 'bad-email',
		] );

		$this->assertWPError( $result );
		// Original email should be unchanged.
		$sub = \RevisionsDigest\get_subscription( $id );
		$this->assertEquals( 'test@example.com', $sub['email'] );
	}

	public function test_update_nonexistent_subscription_returns_error() {
		$result = \RevisionsDigest\update_email_subscription( 'fake_id', [
			'email' => 'test@example.com',
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	public function test_delete_subscription() {
		$id = \RevisionsDigest\add_email_subscription( [
			'email'     => 'test@example.com',
			'frequency' => 'weekly',
		] );

		$result = \RevisionsDigest\delete_email_subscription( $id );
		$this->assertTrue( $result );
		$this->assertNull( \RevisionsDigest\get_subscription( $id ) );
	}

	public function test_delete_nonexistent_subscription_returns_error() {
		$result = \RevisionsDigest\delete_email_subscription( 'fake_id' );

		$this->assertWPError( $result );
		$this->assertEquals( 'not_found', $result->get_error_code() );
	}

	public function test_should_send_digest_respects_daily_interval() {
		$sub_never_sent = [
			'frequency' => 'daily',
			'last_sent' => 0,
		];
		$this->assertTrue( \RevisionsDigest\should_send_digest( $sub_never_sent ) );

		$sub_sent_recently = [
			'frequency' => 'daily',
			'last_sent' => time() - HOUR_IN_SECONDS,
		];
		$this->assertFalse( \RevisionsDigest\should_send_digest( $sub_sent_recently ) );

		$sub_sent_yesterday = [
			'frequency' => 'daily',
			'last_sent' => time() - DAY_IN_SECONDS - 1,
		];
		$this->assertTrue( \RevisionsDigest\should_send_digest( $sub_sent_yesterday ) );
	}

	public function test_should_send_digest_respects_weekly_interval() {
		$sub_sent_3_days_ago = [
			'frequency' => 'weekly',
			'last_sent' => time() - ( 3 * DAY_IN_SECONDS ),
		];
		$this->assertFalse( \RevisionsDigest\should_send_digest( $sub_sent_3_days_ago ) );

		$sub_sent_8_days_ago = [
			'frequency' => 'weekly',
			'last_sent' => time() - ( 8 * DAY_IN_SECONDS ),
		];
		$this->assertTrue( \RevisionsDigest\should_send_digest( $sub_sent_8_days_ago ) );
	}

	public function test_get_timeframe_for_frequency() {
		$this->assertEquals( '-1 day', \RevisionsDigest\get_timeframe_for_frequency( 'daily' ) );
		$this->assertEquals( '-1 week', \RevisionsDigest\get_timeframe_for_frequency( 'weekly' ) );
		$this->assertEquals( '-1 month', \RevisionsDigest\get_timeframe_for_frequency( 'monthly' ) );
		$this->assertEquals( '-1 week', \RevisionsDigest\get_timeframe_for_frequency( 'invalid' ) );
	}
}
