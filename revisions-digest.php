<?php
/**
 * Revisions Digest plugin for WordPress
 *
 * @package   revisions-digest
 * @link      https://github.com/johnbillion/revisions-digest
 * @author    John Blackbourn <john@johnblackbourn.com>
 * @copyright 2017 John Blackbourn
 * @license   GPL v2 or later
 *
 * Plugin Name:     Revisions Digest
 * Plugin URI:      https://wordpress.org/plugins/revisions-digest
 * Description:     Digests of revisions.
 * Version:         0.1.0
 * Author:          John Blackbourn
 * Author URI:      https://johnblackbourn.com
 * Text Domain:     revisions-digest
 * Domain Path:     /languages
 * Requires PHP:    7.0
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

declare( strict_types=1 );

namespace RevisionsDigest;

use WP_Query;
use WP_Post;
use WP_Error;
use Text_Diff;
use Text_Diff_Renderer;
use WP_Text_Diff_Renderer_Table;

add_action( 'wp_dashboard_setup', function() {
	add_meta_box(
		'revisions_digest_dashboard',
		__( 'Recent Changes', 'revisions-digest' ),
		__NAMESPACE__ . '\widget',
		'index.php',
		'column3',
		'high'
	);
} );

/**
 * Undocumented function
 *
 * @param mixed $no_idea  @TODO find out what this parameter is.
 * @param array $meta_box @TODO find out what this parameter is.
 */
function widget( $no_idea, array $meta_box ) {
	$changes = get_digest_changes();

	if ( empty( $changes ) ) {
		esc_html_e( 'There have been no content changes in the last week', 'revisions-digest' );
		return;
	}

	foreach ( $changes as $change ) {
		echo '<div class="activity-block">';

		printf(
			'<h3><a href="%1$s">%2$s</a></h3>',
			esc_url( get_permalink( $change['post_id'] ) ),
			get_the_title( $change['post_id'] )
		);

		$authors = array_filter( array_map( function( int $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return false;
			}

			return $user->display_name;
		}, $change['authors'] ) );

		/* translators: %l: comma-separated list of author names */
		$changes_by = wp_sprintf(
			__( 'Changed by %l', 'revisions-digest' ),
			$authors
		);
		printf(
			'<p>%1$s</p>',
			esc_html( $changes_by )
		);

		echo '<table class="diff">';
		echo $change['rendered']; // WPCS: XSS ok.
		echo '</table>';

		echo '</div>';
	}
}

/**
 * Undocumented function
 *
 * @return array[] {
 *     Array of data about the changes.
 *
 *     @type array ...$0 {
 *         Data about the changes
 *
 *         @type int       $post_id  The post ID
 *         @type WP_Post   $earliest The earliest revision.
 *         @type WP_Post   $latest   The latest revision.
 *         @type Text_Diff $diff     The diff object.
 *         @type string    $rendered The rendered diff.
 *         @type int[]     $authors  The IDs of authors of the changes.
 *     }
 * }
 */
function get_digest_changes() : array {
	$time     = strtotime( '-1 week' );
	$modified = get_updated_posts( $time );
	$changes  = [];

	foreach ( $modified as $i => $modified_post_id ) {
		$revisions = get_post_revisions( $modified_post_id, $time );
		if ( empty( $revisions ) ) {
			continue;
		}

		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		// @TODO this includes the author of the first revision, which it should not
		$authors = array_unique( array_map( 'intval', wp_list_pluck( $revisions, 'post_author' ) ) );
		$bounds  = get_bound_revisions( $revisions );
		$diff    = get_diff( $bounds['latest'], $bounds['earliest'] );

		$renderer = new WP_Text_Diff_Renderer_Table( [
			'show_split_view'        => false,
			'leading_context_lines'  => 1,
			'trailing_context_lines' => 1,
		] );
		$rendered = render_diff( $diff, $renderer );

		$data = [
			'post_id'  => $modified_post_id,
			'latest'   => $bounds['latest'],
			'earliest' => $bounds['earliest'],
			'diff'     => $diff,
			'rendered' => $rendered,
			'authors'  => $authors,
		];

		$changes[] = $data;
	}

	return $changes;
}

/**
 * Undocumented function
 *
 * @param int $timeframe Fetch posts which have been modified since this timestamp.
 * @return int[] Array of post IDs.
 */
function get_updated_posts( int $timeframe ) : array {
	$earliest = date( 'Y-m-d H:i:s', $timeframe );

	// Fetch IDs of all posts that have been modified within the time period.
	$modified = new WP_Query( [
		'fields'      => 'ids',
		'post_type'   => 'page', // Just Pages for now.
		'post_status' => 'publish',
		'date_query'  => [
			'after'  => $earliest,
			'column' => 'post_modified',
		],
	] );

	// @TODO this might prime the post cache
	/**
	 * $revisions = new WP_Query( [
	 * 'post_type'       => 'revision',
	 * 'post_status'     => 'all',
	 * 'post_parent__in' => $modified->posts,
	 * ] );
	 */

	return $modified->posts;
}

/**
 * Undocumented function
 *
 * @param int $post_id   A post ID.
 * @param int $timeframe Fetch revisions since this timestamp.
 * @return WP_Post[] Array of post revisions.
 */
function get_post_revisions( int $post_id, int $timeframe ) : array {
	$earliest      = date( 'Y-m-d H: i: s', $timeframe );
	$revisions     = wp_get_post_revisions( $post_id );
	$use_revisions = [];

	foreach ( $revisions as $revision_id => $revision ) {
		// @TODO this needs to exclude revisions that occured before the post published date
		$use_revisions[] = $revision;

		// this allows the first revision before the date range to also be included.
		if ( $revision->post_modified < $earliest ) {
			break;
		}
	}

	if ( count( $use_revisions ) < 2 ) {
		return [];
	}

	return $use_revisions;
}

/**
 * Undocumented function
 *
 * @param WP_Post[] $revisions Array of post revisions.
 * @return WP_Post[] {
 *     Associative array of the latest and earliest revisions.
 *
 *     @type WP_Post $latest   The latest revision.
 *     @type WP_Post $earliest The earlist revision.
 * }
 */
function get_bound_revisions( array $revisions ) : array {
	$latest   = reset( $revisions );
	$earliest = end( $revisions );

	return compact( 'latest', 'earliest' );
}

/**
 * Undocumented function
 *
 * @param WP_Post $latest   The latest revision.
 * @param WP_Post $earliest The earliest revision.
 * @return Text_Diff The diff object.
 */
function get_diff( WP_Post $latest, WP_Post $earliest ) : Text_Diff {
	if ( ! class_exists( 'Text_Diff', false ) ) {
		require_once ABSPATH . WPINC . '/wp-diff.php';
	}

	$left_string  = normalize_whitespace( $earliest->post_content );
	$right_string = normalize_whitespace( $latest->post_content );
	$left_lines   = explode( "\n", $left_string );
	$right_lines  = explode( "\n", $right_string );

	return new Text_Diff( $left_lines, $right_lines );
}

/**
 * Undocumented function
 *
 * @param Text_Diff          $text_diff The diff object.
 * @param Text_Diff_Renderer $renderer  The diff renderer.
 * @return string The rendered diff.
 */
function render_diff( Text_Diff $text_diff, Text_Diff_Renderer $renderer ) : string {
	$diff = $renderer->render( $text_diff );

	return $diff;
}

/**
 * Get all email subscriptions.
 *
 * @return array Array of subscriptions keyed by subscription ID.
 */
function get_email_subscriptions() : array {
	return get_option( 'revisions_digest_subscriptions', [] );
}

/**
 * Get a single subscription by ID.
 *
 * @param string $id The subscription ID.
 * @return array|null The subscription data or null if not found.
 */
function get_subscription( string $id ) : ?array {
	$subscriptions = get_email_subscriptions();
	return $subscriptions[ $id ] ?? null;
}

/**
 * Add a new email subscription.
 *
 * @param array $data {
 *     Subscription data.
 *
 *     @type string $email     The email address.
 *     @type string $frequency The frequency (daily, weekly, monthly).
 *     @type array  $post_types The post types to include.
 * }
 * @return string|WP_Error The subscription ID on success, WP_Error on failure.
 */
function add_email_subscription( array $data ) {
	$email = sanitize_email( $data['email'] ?? '' );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'revisions-digest' ) );
	}

	$frequency = sanitize_text_field( $data['frequency'] ?? 'weekly' );
	if ( ! in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ) {
		$frequency = 'weekly';
	}

	$post_types = $data['post_types'] ?? [ 'page' ];
	$user_id    = get_current_user_id();

	$subscriptions = get_email_subscriptions();
	$id            = 'sub_' . wp_generate_password( 12, false );

	$subscriptions[ $id ] = [
		'email'      => $email,
		'frequency'  => $frequency,
		'post_types' => $post_types,
		'created'    => time(),
		'last_sent'  => 0,
		'user_id'    => $user_id,
	];

	update_option( 'revisions_digest_subscriptions', $subscriptions );

	return $id;
}

/**
 * Update an existing email subscription.
 *
 * @param string $id   The subscription ID.
 * @param array  $data The data to update.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function update_email_subscription( string $id, array $data ) {
	$subscriptions = get_email_subscriptions();

	if ( ! isset( $subscriptions[ $id ] ) ) {
		return new WP_Error( 'not_found', __( 'Subscription not found.', 'revisions-digest' ) );
	}

	if ( isset( $data['email'] ) ) {
		$email = sanitize_email( $data['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Invalid email address.', 'revisions-digest' ) );
		}
		$subscriptions[ $id ]['email'] = $email;
	}

	if ( isset( $data['frequency'] ) ) {
		$frequency = sanitize_text_field( $data['frequency'] );
		if ( in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ) {
			$subscriptions[ $id ]['frequency'] = $frequency;
		}
	}

	if ( isset( $data['post_types'] ) ) {
		$subscriptions[ $id ]['post_types'] = array_map( 'sanitize_text_field', $data['post_types'] );
	}

	if ( isset( $data['last_sent'] ) ) {
		$subscriptions[ $id ]['last_sent'] = intval( $data['last_sent'] );
	}

	update_option( 'revisions_digest_subscriptions', $subscriptions );

	return true;
}

/**
 * Delete an email subscription.
 *
 * @param string $id The subscription ID.
 * @return bool|WP_Error True on success, WP_Error on failure.
 */
function delete_email_subscription( string $id ) {
	$subscriptions = get_email_subscriptions();

	if ( ! isset( $subscriptions[ $id ] ) ) {
		return new WP_Error( 'not_found', __( 'Subscription not found.', 'revisions-digest' ) );
	}

	unset( $subscriptions[ $id ] );
	update_option( 'revisions_digest_subscriptions', $subscriptions );

	return true;
}

/**
 * Register custom cron schedules.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
add_filter( 'cron_schedules', function( array $schedules ) : array {
	$schedules['weekly'] = [
		'interval' => WEEK_IN_SECONDS,
		'display'  => __( 'Once Weekly', 'revisions-digest' ),
	];

	$schedules['monthly'] = [
		'interval' => MONTH_IN_SECONDS,
		'display'  => __( 'Once Monthly', 'revisions-digest' ),
	];

	return $schedules;
} );

/**
 * Schedule cron events on plugin activation.
 */
function activate_cron_events() : void {
	if ( ! wp_next_scheduled( 'revisions_digest_send_emails' ) ) {
		wp_schedule_event( time(), 'hourly', 'revisions_digest_send_emails' );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_cron_events' );

/**
 * Clear cron events on plugin deactivation.
 */
function deactivate_cron_events() : void {
	$timestamp = wp_next_scheduled( 'revisions_digest_send_emails' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'revisions_digest_send_emails' );
	}
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_cron_events' );

/**
 * Process email subscriptions and send digests when due.
 */
add_action( 'revisions_digest_send_emails', function() : void {
	$subscriptions = get_email_subscriptions();

	foreach ( $subscriptions as $id => $subscription ) {
		if ( should_send_digest( $subscription ) ) {
			send_digest_email( $id );
		}
	}
} );

/**
 * Determine if a digest should be sent for a subscription.
 *
 * @param array $subscription The subscription data.
 * @return bool Whether the digest should be sent.
 */
function should_send_digest( array $subscription ) : bool {
	$last_sent = $subscription['last_sent'] ?? 0;
	$frequency = $subscription['frequency'] ?? 'weekly';
	$now       = time();

	$intervals = [
		'daily'   => DAY_IN_SECONDS,
		'weekly'  => WEEK_IN_SECONDS,
		'monthly' => MONTH_IN_SECONDS,
	];

	$interval = $intervals[ $frequency ] ?? WEEK_IN_SECONDS;

	return ( $now - $last_sent ) >= $interval;
}
