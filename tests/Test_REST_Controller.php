<?php

namespace RevisionsDigest\Tests;

use RevisionsDigest\REST_Controller;
use RevisionsDigest\Digest;
use WP_REST_Request;
use WP_REST_Server;

class Test_REST_Controller extends TestCase {

	/**
	 * REST Server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * Set up test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$editor_id = $factory->user->create( [
			'role' => 'editor',
		] );
		self::$subscriber_id = $factory->user->create( [
			'role' => 'subscriber',
		] );
	}

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Test that the REST route is registered.
	 */
	public function test_route_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/revisions-digest/v1/digest', $routes );
	}

	/**
	 * Test that unauthenticated requests return 401.
	 */
	public function test_unauthenticated_returns_401() {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test that subscribers (without edit_posts capability) return 403.
	 */
	public function test_subscriber_returns_403() {
		wp_set_current_user( self::$subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test that editors can access the endpoint.
	 */
	public function test_editor_returns_200() {
		wp_set_current_user( self::$editor_id );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test default parameters.
	 */
	public function test_default_parameters() {
		wp_set_current_user( self::$editor_id );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( Digest::PERIOD_WEEK, $data['period'] );
		$this->assertEquals( Digest::GROUP_BY_POST, $data['group_by'] );
	}

	/**
	 * Test custom period parameter.
	 */
	public function test_custom_period_parameter() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$request->set_param( 'period', Digest::PERIOD_DAY );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( Digest::PERIOD_DAY, $data['period'] );
	}

	/**
	 * Test custom group_by parameter.
	 */
	public function test_custom_group_by_parameter() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$request->set_param( 'group_by', Digest::GROUP_BY_DATE );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( Digest::GROUP_BY_DATE, $data['group_by'] );
	}

	/**
	 * Test invalid period returns 400.
	 */
	public function test_invalid_period_returns_400() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$request->set_param( 'period', 'invalid' );
		$response = $this->server->dispatch( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	/**
	 * Test response structure.
	 */
	public function test_response_structure() {
		wp_set_current_user( self::$editor_id );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'period', $data );
		$this->assertArrayHasKey( 'group_by', $data );
		$this->assertArrayHasKey( 'changes', $data );
		$this->assertArrayHasKey( 'count', $data );
		$this->assertIsArray( $data['changes'] );
		$this->assertIsInt( $data['count'] );
	}

	/**
	 * Test response with actual changes.
	 */
	public function test_response_with_changes() {
		wp_set_current_user( self::$editor_id );

		// Create a post that was modified recently.
		$four_days_ago = strtotime( '-4 days' );
		$post          = self::post_factory( [
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_modified' => gmdate( 'Y-m-d H:i:s', $four_days_ago ),
			'post_content'  => 'Original content',
		] );

		// Create revisions.
		wp_update_post( [
			'ID'           => $post->ID,
			'post_content' => 'Updated content v1',
		] );
		wp_save_post_revision( $post->ID );

		wp_update_post( [
			'ID'           => $post->ID,
			'post_content' => 'Updated content v2',
		] );
		wp_save_post_revision( $post->ID );

		$request  = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertGreaterThan( 0, $data['count'] );
		$this->assertNotEmpty( $data['changes'] );

		// Check structure of a change item.
		$change = $data['changes'][0];
		$this->assertArrayHasKey( 'post_id', $change );
		$this->assertArrayHasKey( 'post_title', $change );
		$this->assertArrayHasKey( 'post_url', $change );
		$this->assertArrayHasKey( 'edit_url', $change );
		$this->assertArrayHasKey( 'rendered', $change );
		$this->assertArrayHasKey( 'authors', $change );
	}

	/**
	 * Test all valid periods.
	 */
	public function test_all_valid_periods() {
		wp_set_current_user( self::$editor_id );

		$valid_periods = [
			Digest::PERIOD_DAY,
			Digest::PERIOD_WEEK,
			Digest::PERIOD_MONTH,
		];

		foreach ( $valid_periods as $period ) {
			$request = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
			$request->set_param( 'period', $period );
			$response = $this->server->dispatch( $request );

			$this->assertEquals( 200, $response->get_status(), "Period '$period' should return 200" );
		}
	}

	/**
	 * Test all valid group_by values.
	 */
	public function test_all_valid_group_by_values() {
		wp_set_current_user( self::$editor_id );

		$valid_group_by = [
			Digest::GROUP_BY_POST,
			Digest::GROUP_BY_DATE,
			Digest::GROUP_BY_USER,
			Digest::GROUP_BY_TAXONOMY,
		];

		foreach ( $valid_group_by as $group_by ) {
			$request = new WP_REST_Request( 'GET', '/revisions-digest/v1/digest' );
			$request->set_param( 'group_by', $group_by );
			$response = $this->server->dispatch( $request );

			$this->assertEquals( 200, $response->get_status(), "Group by '$group_by' should return 200" );
		}
	}
}
