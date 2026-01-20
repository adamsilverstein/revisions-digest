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

// Include the Digest helper class
require_once __DIR__ . '/includes/class-digest.php';

// Include the REST controller class
require_once __DIR__ . '/includes/class-rest-controller.php';

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

// Register REST API routes.
add_action( 'rest_api_init', function() {
	$controller = new REST_Controller();
	$controller->register_routes();
} );

// Enqueue dashboard assets.
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_dashboard_assets' );

/**
 * Enqueue dashboard widget assets.
 *
 * @param string $hook_suffix The current admin page.
 * @return void
 */
function enqueue_dashboard_assets( string $hook_suffix ) : void {
	if ( 'index.php' !== $hook_suffix ) {
		return;
	}

	$plugin_url = plugin_dir_url( __FILE__ );

	wp_enqueue_style(
		'revisions-digest-widget',
		$plugin_url . 'assets/css/widget.css',
		[],
		'0.1.0'
	);

	wp_enqueue_script(
		'revisions-digest-widget',
		$plugin_url . 'assets/js/widget.js',
		[ 'wp-api-fetch', 'wp-i18n' ],
		'0.1.0',
		true
	);

	wp_localize_script(
		'revisions-digest-widget',
		'revisionsDigestData',
		[
			'restUrl' => rest_url( 'revisions-digest/v1/digest' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'periods' => [
				Digest::PERIOD_DAY   => __( 'Today', 'revisions-digest' ),
				Digest::PERIOD_WEEK  => __( 'This Week', 'revisions-digest' ),
				Digest::PERIOD_MONTH => __( 'This Month', 'revisions-digest' ),
			],
		]
	);
}

/**
 * Dashboard widget callback.
 *
 * @param mixed $no_idea  The object passed to the callback.
 * @param array $meta_box The meta box arguments.
 * @return void
 */
function widget( $no_idea, array $meta_box ) : void {
	?>
	<div class="revisions-digest-widget">
		<div class="revisions-digest-period-selector">
			<button type="button" class="button revisions-digest-period-btn" data-period="<?php echo esc_attr( Digest::PERIOD_DAY ); ?>">
				<?php esc_html_e( 'Today', 'revisions-digest' ); ?>
			</button>
			<button type="button" class="button revisions-digest-period-btn active" data-period="<?php echo esc_attr( Digest::PERIOD_WEEK ); ?>">
				<?php esc_html_e( 'This Week', 'revisions-digest' ); ?>
			</button>
			<button type="button" class="button revisions-digest-period-btn" data-period="<?php echo esc_attr( Digest::PERIOD_MONTH ); ?>">
				<?php esc_html_e( 'This Month', 'revisions-digest' ); ?>
			</button>
		</div>

		<div class="revisions-digest-loading" style="display: none;">
			<span class="spinner is-active"></span>
			<span><?php esc_html_e( 'Loading...', 'revisions-digest' ); ?></span>
		</div>

		<div class="revisions-digest-error" style="display: none;"></div>

		<div class="revisions-digest-results">
			<?php render_widget_content( get_digest_changes() ); ?>
		</div>
	</div>
	<?php
}

/**
 * Render the widget content from changes array.
 *
 * @param array $changes Array of changes to render.
 * @return void
 */
function render_widget_content( array $changes ) : void {
	if ( empty( $changes ) ) {
		echo '<p class="revisions-digest-empty">';
		esc_html_e( 'There have been no content changes in this period.', 'revisions-digest' );
		echo '</p>';
	} else {
		foreach ( $changes as $change ) {
			echo '<div class="activity-block">';

			printf(
				'<h3><a href="%1$s">%2$s</a> <a href="%3$s" class="revisions-digest-edit-link">%4$s</a></h3>',
				esc_url( get_permalink( $change['post_id'] ) ),
				esc_html( get_the_title( $change['post_id'] ) ),
				esc_url( get_edit_post_link( $change['post_id'] ) ),
				esc_html__( 'Edit', 'revisions-digest' )
			);

			$authors = array_filter( array_map( function ( int $user_id ) {
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
			echo $change['rendered']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is pre-escaped by WP_Text_Diff_Renderer_Table.
			echo '</table>';

			echo '</div>';
		}
	}

	// Email subscription section.
	if ( current_user_can( 'edit_posts' ) ) {
		render_subscription_section();
	}
}

/**
 * Render the email subscription section in the widget.
 */
function render_subscription_section() : void {
	$current_user = wp_get_current_user();
	$subscriptions = array_filter(
		get_email_subscriptions(),
		static function( array $subscription ) use ( $current_user ) : bool {
			return (int) ( $subscription['user_id'] ?? 0 ) === $current_user->ID;
		}
	);
	$nonce = wp_create_nonce( 'revisions_digest_subscription' );
	?>
	<div class="activity-block revisions-digest-subscriptions">
		<h3><?php esc_html_e( 'Email Subscriptions', 'revisions-digest' ); ?></h3>

		<?php render_subscription_form( $current_user->user_email, $nonce ); ?>

		<?php render_subscription_list( $subscriptions, $nonce ); ?>
	</div>
	<?php
	render_subscription_scripts( $nonce );
}

/**
 * Render the subscription form.
 *
 * @param string $default_email The default email address.
 * @param string $nonce         The security nonce.
 */
function render_subscription_form( string $default_email, string $nonce ) : void {
	?>
	<form id="revisions-digest-add-subscription" class="revisions-digest-form">
		<p>
			<label for="revisions-digest-email"><?php esc_html_e( 'Email Address:', 'revisions-digest' ); ?></label>
			<input type="email" id="revisions-digest-email" name="email" value="<?php echo esc_attr( $default_email ); ?>" required />
		</p>
		<p>
			<label for="revisions-digest-frequency"><?php esc_html_e( 'Frequency:', 'revisions-digest' ); ?></label>
			<select id="revisions-digest-frequency" name="frequency">
				<option value="daily"><?php esc_html_e( 'Daily', 'revisions-digest' ); ?></option>
				<option value="weekly" selected><?php esc_html_e( 'Weekly', 'revisions-digest' ); ?></option>
				<option value="monthly"><?php esc_html_e( 'Monthly', 'revisions-digest' ); ?></option>
			</select>
		</p>
		<p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Subscribe', 'revisions-digest' ); ?></button>
		</p>
		<div class="revisions-digest-message" style="display:none;"></div>
	</form>
	<?php
}

/**
 * Render the list of existing subscriptions.
 *
 * @param array  $subscriptions The subscriptions array.
 * @param string $nonce         The security nonce.
 */
function render_subscription_list( array $subscriptions, string $nonce ) : void {
	$has_subscriptions = ! empty( $subscriptions );

	$frequency_labels = [
		'daily'   => __( 'Daily', 'revisions-digest' ),
		'weekly'  => __( 'Weekly', 'revisions-digest' ),
		'monthly' => __( 'Monthly', 'revisions-digest' ),
	];
	?>
	<?php if ( $has_subscriptions ) : ?>
		<h4><?php esc_html_e( 'Current Subscriptions', 'revisions-digest' ); ?></h4>
		<ul id="revisions-digest-subscription-list">
			<?php foreach ( $subscriptions as $id => $subscription ) : ?>
				<li data-id="<?php echo esc_attr( $id ); ?>">
					<span class="subscription-email"><?php echo esc_html( $subscription['email'] ); ?></span>
					<span class="subscription-frequency">(<?php echo esc_html( $frequency_labels[ $subscription['frequency'] ] ?? $subscription['frequency'] ); ?>)</span>
					<span class="subscription-actions">
						<a href="#" class="edit-subscription" data-id="<?php echo esc_attr( $id ); ?>" data-email="<?php echo esc_attr( $subscription['email'] ); ?>" data-frequency="<?php echo esc_attr( $subscription['frequency'] ); ?>"><?php esc_html_e( 'Edit', 'revisions-digest' ); ?></a>
						|
						<a href="#" class="delete-subscription" data-id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Delete', 'revisions-digest' ); ?></a>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<ul id="revisions-digest-subscription-list" class="hidden"></ul>
	<?php endif; ?>

	<!-- Edit modal -->
	<div id="revisions-digest-edit-modal" style="display:none;">
		<form id="revisions-digest-edit-form">
			<input type="hidden" id="edit-subscription-id" name="id" value="" />
			<p>
				<label for="edit-subscription-email"><?php esc_html_e( 'Email Address:', 'revisions-digest' ); ?></label>
				<input type="email" id="edit-subscription-email" name="email" required />
			</p>
			<p>
				<label for="edit-subscription-frequency"><?php esc_html_e( 'Frequency:', 'revisions-digest' ); ?></label>
				<select id="edit-subscription-frequency" name="frequency">
					<option value="daily"><?php esc_html_e( 'Daily', 'revisions-digest' ); ?></option>
					<option value="weekly"><?php esc_html_e( 'Weekly', 'revisions-digest' ); ?></option>
					<option value="monthly"><?php esc_html_e( 'Monthly', 'revisions-digest' ); ?></option>
				</select>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Update', 'revisions-digest' ); ?></button>
				<button type="button" class="button cancel-edit"><?php esc_html_e( 'Cancel', 'revisions-digest' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Get digest changes (backward compatibility function)
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
	$digest = new Digest();
	return $digest->get_changes();
}

/**
 * Get digest changes for a specific period
 *
 * @param string $period Time period (day, week, month).
 * @param string $group_by How to group results.
 * @return array Digest changes.
 */
function get_digest_changes_for_period( string $period = Digest::PERIOD_WEEK, string $group_by = Digest::GROUP_BY_POST ) : array {
	$digest = new Digest( $period, $group_by );
	return $digest->get_changes();
}

/**
 * Get digest changes with intelligent descriptions
 *
 * @param string $period Time period (day, week, month).
 * @param string $group_by How to group results.
 * @return array Digest changes with descriptions.
 */
function get_digest_with_descriptions( string $period = Digest::PERIOD_WEEK, string $group_by = Digest::GROUP_BY_POST ) : array {
	$digest = new Digest( $period, $group_by );
	return $digest->get_grouped_changes();
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

/**
 * Get the timeframe string for a frequency.
 *
 * @param string $frequency The frequency (daily, weekly, monthly).
 * @return string The timeframe string for strtotime.
 */
function get_timeframe_for_frequency( string $frequency ) : string {
	$timeframes = [
		'daily'   => '-1 day',
		'weekly'  => '-1 week',
		'monthly' => '-1 month',
	];

	return $timeframes[ $frequency ] ?? '-1 week';
}

/**
 * Get digest changes for a specific timeframe.
 *
 * @param string $timeframe The timeframe string for strtotime.
 * @return array Array of changes.
 */
function get_digest_changes_for_timeframe( string $timeframe ) : array {
	$time     = strtotime( $timeframe );
	$modified = get_updated_posts( $time );
	$changes  = [];

	foreach ( $modified as $modified_post_id ) {
		$revisions = get_post_revisions( $modified_post_id, $time );
		if ( empty( $revisions ) ) {
			continue;
		}

		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$authors = array_unique( array_map( 'intval', wp_list_pluck( $revisions, 'post_author' ) ) );
		$bounds  = get_bound_revisions( $revisions );
		$diff    = get_diff( $bounds['latest'], $bounds['earliest'] );

		$renderer = new WP_Text_Diff_Renderer_Table( [
			'show_split_view'        => false,
			'leading_context_lines'  => 1,
			'trailing_context_lines' => 1,
		] );
		$rendered = render_diff( $diff, $renderer );

		$changes[] = [
			'post_id'  => $modified_post_id,
			'latest'   => $bounds['latest'],
			'earliest' => $bounds['earliest'],
			'diff'     => $diff,
			'rendered' => $rendered,
			'authors'  => $authors,
		];
	}

	return $changes;
}

/**
 * Generate email subject line.
 *
 * @param int $changes_count The number of changes.
 * @return string The email subject.
 */
function get_email_subject( int $changes_count ) : string {
	$site_name = get_bloginfo( 'name' );

	if ( 0 === $changes_count ) {
		return sprintf(
			/* translators: %s: site name */
			__( '[%s] Revisions Digest - No Recent Changes', 'revisions-digest' ),
			$site_name
		);
	}

	return sprintf(
		/* translators: 1: site name, 2: number of changes */
		_n(
			'[%1$s] Revisions Digest - %2$d Page Changed',
			'[%1$s] Revisions Digest - %2$d Pages Changed',
			$changes_count,
			'revisions-digest'
		),
		$site_name,
		$changes_count
	);
}

/**
 * Generate HTML email content.
 *
 * @param array $changes The array of changes.
 * @return string The HTML email content.
 */
function get_email_content( array $changes ) : string {
	$site_name = get_bloginfo( 'name' );
	$site_url  = home_url();

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
			line-height: 1.6;
			color: #333;
			max-width: 800px;
			margin: 0 auto;
			padding: 20px;
		}
		h1 {
			color: #0073aa;
			border-bottom: 2px solid #0073aa;
			padding-bottom: 10px;
		}
		h2 {
			color: #23282d;
			margin-top: 30px;
		}
		h2 a {
			color: #0073aa;
			text-decoration: none;
		}
		h2 a:hover {
			text-decoration: underline;
		}
		.change-block {
			background: #f9f9f9;
			border: 1px solid #e5e5e5;
			border-radius: 4px;
			padding: 15px;
			margin-bottom: 20px;
		}
		.authors {
			color: #666;
			font-size: 14px;
			margin-bottom: 15px;
		}
		table.diff {
			width: 100%;
			border-collapse: collapse;
			font-size: 13px;
			margin-top: 10px;
		}
		table.diff td {
			padding: 5px 10px;
			border: 1px solid #ddd;
			vertical-align: top;
		}
		table.diff .diff-deletedline {
			background-color: #ffecec;
		}
		table.diff .diff-addedline {
			background-color: #eaffea;
		}
		table.diff del {
			background-color: #faa;
			text-decoration: none;
		}
		table.diff ins {
			background-color: #afa;
			text-decoration: none;
		}
		.footer {
			margin-top: 40px;
			padding-top: 20px;
			border-top: 1px solid #ddd;
			font-size: 12px;
			color: #666;
		}
		.preamble {
			background: #fff8e5;
			border-left: 4px solid #ffb900;
			padding: 15px;
			margin-bottom: 20px;
		}
	</style>
</head>
<body>
	<h1><?php echo esc_html( sprintf( __( 'Revisions Digest for %s', 'revisions-digest' ), $site_name ) ); ?></h1>

	<div class="preamble">
		<p><?php esc_html_e( 'This is your periodic digest of content changes.', 'revisions-digest' ); ?></p>
	</div>

	<?php if ( empty( $changes ) ) : ?>
		<p><?php esc_html_e( 'There have been no content changes during this period.', 'revisions-digest' ); ?></p>
	<?php else : ?>
		<?php foreach ( $changes as $change ) : ?>
			<div class="change-block">
				<h2>
					<a href="<?php echo esc_url( get_permalink( $change['post_id'] ) ); ?>">
						<?php echo esc_html( get_the_title( $change['post_id'] ) ); ?>
					</a>
				</h2>

				<?php
				$authors = array_filter( array_map( function( int $user_id ) {
					$user = get_userdata( $user_id );
					return $user ? $user->display_name : false;
				}, $change['authors'] ) );
				?>

				<p class="authors">
					<?php
					echo esc_html( wp_sprintf(
						/* translators: %l: comma-separated list of author names */
						__( 'Changed by %l', 'revisions-digest' ),
						$authors
					) );
					?>
				</p>

				<table class="diff">
					<?php echo $change['rendered']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</table>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>

	<div class="footer">
		<p>
			<?php
			printf(
				/* translators: %s: site URL */
				esc_html__( 'This email was sent from %s', 'revisions-digest' ),
				esc_html( $site_url )
			);
			?>
		</p>
		<p><?php esc_html_e( 'To manage your subscription, visit the WordPress dashboard.', 'revisions-digest' ); ?></p>
	</div>
</body>
</html>
	<?php
	return ob_get_clean();
}

/**
 * Send a digest email for a subscription.
 *
 * @param string $subscription_id The subscription ID.
 * @return bool Whether the email was sent successfully.
 */
function send_digest_email( string $subscription_id ) : bool {
	$subscription = get_subscription( $subscription_id );
	if ( ! $subscription ) {
		return false;
	}

	$timeframe = get_timeframe_for_frequency( $subscription['frequency'] );
	$changes   = get_digest_changes_for_timeframe( $timeframe );
	$subject   = get_email_subject( count( $changes ) );
	$content   = get_email_content( $changes );

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
	];

	$sent = wp_mail( $subscription['email'], $subject, $content, $headers );

	if ( $sent ) {
		update_email_subscription( $subscription_id, [ 'last_sent' => time() ] );
	}

	return $sent;
}

/**
 * AJAX handler for adding a subscription.
 */
add_action( 'wp_ajax_revisions_digest_add_subscription', function() : void {
	check_ajax_referer( 'revisions_digest_subscription', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'revisions-digest' ) ] );
	}

	$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$frequency = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : 'weekly';

	$result = add_email_subscription( [
		'email'     => $email,
		'frequency' => $frequency,
	] );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message' => __( 'Subscription added successfully.', 'revisions-digest' ),
		'id'      => $result,
		'email'   => $email,
		'frequency' => $frequency,
	] );
} );

/**
 * Verify subscription ownership for AJAX requests.
 *
 * @param string $subscription_id The subscription ID to verify.
 * @return array|null The subscription data if verified, null otherwise (with error sent via AJAX).
 */
function verify_subscription_ownership( string $subscription_id ) : ?array {
	$subscription = get_subscription( $subscription_id );
	if ( ! $subscription ) {
		wp_send_json_error( [ 'message' => __( 'Subscription not found.', 'revisions-digest' ) ] );
	}
	if ( (int) ( $subscription['user_id'] ?? 0 ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'revisions-digest' ) ] );
	}
	return $subscription;
}

/**
 * AJAX handler for updating a subscription.
 */
add_action( 'wp_ajax_revisions_digest_update_subscription', function() : void {
	check_ajax_referer( 'revisions_digest_subscription', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'revisions-digest' ) ] );
	}

	$id        = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$frequency = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : '';

	// Verify subscription ownership.
	verify_subscription_ownership( $id );

	$data = [];
	if ( $email ) {
		$data['email'] = $email;
	}
	if ( $frequency ) {
		$data['frequency'] = $frequency;
	}

	$result = update_email_subscription( $id, $data );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message'   => __( 'Subscription updated successfully.', 'revisions-digest' ),
		'id'        => $id,
		'email'     => $email,
		'frequency' => $frequency,
	] );
} );

/**
 * AJAX handler for deleting a subscription.
 */
add_action( 'wp_ajax_revisions_digest_delete_subscription', function() : void {
	check_ajax_referer( 'revisions_digest_subscription', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'revisions-digest' ) ] );
	}

	$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

	// Verify subscription ownership.
	verify_subscription_ownership( $id );

	$result = delete_email_subscription( $id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( [
		'message' => __( 'Subscription deleted successfully.', 'revisions-digest' ),
		'id'      => $id,
	] );
} );

/**
 * Render the inline JavaScript for subscription management.
 *
 * @param string $nonce The security nonce.
 */
function render_subscription_scripts( string $nonce ) : void {
	$frequency_labels = [
		'daily'   => __( 'Daily', 'revisions-digest' ),
		'weekly'  => __( 'Weekly', 'revisions-digest' ),
		'monthly' => __( 'Monthly', 'revisions-digest' ),
	];
	?>
	<style>
		.revisions-digest-subscriptions {
			margin-top: 20px;
			padding-top: 15px;
			border-top: 1px solid #eee;
		}
		.revisions-digest-form label {
			display: inline-block;
			width: 100px;
		}
		.revisions-digest-form input[type="email"],
		.revisions-digest-form select {
			width: 200px;
		}
		.revisions-digest-message {
			padding: 8px 12px;
			margin: 10px 0;
			border-radius: 3px;
		}
		.revisions-digest-message.success {
			background: #d4edda;
			border: 1px solid #c3e6cb;
			color: #155724;
		}
		.revisions-digest-message.error {
			background: #f8d7da;
			border: 1px solid #f5c6cb;
			color: #721c24;
		}
		#revisions-digest-subscription-list {
			margin: 10px 0;
			padding-left: 20px;
		}
		#revisions-digest-subscription-list li {
			margin-bottom: 5px;
		}
		.subscription-actions {
			margin-left: 10px;
		}
		.subscription-actions a {
			text-decoration: none;
		}
		#revisions-digest-edit-modal {
			background: #fff;
			border: 1px solid #ccc;
			padding: 15px;
			margin: 15px 0;
			border-radius: 4px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
		}
		#revisions-digest-subscription-list.hidden {
			display: none;
		}
	</style>
	<script>
	(function() {
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxurl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		var frequencyLabels = <?php echo wp_json_encode( $frequency_labels ); ?>;

		function showMessage(container, message, type) {
			var msgEl = container.querySelector('.revisions-digest-message');
			if (!msgEl) {
				msgEl = document.createElement('div');
				msgEl.className = 'revisions-digest-message';
				container.appendChild(msgEl);
			}
			msgEl.textContent = message;
			msgEl.className = 'revisions-digest-message ' + type;
			msgEl.style.display = 'block';
			setTimeout(function() {
				msgEl.style.display = 'none';
			}, 5000);
		}

		// Add subscription form
		var addForm = document.getElementById('revisions-digest-add-subscription');
		if (addForm) {
			addForm.addEventListener('submit', function(e) {
				e.preventDefault();
				var email = document.getElementById('revisions-digest-email').value;
				var frequency = document.getElementById('revisions-digest-frequency').value;

				var formData = new FormData();
				formData.append('action', 'revisions_digest_add_subscription');
				formData.append('nonce', nonce);
				formData.append('email', email);
				formData.append('frequency', frequency);

				fetch(ajaxurl, {
					method: 'POST',
					body: formData
				})
				.then(function(response) { return response.json(); })
				.then(function(data) {
					if (data.success) {
						showMessage(addForm, data.data.message, 'success');
						// Add to list
						var list = document.getElementById('revisions-digest-subscription-list');
						if (!list) {
							var h4 = document.createElement('h4');
							h4.textContent = <?php echo wp_json_encode( __( 'Current Subscriptions', 'revisions-digest' ) ); ?>;
							addForm.parentNode.appendChild(h4);
							list = document.createElement('ul');
							list.id = 'revisions-digest-subscription-list';
							addForm.parentNode.appendChild(list);
						}
						// Remove hidden class if present
						list.classList.remove('hidden');
						var li = document.createElement('li');
						li.setAttribute('data-id', data.data.id);
						li.innerHTML = '<span class="subscription-email">' + escapeHtml(data.data.email) + '</span> ' +
							'<span class="subscription-frequency">(' + escapeHtml(frequencyLabels[data.data.frequency] || data.data.frequency) + ')</span> ' +
							'<span class="subscription-actions">' +
							'<a href="#" class="edit-subscription" data-id="' + escapeHtml(data.data.id) + '" data-email="' + escapeHtml(data.data.email) + '" data-frequency="' + escapeHtml(data.data.frequency) + '"><?php echo esc_js( __( 'Edit', 'revisions-digest' ) ); ?></a> | ' +
							'<a href="#" class="delete-subscription" data-id="' + escapeHtml(data.data.id) + '"><?php echo esc_js( __( 'Delete', 'revisions-digest' ) ); ?></a>' +
							'</span>';
						list.appendChild(li);
						bindDeleteHandlers();
						bindEditHandlers();
					} else {
						showMessage(addForm, data.data.message, 'error');
					}
				})
				.catch(function() {
					showMessage(addForm, <?php echo wp_json_encode( __( 'An error occurred.', 'revisions-digest' ) ); ?>, 'error');
				});
			});
		}

		function escapeHtml(text) {
			var div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}

		// Delete handlers
		function bindDeleteHandlers() {
			var deleteLinks = document.querySelectorAll('.delete-subscription');
			deleteLinks.forEach(function(link) {
				link.onclick = function(e) {
					e.preventDefault();
					if (!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to delete this subscription?', 'revisions-digest' ) ); ?>)) {
						return;
					}
					var id = this.getAttribute('data-id');
					var li = this.closest('li');

					var formData = new FormData();
					formData.append('action', 'revisions_digest_delete_subscription');
					formData.append('nonce', nonce);
					formData.append('id', id);

					fetch(ajaxurl, {
						method: 'POST',
						body: formData
					})
					.then(function(response) { return response.json(); })
					.then(function(data) {
						if (data.success) {
							li.remove();
						} else {
							alert(data.data.message);
						}
					});
				};
			});
		}

		// Edit handlers
		function bindEditHandlers() {
			var editLinks = document.querySelectorAll('.edit-subscription');
			editLinks.forEach(function(link) {
				link.onclick = function(e) {
					e.preventDefault();
					var id = this.getAttribute('data-id');
					var email = this.getAttribute('data-email');
					var frequency = this.getAttribute('data-frequency');

					document.getElementById('edit-subscription-id').value = id;
					document.getElementById('edit-subscription-email').value = email;
					document.getElementById('edit-subscription-frequency').value = frequency;

					var modal = document.getElementById('revisions-digest-edit-modal');
					modal.style.display = 'block';
				};
			});
		}

		// Edit form submission
		var editForm = document.getElementById('revisions-digest-edit-form');
		if (editForm) {
			editForm.addEventListener('submit', function(e) {
				e.preventDefault();
				var id = document.getElementById('edit-subscription-id').value;
				var email = document.getElementById('edit-subscription-email').value;
				var frequency = document.getElementById('edit-subscription-frequency').value;

				var formData = new FormData();
				formData.append('action', 'revisions_digest_update_subscription');
				formData.append('nonce', nonce);
				formData.append('id', id);
				formData.append('email', email);
				formData.append('frequency', frequency);

				fetch(ajaxurl, {
					method: 'POST',
					body: formData
				})
				.then(function(response) { return response.json(); })
				.then(function(data) {
					if (data.success) {
						// Update list item
						var li = document.querySelector('li[data-id="' + id + '"]');
						if (li) {
							li.querySelector('.subscription-email').textContent = email;
							li.querySelector('.subscription-frequency').textContent = '(' + (frequencyLabels[frequency] || frequency) + ')';
							li.querySelector('.edit-subscription').setAttribute('data-email', email);
							li.querySelector('.edit-subscription').setAttribute('data-frequency', frequency);
						}
						document.getElementById('revisions-digest-edit-modal').style.display = 'none';
					} else {
						alert(data.data.message);
					}
				});
			});
		}

		// Cancel edit
		var cancelBtn = document.querySelector('.cancel-edit');
		if (cancelBtn) {
			cancelBtn.addEventListener('click', function() {
				document.getElementById('revisions-digest-edit-modal').style.display = 'none';
			});
		}

		// Initial binding
		bindDeleteHandlers();
		bindEditHandlers();
	})();
	</script>
	<?php
}

/**
 * Register RSS feed settings in Settings → Reading.
 */
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );

/**
 * Register the RSS feed setting.
 */
function register_settings() : void {
	register_setting( 'reading', 'revisions_digest_rss_enabled' );

	add_settings_field(
		'revisions_digest_rss_enabled',
		__( 'Revisions Digest RSS Feed', 'revisions-digest' ),
		__NAMESPACE__ . '\render_rss_setting',
		'reading',
		'default'
	);
}

/**
 * Render the RSS feed setting checkbox.
 */
function render_rss_setting() : void {
	$enabled = get_option( 'revisions_digest_rss_enabled', false );
	?>
	<label for="revisions_digest_rss_enabled">
		<input type="hidden" name="revisions_digest_rss_enabled" value="0" />
		<input
			type="checkbox"
			id="revisions_digest_rss_enabled"
			name="revisions_digest_rss_enabled"
			value="1"
			<?php checked( $enabled ); ?>
		/>
		<?php esc_html_e( 'Enable RSS feed for recent content changes', 'revisions-digest' ); ?>
	</label>
	<?php if ( $enabled ) : ?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Feed URL */
				esc_html__( 'Feed URL: %s', 'revisions-digest' ),
				'<code>' . esc_url( get_feed_link( 'revisions-digest' ) ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Register the RSS feed endpoint.
 */
add_action( 'init', __NAMESPACE__ . '\register_feed' );

/**
 * Register the revisions-digest feed.
 */
function register_feed() : void {
	if ( get_option( 'revisions_digest_rss_enabled', false ) ) {
		add_feed( 'revisions-digest', __NAMESPACE__ . '\render_feed' );
	}
}

/**
 * Render the RSS feed.
 */
function render_feed() : void {
	if ( ! is_user_logged_in() ) {
		status_header( 403 );
		wp_die(
			esc_html__( 'You must be logged in to view the Revisions Digest feed.', 'revisions-digest' ),
			esc_html__( 'Access Denied', 'revisions-digest' ),
			array( 'response' => 403 )
		);
	}

	header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ) );

	$changes = get_digest_changes();

	echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
	?>
<rss version="2.0"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:atom="http://www.w3.org/2005/Atom"
>
<channel>
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> - <?php esc_html_e( 'Revisions Digest', 'revisions-digest' ); ?></title>
	<link><?php echo esc_url( home_url( '/' ) ); ?></link>
	<description><?php esc_html_e( 'Recent content changes', 'revisions-digest' ); ?></description>
	<lastBuildDate><?php echo esc_html( gmdate( 'r' ) ); ?></lastBuildDate>
	<language><?php echo esc_html( get_bloginfo( 'language' ) ); ?></language>
	<atom:link href="<?php echo esc_url( get_feed_link( 'revisions-digest' ) ); ?>" rel="self" type="application/rss+xml" />
	<?php if ( empty( $changes ) ) : ?>
	<!-- <?php esc_html_e( 'No content changes in the last week', 'revisions-digest' ); ?> -->
	<?php else : ?>
	<?php foreach ( $changes as $change ) : ?>
	<item>
		<title><?php echo esc_html( get_the_title( $change['post_id'] ) ); ?></title>
		<?php $link = get_edit_post_link( $change['post_id'], 'raw' ) ?: get_permalink( $change['post_id'] ); ?>
		<link><?php echo esc_url( $link ); ?></link>
		<guid isPermaLink="false"><?php echo esc_html( $change['post_id'] . '-' . $change['latest']->ID ); ?></guid>
		<pubDate><?php echo esc_html( mysql2date( 'r', $change['latest']->post_modified_gmt, false ) ); ?></pubDate>
		<?php
		$authors = array_filter( array_map( function( int $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return false;
			}
			return $user->display_name;
		}, $change['authors'] ) );
		foreach ( $authors as $author ) :
		?>
		<dc:creator><?php echo esc_html( $author ); ?></dc:creator>
		<?php endforeach; ?>
		<?php $rendered = str_replace( ']]>', ']]]]><![CDATA[>', $change['rendered'] ); ?>
		<description><![CDATA[
			<table class="diff">
				<?php echo $rendered; ?>
			</table>
		]]></description>
	</item>
	<?php endforeach; ?>
	<?php endif; ?>
</channel>
</rss>
	<?php
}

/**
 * Flush rewrite rules when the RSS feed setting changes.
 */
add_action( 'update_option_revisions_digest_rss_enabled', __NAMESPACE__ . '\flush_rewrite_rules_on_setting_change' );
add_action( 'add_option_revisions_digest_rss_enabled', __NAMESPACE__ . '\flush_rewrite_rules_on_setting_change' );

/**
 * Flush rewrite rules.
 */
function flush_rewrite_rules_on_setting_change() : void {
	flush_rewrite_rules();
}
