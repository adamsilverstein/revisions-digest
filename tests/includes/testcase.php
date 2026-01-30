<?php

namespace RevisionsDigest\Tests;

class TestCase extends \WP_UnitTestCase {

	public static function post_factory( array $args = [] ) : \WP_Post {
		global $wpdb;

		// Default to page type and published status to match plugin queries.
		$args = array_merge( [
			'post_type'   => 'page',
			'post_status' => 'publish',
		], $args );

		$post_id = self::factory()->post->create( $args );

		if ( isset( $args['post_modified'] ) ) {
			// wp_insert_post() doesn't support the post_modified parameter,
			// so this needs to be set manually. Also set post_modified_gmt.
			$wpdb->update( $wpdb->posts, [
				'post_modified'     => $args['post_modified'],
				'post_modified_gmt' => get_gmt_from_date( $args['post_modified'] ),
			], [
				'ID' => $post_id,
			] );
		}

		clean_post_cache( $post_id );

		return get_post( $post_id );
	}

}
