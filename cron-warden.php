<?php
/**
 * Plugin Name: Cron Warden
 * Plugin URI: https://github.com/HubYura/cron-warden
 * Description: Automatically republish missed scheduled posts with Action Scheduler support. Runs every 2 minutes to find and publish posts that missed their schedule.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: HubYura
 * Author URI: https://github.com/HubYura
 * License: GPL v2
 * Text Domain: cron-warden
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRON_WARDEN_VERSION', '3.0.0' );
define( 'CRON_WARDEN_SCHEDULES_NAME', 'cron_warden_2_min' );
define( 'CRON_WARDEN_SCHEDULES_TIME', 120 );
define( 'CRON_WARDEN_ACTION_HOOK', 'cron_warden_check_posts' );
define( 'CRON_WARDEN_GROUP', 'cron_warden' );

// PHP 8.0+ check
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action( 'admin_notices', function (): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Cron Warden requires PHP 8.0 or higher.', 'cron-warden' );
		echo '</p></div>';
	} );

	return;
}

class CronWarden {

	/**
	 * Check if Action Scheduler is available and active
	 *
	 * @return bool
	 */
	public static function isActionSchedulerAvailable(): bool {
		return function_exists( 'as_schedule_recurring_action' ) &&
		       function_exists( 'as_has_scheduled_action' ) &&
		       function_exists( 'as_unschedule_action' );
	}

	/**
	 * Schedule a recurring action using Action Scheduler or WP-Cron
	 *
	 * @param int    $timestamp When to start the recurring action.
	 * @param int    $interval  Interval in seconds.
	 * @param string $hook      The hook to trigger.
	 * @param array  $args      Arguments to pass when the hook triggers.
	 * @param string $group     The group to assign this job to.
	 * @param bool   $unique    Whether the action should be unique.
	 *
	 * @return int|bool The action ID or true for WP-Cron success, false on failure.
	 */
	public static function scheduleRecurringAction(
		int $timestamp,
		int $interval,
		string $hook,
		array $args = [],
		string $group = CRON_WARDEN_GROUP,
		bool $unique = true,
	): int|bool {
		if ( self::isActionSchedulerAvailable() ) {
			// Use Action Scheduler
			if ( $unique && as_has_scheduled_action( $hook, $args, $group ) ) {
				return false; // Already scheduled
			}

			$action_id = as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $group, $unique );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'Cron Warden: Scheduled Action Scheduler job %s (ID: %d)',
					$hook,
					$action_id,
				) );
			}

			return $action_id;
		} else {
			// Fallback to WP-Cron
			if ( ! wp_next_scheduled( $hook, $args ) ) {
				$result = wp_schedule_event( $timestamp, CRON_WARDEN_SCHEDULES_NAME, $hook, $args );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'Cron Warden: Scheduled WP-Cron job %s (Result: %s)',
						$hook,
						$result ? 'success' : 'failed',
					) );
				}

				return $result;
			}
		}

		return false;
	}

	/**
	 * Cancel a scheduled action
	 *
	 * @param string $hook  The hook to cancel.
	 * @param array  $args  Arguments to match.
	 * @param string $group The group to search in.
	 *
	 * @return int|bool Number of cancelled actions or false if none found.
	 */
	public static function cancelScheduledAction(
		string $hook,
		array $args = [],
		string $group = CRON_WARDEN_GROUP,
	): int|bool {
		if ( self::isActionSchedulerAvailable() ) {
			$cancelled = as_unschedule_action( $hook, $args, $group );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'Cron Warden: Cancelled %d Action Scheduler actions for hook %s',
					$cancelled,
					$hook,
				) );
			}

			return $cancelled;
		} else {
			$timestamp = wp_next_scheduled( $hook, $args );
			if ( $timestamp ) {
				$result = wp_unschedule_event( $timestamp, $hook, $args );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'Cron Warden: Cancelled WP-Cron job %s (Result: %s)',
						$hook,
						$result ? 'success' : 'failed',
					) );
				}

				return $result;
			}
		}

		return false;
	}

	/**
	 * Get next scheduled time for the action
	 *
	 * @param string $hook  The hook to check.
	 * @param array  $args  Arguments to match.
	 * @param string $group The group to search in.
	 *
	 * @return int|false Timestamp of next run or false if not found.
	 */
	public static function getNextScheduledTime(
		string $hook,
		array $args = [],
		string $group = CRON_WARDEN_GROUP,
	): int|false {
		if ( self::isActionSchedulerAvailable() ) {
			$actions = as_get_scheduled_actions( [
				'hook'     => $hook,
				'args'     => $args,
				'group'    => $group,
				'status'   => 'pending',
				'per_page' => 1,
			] );

			if ( ! empty( $actions ) ) {
				$action = reset( $actions );

				return $action->get_schedule()->get_date()->getTimestamp();
			}
		} else {
			return wp_next_scheduled( $hook, $args );
		}

		return false;
	}

	/**
	 * Get count of scheduled actions
	 *
	 * @param string $hook   The hook to count.
	 * @param string $status Status to count ('pending', 'complete', 'failed', etc.).
	 * @param string $group  The group to search in.
	 *
	 * @return int Count of actions.
	 */
	public static function getActionCount(
		string $hook = '',
		string $status = 'pending',
		string $group = CRON_WARDEN_GROUP,
	): int {
		if ( self::isActionSchedulerAvailable() ) {
			$query_args = [
				'status'   => $status,
				'group'    => $group,
				'per_page' => - 1,
			];

			if ( ! empty( $hook ) ) {
				$query_args[ 'hook' ] = $hook;
			}

			$actions = as_get_scheduled_actions( $query_args );

			return count( $actions );
		}

		return 0; // WP-Cron doesn't have easy counting
	}

	/**
	 * Clean up old completed actions
	 *
	 * @param string $hook             Hook to clean up.
	 * @param int    $older_than_hours Remove actions older than X hours.
	 * @param string $status           Status to clean ('complete', 'failed', etc.).
	 *
	 * @return int Number of cleaned actions.
	 */
	public static function cleanupOldActions(
		string $hook = '',
		int $older_than_hours = 24,
		string $status = 'complete',
	): int {
		if ( ! self::isActionSchedulerAvailable() ) {
			return 0;
		}

		$cutoff_time   = time() - ( $older_than_hours * HOUR_IN_SECONDS );
		$cleaned_count = 0;

		$query_args = [
			'status'       => $status,
			'group'        => CRON_WARDEN_GROUP,
			'date'         => gmdate( 'Y-m-d H:i:s', $cutoff_time ),
			'date_compare' => '<=',
			'per_page'     => 100,
		];

		if ( ! empty( $hook ) ) {
			$query_args[ 'hook' ] = $hook;
		}

		$actions = as_get_scheduled_actions( $query_args );

		foreach ( $actions as $action_id => $action ) {
			if ( function_exists( 'ActionScheduler' ) ) {
				ActionScheduler::store()->delete_action( $action_id );
				$cleaned_count ++;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $cleaned_count > 0 ) {
			error_log( sprintf(
				'Cron Warden: Cleaned up %d old %s actions',
				$cleaned_count,
				$status,
			) );
		}

		return $cleaned_count;
	}
}

/**
 * Adds custom intervals to WordPress cron schedules.
 *
 * @param array $schedules An array of existing cron intervals.
 *
 * @return array The modified array of cron intervals including the new schedule.
 */
function cron_warden_add_intervals( array $schedules ): array {
	$schedules[ CRON_WARDEN_SCHEDULES_NAME ] = [
		'interval' => CRON_WARDEN_SCHEDULES_TIME,
		'display'  => __( 'Cron Warden - Every 2 minutes', 'cron-warden' ),
	];

	return $schedules;
}

/**
 * Check and publish missed scheduled posts.
 *
 * Identifies posts that were scheduled to publish in the past but remain unpublished
 * and attempts to publish them programmatically.
 *
 * @return void
 */
function cron_warden_check_missed_posts(): void {
	global $wpdb;

	$start_time = microtime( true );

	// Get missed posts with prepared statement for security
	$query = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} 
         WHERE post_status = %s 
         AND post_date <= %s 
         LIMIT %d",
		'future',
		current_time( 'mysql' ),
		10,
	);

	$missed_post_ids = $wpdb->get_col( $query );

	if ( empty( $missed_post_ids ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Cron Warden: No missed posts found' );
		}

		return;
	}

	$published_count = 0;
	$failed_count    = 0;

	// Publish each missed post
	foreach ( $missed_post_ids as $post_id ) {
		$post_id = (int) $post_id;

		// Verify post exists and is still scheduled
		$post = get_post( $post_id );
		if ( $post && $post->post_status === 'future' ) {
			$result = wp_publish_post( $post_id );

			if ( $result ) {
				$published_count ++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'Cron Warden: Published post ID %d (%s)',
						$post_id,
						$post->post_title,
					) );
				}
			} else {
				$failed_count ++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf(
						'Cron Warden: Failed to publish post ID %d',
						$post_id,
					) );
				}
			}
		}
	}

	$execution_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );

	// Log summary
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf(
			'Cron Warden: Processed %d missed posts - %d published, %d failed (%.2fms)',
			count( $missed_post_ids ),
			$published_count,
			$failed_count,
			$execution_time,
		) );
	}

	// Store statistics for admin page
	update_option( 'cron_warden_last_run', [
		'timestamp'      => time(),
		'processed'      => count( $missed_post_ids ),
		'published'      => $published_count,
		'failed'         => $failed_count,
		'execution_time' => $execution_time,
		'scheduler_type' => CronWarden::isActionSchedulerAvailable() ? 'Action Scheduler' : 'WP-Cron',
	] );
}

/**
 * Activate plugin - schedule recurring job if not already scheduled
 *
 * @return void
 */
function cron_warden_activate(): void {
	$scheduled = CronWarden::scheduleRecurringAction(
		time(),
		CRON_WARDEN_SCHEDULES_TIME,
		CRON_WARDEN_ACTION_HOOK,
	);

	if ( $scheduled ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$scheduler_type = CronWarden::isActionSchedulerAvailable() ? 'Action Scheduler' : 'WP-Cron';
			error_log( sprintf(
				'Cron Warden: Plugin activated and scheduled using %s',
				$scheduler_type,
			) );
		}
	} else {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Cron Warden: Plugin activation failed or already scheduled' );
		}
	}

	// Initialize options
	add_option( 'cron_warden_last_run', [] );
}

/**
 * Deactivates the Cron Warden plugin by unscheduling the action.
 *
 * @return void
 */
function cron_warden_deactivate(): void {
	$cancelled = CronWarden::cancelScheduledAction( CRON_WARDEN_ACTION_HOOK );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$scheduler_type = CronWarden::isActionSchedulerAvailable() ? 'Action Scheduler' : 'WP-Cron';
		error_log( sprintf(
			'Cron Warden: Plugin deactivated and unscheduled from %s (Result: %s)',
			$scheduler_type,
			$cancelled ? 'success' : 'failed',
		) );
	}

	// Clean up old actions if using Action Scheduler
	if ( CronWarden::isActionSchedulerAvailable() ) {
		$cleaned = CronWarden::cleanupOldActions( CRON_WARDEN_ACTION_HOOK, 1 ); // Clean actions older than 1 hour
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $cleaned > 0 ) {
			error_log( sprintf( 'Cron Warden: Cleaned up %d old actions', $cleaned ) );
		}
	}
}

/**
 * Add settings link to plugins page
 */
function cron_warden_add_settings_link( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url( 'tools.php?page=cron-warden-status' ),
		__( 'Status', 'cron-warden' ),
	);

	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Adds an admin menu item for Cron Warden under the Tools section in WordPress admin.
 *
 * @return void
 */
function cron_warden_add_admin_menu(): void {
	add_management_page(
		__( 'Cron Warden Status', 'cron-warden' ),
		__( 'Cron Warden', 'cron-warden' ),
		'manage_options',
		'cron-warden-status',
		'cron_warden_status_page',
	);
}

/**
 * Renders the enhanced Cron Warden status page with Action Scheduler support.
 *
 * @return void
 */
function cron_warden_status_page(): void {
	global $wpdb;

	// Get current stats
	$next_run     = CronWarden::getNextScheduledTime( CRON_WARDEN_ACTION_HOOK );
	$missed_count = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} 
         WHERE post_status = 'future' 
         AND post_date <= NOW()",
	);

	$scheduler_type = CronWarden::isActionSchedulerAvailable() ? 'Action Scheduler' : 'WP-Cron';
	$last_run_data  = get_option( 'cron_warden_last_run', [] );

	// Handle manual run
	if ( isset( $_POST[ 'manual_run' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'cron_warden_manual' ) ) {
		cron_warden_check_missed_posts();
		echo '<div class="notice notice-success"><p>' . __( 'Manual check completed!', 'cron-warden' ) . '</p></div>';

		// Refresh stats
		$missed_count  = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_status = 'future' 
             AND post_date <= NOW()",
		);
		$last_run_data = get_option( 'cron_warden_last_run', [] );
	}

	// Handle cleanup
	if ( isset( $_POST[ 'cleanup_actions' ] ) && wp_verify_nonce( $_POST[ '_wpnonce' ], 'cron_warden_cleanup' ) ) {
		$cleaned = CronWarden::cleanupOldActions( CRON_WARDEN_ACTION_HOOK, 24 );
		echo '<div class="notice notice-success"><p>' .
		     sprintf( __( 'Cleaned up %d old actions!', 'cron-warden' ), $cleaned ) .
		     '</p></div>';
	}
	?>

    <div class="wrap">
        <h1><?php
			echo esc_html( get_admin_page_title() ); ?></h1>

        <div class="card">
            <h2><?php
				_e( 'Status', 'cron-warden' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php
						_e( 'Scheduler Type:', 'cron-warden' ); ?></th>
                    <td>
                        <strong style="color: <?php
						echo CronWarden::isActionSchedulerAvailable() ? '#00a32a' : '#d63638'; ?>">
							<?php
							echo esc_html( $scheduler_type ); ?>
                        </strong>
						<?php
						if ( CronWarden::isActionSchedulerAvailable() ): ?>
                            <br><small><?php
								_e( 'Advanced scheduling with better reliability', 'cron-warden' ); ?></small>
						<?php
						else: ?>
                            <br><small><?php
								_e( 'Standard WordPress cron system', 'cron-warden' ); ?></small>
						<?php
						endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php
						_e( 'Cron Job Status:', 'cron-warden' ); ?></th>
                    <td>
						<?php
						if ( $next_run ): ?>
                            <span style="color: green;">● <?php
								_e( 'Active', 'cron-warden' ); ?></span>
                            <br>
                            <small>
								<?php
								printf(
									__( 'Next run: %s', 'cron-warden' ),
									wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
										$next_run ),
								); ?>
                            </small>
						<?php
						else: ?>
                            <span style="color: red;">● <?php
								_e( 'Inactive', 'cron-warden' ); ?></span>
						<?php
						endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php
						_e( 'Missed Posts:', 'cron-warden' ); ?></th>
                    <td>
                        <strong style="font-size: 18px; color: <?php
						echo $missed_count > 0 ? '#d63638' : '#00a32a'; ?>">
							<?php
							echo (int) $missed_count; ?>
                        </strong>
						<?php
						if ( $missed_count > 0 ): ?>
                            <br><small><?php
								_e( 'Posts waiting to be published', 'cron-warden' ); ?></small>
						<?php
						endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php
						_e( 'Check Interval:', 'cron-warden' ); ?></th>
                    <td><?php
						_e( 'Every 2 minutes', 'cron-warden' ); ?></td>
                </tr>
				<?php
				if ( CronWarden::isActionSchedulerAvailable() ): ?>
                    <tr>
                        <th><?php
							_e( 'Pending Actions:', 'cron-warden' ); ?></th>
                        <td><?php
							echo CronWarden::getActionCount( CRON_WARDEN_ACTION_HOOK, 'pending' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'Completed Actions:', 'cron-warden' ); ?></th>
                        <td><?php
							echo CronWarden::getActionCount( CRON_WARDEN_ACTION_HOOK, 'complete' ); ?></td>
                    </tr>
				<?php
				endif; ?>
            </table>
        </div>

		<?php
		if ( ! empty( $last_run_data ) ): ?>
            <div class="card">
                <h2><?php
					_e( 'Last Run Statistics', 'cron-warden' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php
							_e( 'Last Execution:', 'cron-warden' ); ?></th>
                        <td>
							<?php
							echo wp_date(
								get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
								$last_run_data[ 'timestamp' ] ?? 0,
							); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'Posts Processed:', 'cron-warden' ); ?></th>
                        <td><?php
							echo (int) ( $last_run_data[ 'processed' ] ?? 0 ); ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'Posts Published:', 'cron-warden' ); ?></th>
                        <td style="color: #00a32a;"><?php
							echo (int) ( $last_run_data[ 'published' ] ?? 0 ); ?></td>
                    </tr>
					<?php
					if ( ( $last_run_data[ 'failed' ] ?? 0 ) > 0 ): ?>
                        <tr>
                            <th><?php
								_e( 'Failed:', 'cron-warden' ); ?></th>
                            <td style="color: #d63638;"><?php
								echo (int) $last_run_data[ 'failed' ]; ?></td>
                        </tr>
					<?php
					endif; ?>
                    <tr>
                        <th><?php
							_e( 'Execution Time:', 'cron-warden' ); ?></th>
                        <td><?php
							echo esc_html( ( $last_run_data[ 'execution_time' ] ?? 0 ) . 'ms' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'Scheduler Used:', 'cron-warden' ); ?></th>
                        <td><?php
							echo esc_html( $last_run_data[ 'scheduler_type' ] ?? 'Unknown' ); ?></td>
                    </tr>
                </table>
            </div>
		<?php
		endif; ?>

        <div class="card">
            <h2><?php
				_e( 'Manual Actions', 'cron-warden' ); ?></h2>

            <form method="post" style="display: inline-block; margin-right: 10px;">
				<?php
				wp_nonce_field( 'cron_warden_manual' ); ?>
                <p>
                    <input type="submit"
                           name="manual_run"
                           class="button button-primary"
                           value="<?php
					       esc_attr_e( 'Run Check Now', 'cron-warden' ); ?>"/>
                </p>
                <p class="description">
					<?php
					_e( 'Manually check for missed posts and publish them immediately.', 'cron-warden' ); ?>
                </p>
            </form>

			<?php
			if ( CronWarden::isActionSchedulerAvailable() ): ?>
                <form method="post" style="display: inline-block;">
					<?php
					wp_nonce_field( 'cron_warden_cleanup' ); ?>
                    <p>
                        <input type="submit"
                               name="cleanup_actions"
                               class="button"
                               value="<?php
						       esc_attr_e( 'Cleanup Old Actions', 'cron-warden' ); ?>"/>
                    </p>
                    <p class="description">
						<?php
						_e( 'Remove completed Action Scheduler entries older than 24 hours.', 'cron-warden' ); ?>
                    </p>
                </form>
			<?php
			endif; ?>
        </div>

		<?php
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ): ?>
            <div class="card">
                <h2><?php
					_e( 'Debug Info', 'cron-warden' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php
							_e( 'PHP Version:', 'cron-warden' ); ?></th>
                        <td><?php
							echo esc_html( PHP_VERSION ); ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'WordPress Version:', 'cron-warden' ); ?></th>
                        <td><?php
							echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'WP Cron Status:', 'cron-warden' ); ?></th>
                        <td><?php
							echo defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Disabled' : 'Enabled'; ?></td>
                    </tr>
                    <tr>
                        <th><?php
							_e( 'Action Scheduler:', 'cron-warden' ); ?></th>
                        <td>
							<?php
							echo CronWarden::isActionSchedulerAvailable() ? 'Available' : 'Not Available'; ?>
							<?php
							if ( CronWarden::isActionSchedulerAvailable() && defined( 'ActionScheduler_DataController::STORE_CLASS' ) ): ?>
                                <br><small>Store: <?php
									echo esc_html( ActionScheduler_DataController::STORE_CLASS ); ?></small>
							<?php
							endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
		<?php
		endif; ?>
    </div>

    <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
            padding: 20px;
            margin: 20px 0;
        }

        .card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
    </style>
	<?php
}

// Hook everything up
add_filter( 'cron_schedules', 'cron_warden_add_intervals' );
add_action( CRON_WARDEN_ACTION_HOOK, 'cron_warden_check_missed_posts' );

// Plugin activation/deactivation
register_activation_hook( __FILE__, 'cron_warden_activate' );
register_deactivation_hook( __FILE__, 'cron_warden_deactivate' );

// Admin interface
if ( is_admin() ) {
	add_action( 'admin_menu', 'cron_warden_add_admin_menu' );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cron_warden_add_settings_link' );
}

// Cleanup old actions weekly (only if Action Scheduler is available)
add_action( 'wp_weekly_event', function () {
	if ( CronWarden::isActionSchedulerAvailable() ) {
		CronWarden::cleanupOldActions( CRON_WARDEN_ACTION_HOOK, 168 ); // Clean actions older than 1 week
	}
} );