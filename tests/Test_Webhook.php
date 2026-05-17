<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\Digest;

/**
 * @group webhook
 */
class Test_Webhook extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'revisions_digest_webhook_urls' );
		delete_option( 'revisions_digest_webhook_last_sent' );
		Digest::flush_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'revisions_digest_webhook_payload' );
		parent::tear_down();
	}

	public function test_sanitize_webhook_urls_keeps_valid_https_urls() {
		$result = \RevisionsDigest\sanitize_webhook_urls_setting(
			"https://hooks.slack.com/services/AAA\nnot a url\nhttps://example.com/hook"
		);

		$this->assertSame(
			[ 'https://hooks.slack.com/services/AAA', 'https://example.com/hook' ],
			$result
		);
	}

	public function test_sanitize_webhook_urls_handles_garbage() {
		$this->assertSame( [], \RevisionsDigest\sanitize_webhook_urls_setting( 12345 ) );
		$this->assertSame( [], \RevisionsDigest\sanitize_webhook_urls_setting( 'ftp://nope.example' ) );
	}

	public function test_build_payload_has_slack_friendly_text_and_changes() {
		$changes = [
			[ 'post_id' => 7, 'latest' => null, 'authors' => [ 1 ] ],
		];

		$payload = \RevisionsDigest\build_webhook_payload( $changes );

		$this->assertArrayHasKey( 'text', $payload );
		$this->assertStringContainsString( '1', $payload['text'] );
		$this->assertCount( 1, $payload['changes'] );
		$this->assertSame( 7, $payload['changes'][0]['post_id'] );
	}

	public function test_build_payload_is_filterable() {
		add_filter(
			'revisions_digest_webhook_payload',
			static function ( $payload ) {
				$payload['custom'] = 'yes';
				return $payload;
			}
		);

		$payload = \RevisionsDigest\build_webhook_payload( [] );

		$this->assertSame( 'yes', $payload['custom'] );
	}

	public function test_webhook_due_respects_period_interval() {
		$now = time();

		// Weekly: sent 2 days ago is not due; sent 8 days ago is due.
		$this->assertFalse( \RevisionsDigest\webhook_due( $now - 2 * DAY_IN_SECONDS, Digest::PERIOD_WEEK ) );
		$this->assertTrue( \RevisionsDigest\webhook_due( $now - 8 * DAY_IN_SECONDS, Digest::PERIOD_WEEK ) );

		// Never sent is always due.
		$this->assertTrue( \RevisionsDigest\webhook_due( 0, Digest::PERIOD_DAY ) );
	}

	public function test_send_webhooks_posts_payload_and_records_timestamp() {
		update_option( 'revisions_digest_webhook_urls', [ 'https://example.com/hook' ] );

		$captured = [];
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured[] = [ 'url' => $url, 'body' => $args['body'] ];
				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'ok',
				];
			},
			10,
			3
		);

		\RevisionsDigest\send_digest_webhooks();

		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://example.com/hook', $captured[0]['url'] );
		$this->assertJson( $captured[0]['body'] );
		$this->assertNotEmpty( get_option( 'revisions_digest_webhook_last_sent' ) );
	}

	public function test_send_webhooks_noop_without_urls() {
		$called = false;
		add_filter(
			'pre_http_request',
			static function () use ( &$called ) {
				$called = true;
				return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
			}
		);

		\RevisionsDigest\send_digest_webhooks();

		$this->assertFalse( $called );
	}

	public function test_send_webhooks_skips_when_not_due() {
		update_option( 'revisions_digest_webhook_urls', [ 'https://example.com/hook' ] );
		update_option( 'revisions_digest_webhook_last_sent', time() );

		$called = false;
		add_filter(
			'pre_http_request',
			static function () use ( &$called ) {
				$called = true;
				return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
			}
		);

		\RevisionsDigest\send_digest_webhooks();

		$this->assertFalse( $called );
	}
}
