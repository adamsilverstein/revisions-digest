<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\CLI_Command;

/**
 * @group cli
 */
class Test_CLI_Command extends TestCase {

	private function fake_change( int $post_id, string $title, array $authors ): array {
		$post = self::post_factory( [ 'post_title' => $title ] );
		// Force a deterministic post_modified for the assertion.
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[ 'post_modified' => '2026-05-01 10:00:00' ],
			[ 'ID' => $post->ID ]
		);
		clean_post_cache( $post->ID );

		return [
			'post_id'  => $post_id,
			'latest'   => get_post( $post->ID ),
			'earliest' => get_post( $post->ID ),
			'authors'  => $authors,
		];
	}

	public function test_command_class_exists_without_wp_cli() {
		$this->assertFalse( defined( 'WP_CLI' ) && WP_CLI );
		$this->assertTrue( class_exists( CLI_Command::class ) );
	}

	public function test_to_rows_on_flat_changes() {
		$changes = [
			$this->fake_change( 11, 'Alpha', [ 1 ] ),
			$this->fake_change( 22, 'Beta', [ 1, 2 ] ),
		];

		$rows = CLI_Command::to_rows( $changes );

		$this->assertCount( 2, $rows );
		$this->assertSame( 11, $rows[0]['post_id'] );
		$this->assertSame( 'Alpha', $rows[0]['title'] );
		$this->assertSame( 2, $rows[1]['authors'] );
		$this->assertSame( '2026-05-01 10:00:00', $rows[0]['modified'] );
	}

	public function test_to_rows_flattens_grouped_structure() {
		$grouped = [
			'2026-05-01' => [ $this->fake_change( 11, 'Alpha', [ 1 ] ) ],
			'2026-05-02' => [
				$this->fake_change( 22, 'Beta', [ 1 ] ),
				$this->fake_change( 33, 'Gamma', [ 1 ] ),
			],
		];

		$rows = CLI_Command::to_rows( $grouped );

		$this->assertCount( 3, $rows );
		$this->assertEqualsCanonicalizing(
			[ 11, 22, 33 ],
			wp_list_pluck( $rows, 'post_id' )
		);
	}

	public function test_to_rows_on_empty() {
		$this->assertSame( [], CLI_Command::to_rows( [] ) );
	}

	public function test_normalize_period_validates_against_constants() {
		$this->assertSame( 'day', CLI_Command::normalize_period( 'day' ) );
		$this->assertSame( 'week', CLI_Command::normalize_period( 'week' ) );
		$this->assertSame( 'month', CLI_Command::normalize_period( 'month' ) );
		$this->assertNull( CLI_Command::normalize_period( 'decade' ) );
	}

	public function test_normalize_group_by_validates_against_constants() {
		$this->assertSame( 'post', CLI_Command::normalize_group_by( 'post' ) );
		$this->assertSame( 'user', CLI_Command::normalize_group_by( 'user' ) );
		$this->assertNull( CLI_Command::normalize_group_by( 'banana' ) );
	}
}
