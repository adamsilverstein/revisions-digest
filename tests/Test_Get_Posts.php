<?php

namespace RevisionsDigest\Tests;

class Test_Get_Posts extends TestCase {

	public function test_recently_updated_post_fetching_is_accurate() {
		global $wpdb;

		$eight_days_ago = strtotime( '-8 days' );
		$week_ago       = strtotime( '-1 week' );
		$four_days_ago  = strtotime( '-4 days' );

		$last_week  = self::post_factory( [
			'post_modified' => date( 'Y-m-d H:i:s', $eight_days_ago ),
		] );
		$borderline = self::post_factory( [
			'post_modified' => date( 'Y-m-d H:i:s', $week_ago ),
		] );
		$this_week  = self::post_factory( [
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
		] );

		$actual   = \RevisionsDigest\get_updated_posts( $week_ago );
		$expected = [
			$this_week->ID,
		];

		$this->assertEquals( date( 'Y-m-d H:i:s', $eight_days_ago ), $last_week->post_modified );
		$this->assertEquals( date( 'Y-m-d H:i:s', $week_ago ), $borderline->post_modified );
		$this->assertEquals( date( 'Y-m-d H:i:s', $four_days_ago ), $this_week->post_modified );

		$this->assertEquals( $expected, $actual );
	}

	public function test_post_types_filter_includes_additional_types() {
		$four_days_ago = strtotime( '-4 days' );
		$week_ago      = strtotime( '-1 week' );

		$page = self::post_factory( [
			'post_type'     => 'page',
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
		] );
		$post = self::post_factory( [
			'post_type'     => 'post',
			'post_modified' => date( 'Y-m-d H:i:s', $four_days_ago ),
		] );

		// Without filter, only pages are returned.
		$default_result = \RevisionsDigest\get_updated_posts( $week_ago );
		$this->assertContains( $page->ID, $default_result );
		$this->assertNotContains( $post->ID, $default_result );

		// With filter, both types are returned.
		$filter = function () {
			return [ 'page', 'post' ];
		};
		add_filter( 'revisions_digest_post_types', $filter );

		$filtered_result = \RevisionsDigest\get_updated_posts( $week_ago );
		$this->assertContains( $page->ID, $filtered_result );
		$this->assertContains( $post->ID, $filtered_result );

		remove_filter( 'revisions_digest_post_types', $filter );
	}

}
