<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\Digest;

/**
 * Added / removed content entries.
 *
 * @group lifecycle
 */
class Test_Lifecycle extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Digest::flush_cache();
	}

	private function entry_for( array $changes, int $post_id ): ?array {
		foreach ( $changes as $change ) {
			if ( (int) ( $change['post_id'] ?? 0 ) === $post_id ) {
				return $change;
			}
		}
		return null;
	}

	public function test_newly_published_post_appears_as_added() {
		$page = self::post_factory( [
			'post_title'  => 'Brand New Page',
			'post_date'   => date( 'Y-m-d H:i:s', strtotime( '-2 days' ) ),
		] );

		$changes = ( new Digest() )->get_changes();
		$entry   = $this->entry_for( $changes, $page->ID );

		$this->assertNotNull( $entry, 'Newly published page should be in the digest.' );
		$this->assertSame( 'added', $entry['type'] );
	}

	public function test_trashed_post_appears_as_removed() {
		$page = self::post_factory( [
			'post_title' => 'Doomed Page',
			'post_date'  => date( 'Y-m-d H:i:s', strtotime( '-3 weeks' ) ),
		] );
		wp_trash_post( $page->ID );

		$changes = ( new Digest() )->get_changes();
		$entry   = $this->entry_for( $changes, $page->ID );

		$this->assertNotNull( $entry, 'Trashed page should be in the digest.' );
		$this->assertSame( 'removed', $entry['type'] );
	}

	public function test_modified_post_keeps_modified_type_and_no_duplicate() {
		$four_days_ago = strtotime( '-4 days' );
		$page          = self::post_factory( [
			'post_content'  => 'Original',
			'post_date'     => date( 'Y-m-d H:i:s', strtotime( '-2 months' ) ),
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
		] );
		wp_save_post_revision( $page->ID );
		wp_update_post( [ 'ID' => $page->ID, 'post_content' => 'Updated' ] );
		wp_save_post_revision( $page->ID );

		$changes = ( new Digest() )->get_changes();
		$ids     = wp_list_pluck( $changes, 'post_id' );

		$this->assertSame( [ $page->ID ], array_values( array_filter( $ids, fn( $id ) => $id === $page->ID ) ) );
		$this->assertSame( 'modified', $this->entry_for( $changes, $page->ID )['type'] );
	}

	public function test_grouped_changes_do_not_fatal_for_added_and_removed() {
		$added = self::post_factory( [
			'post_title' => 'Fresh',
			'post_date'  => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );
		$removed = self::post_factory( [
			'post_title' => 'Gone',
			'post_date'  => date( 'Y-m-d H:i:s', strtotime( '-2 months' ) ),
		] );
		wp_trash_post( $removed->ID );

		$grouped = ( new Digest() )->get_grouped_changes();

		$this->assertNotEmpty( $grouped );
		foreach ( $grouped as $group ) {
			$this->assertArrayHasKey( 'description', $group );
			$this->assertNotEmpty( $group['description'] );
		}
	}

	public function test_watch_list_still_applies_to_added_entries() {
		$watched = self::post_factory( [
			'post_title' => 'Watched New',
			'post_date'  => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );
		$other = self::post_factory( [
			'post_title' => 'Unwatched New',
			'post_date'  => date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
		] );

		update_option( 'revisions_digest_watch', [ 'post_ids' => [ $watched->ID ], 'term_ids' => [] ] );
		Digest::flush_cache();

		$ids = wp_list_pluck( ( new Digest() )->get_changes(), 'post_id' );

		$this->assertContains( $watched->ID, $ids );
		$this->assertNotContains( $other->ID, $ids );

		delete_option( 'revisions_digest_watch' );
	}
}
