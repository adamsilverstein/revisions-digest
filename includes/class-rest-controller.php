<?php
/**
 * REST API Controller for Revisions Digest
 *
 * @package revisions-digest
 */

declare( strict_types=1 );

namespace RevisionsDigest;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API controller for the Revisions Digest plugin.
 */
class REST_Controller extends WP_REST_Controller {

	/**
	 * Namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'revisions-digest/v1';

	/**
	 * Base route for the REST API.
	 *
	 * @var string
	 */
	protected $rest_base = 'digest';

	/**
	 * Register routes for the REST API.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 * Check if a given request has access to get items.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return bool|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access this endpoint.', 'revisions-digest' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'revisions-digest' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Get a collection of digest items.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_items( $request ): WP_REST_Response {
		$period   = $request->get_param( 'period' );
		$group_by = $request->get_param( 'group_by' );
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		$digest      = new Digest( $period, $group_by );
		$all_changes = $digest->get_changes();

		$total    = count( $all_changes );
		$pages    = (int) ceil( $total / $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$changes  = array_slice( $all_changes, $offset, $per_page );

		$response_data = [];

		foreach ( $changes as $change ) {
			$authors = array_filter(
				array_map(
					function ( int $user_id ) {
						$user = get_userdata( $user_id );
						if ( ! $user ) {
								return false;
						}

						return [
							'id'           => $user_id,
							'display_name' => $user->display_name,
						];
					},
					$change['authors']
				)
			);

			$response_data[] = [
				'post_id'          => $change['post_id'],
				'post_title'       => get_the_title( $change['post_id'] ),
				'post_url'         => get_permalink( $change['post_id'] ),
				'edit_url'         => get_edit_post_link( $change['post_id'], 'raw' ),
				'rendered'         => $change['rendered'],
				'title_rendered'   => $change['title_rendered'] ?? '',
				'excerpt_rendered' => $change['excerpt_rendered'] ?? '',
				'authors'          => array_values( $authors ),
			];
		}

		$response = new WP_REST_Response(
			[
				'period'   => $period,
				'group_by' => $group_by,
				'changes'  => $response_data,
				'count'    => count( $response_data ),
			],
			200
		);

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );

		return $response;
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params(): array {
		return [
			'period'   => [
				'description'       => __( 'Time period for the digest.', 'revisions-digest' ),
				'type'              => 'string',
				'default'           => Digest::PERIOD_WEEK,
				'enum'              => [
					Digest::PERIOD_DAY,
					Digest::PERIOD_WEEK,
					Digest::PERIOD_MONTH,
				],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => [ $this, 'validate_period' ],
			],
			'group_by' => [
				'description'       => __( 'How to group the results.', 'revisions-digest' ),
				'type'              => 'string',
				'default'           => Digest::GROUP_BY_POST,
				'enum'              => [
					Digest::GROUP_BY_POST,
					Digest::GROUP_BY_DATE,
					Digest::GROUP_BY_USER,
					Digest::GROUP_BY_TAXONOMY,
				],
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page'     => [
				'description'       => __( 'Current page of the collection.', 'revisions-digest' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'description'       => __( 'Maximum number of items to return per page.', 'revisions-digest' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * Validate the period parameter.
	 *
	 * @param string          $value   The parameter value.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_period( $value, $request, $param ) {
		$valid_periods = [
			Digest::PERIOD_DAY,
			Digest::PERIOD_WEEK,
			Digest::PERIOD_MONTH,
		];

		if ( ! in_array( $value, $valid_periods, true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: 1: Parameter name, 2: List of valid values */
					__( '%1$s must be one of: %2$s', 'revisions-digest' ),
					$param,
					implode( ', ', $valid_periods )
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
