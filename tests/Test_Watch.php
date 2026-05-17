<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\Digest;

/**
 * @group watch
 */
class Test_Watch extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'revisions_digest_watch' );
		Digest::flush_cache();
	}

	public function tear_down(): void {
		remove_all_filters( 'revisions_digest_watch' );
		parent::tear_down();
	}

	/**
	 * Create a page modified within the last week with two revisions.
	 *
	 * @param string $title Page title.
	 * @return \WP_Post
	 */
	private function modified_page( string $title ): \WP_Post {
		$two_days_ago = strtotime( '-2 days' );
		$page         = self::post_factory( [
			'post_title'    => $title,
			'post_content'  => 'Original content',
			'post_modified' => date( 'Y-m-d H:i:s', $two_days_ago ),
		] );
		wp_save_post_revision( $page->ID );
		wp_update_post( [
			'ID'           => $page->ID,
			'post_content' => 'Updated content for ' . $title,
		] );
		wp_save_post_revision( $page->ID );

		return get_post( $page->ID );
	}

	public function test_empty_watch_list_includes_all_modified_posts() {
		$a = $this->modified_page( 'Page A' );
		$b = $this->modified_page( 'Page B' );

		$ids = wp_list_pluck( ( new Digest() )->get_changes(), 'post_id' );

		$this->assertContains( $a->ID, $ids );
		$this->assertContains( $b->ID, $ids );
	}

	public function test_watch_by_post_id_limits_results() {
		$watched   = $this->modified_page( 'Watched' );
		$unwatched = $this->modified_page( 'Unwatched' );

		update_option( 'revisions_digest_watch', [ 'post_ids' => [ $watched->ID ], 'term_ids' => [] ] );
		Digest::flush_cache();

		$ids = wp_list_pluck( ( new Digest() )->get_changes(), 'post_id' );

		$this->assertContains( $watched->ID, $ids );
		$this->assertNotContains( $unwatched->ID, $ids );
	}

	public function test_watch_by_term_id_limits_results() {
		$category = self::factory()->category->create( [ 'name' => 'Docs' ] );

		$in_term     = $this->modified_page( 'In Docs' );
		$not_in_term = $this->modified_page( 'Not In Docs' );
		wp_set_object_terms( $in_term->ID, [ $category ], 'category' );

		update_option( 'revisions_digest_watch', [ 'post_ids' => [], 'term_ids' => [ $category ] ] );
		Digest::flush_cache();

		$ids = wp_list_pluck( ( new Digest() )->get_changes(), 'post_id' );

		$this->assertContains( $in_term->ID, $ids );
		$this->assertNotContains( $not_in_term->ID, $ids );
	}

	public function test_watch_filter_overrides_option() {
		$watched   = $this->modified_page( 'Filter Watched' );
		$unwatched = $this->modified_page( 'Filter Unwatched' );

		add_filter(
			'revisions_digest_watch',
			static function () use ( $watched ) {
				return [ 'post_ids' => [ $watched->ID ], 'term_ids' => [] ];
			}
		);
		Digest::flush_cache();

		$ids = wp_list_pluck( ( new Digest() )->get_changes(), 'post_id' );

		$this->assertSame( [ $watched->ID ], $ids );
		$this->assertNotContains( $unwatched->ID, $ids );
	}

	public function test_cache_key_changes_with_watch_config() {
		$before = ( new Digest() )->get_cache_key();

		update_option( 'revisions_digest_watch', [ 'post_ids' => [ 42 ], 'term_ids' => [] ] );

		$this->assertNotSame( $before, ( new Digest() )->get_cache_key() );
	}

	public function test_sanitize_watch_setting_parses_comma_separated_ids() {
		$result = \RevisionsDigest\sanitize_watch_setting(
			[
				'post_ids' => '12, 34, foo, 56',
				'term_ids' => '7,8',
			]
		);

		$this->assertSame( [ 12, 34, 56 ], $result['post_ids'] );
		$this->assertSame( [ 7, 8 ], $result['term_ids'] );
	}

	public function test_sanitize_watch_setting_handles_garbage() {
		$result = \RevisionsDigest\sanitize_watch_setting( 'not-an-array' );

		$this->assertSame( [ 'post_ids' => [], 'term_ids' => [] ], $result );
	}
}
