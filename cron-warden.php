<?php
/**
 * Plugin Name: Cron Warden
 * Plugin URI: https://github.com/HubYura/cron-warden
 * Description: Automatically republish missed scheduled posts. Runs every 5 minutes to find and publish posts that missed their schedule.
 * Version: 2.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: HubYura
 * Author URI: https://github.com/HubYura
 * License: GPL v2
 * Text Domain: cron-warden
 */

// Prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

// PHP 8.0+ check
if (version_compare(PHP_VERSION, '8.0', '<')) {
	add_action('admin_notices', function(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__('Cron Warden requires PHP 8.0 or higher.', 'cron-warden');
		echo '</p></div>';
	});
	return;
}

/**
 * Add custom cron interval
 */
function cron_warden_add_intervals(array $schedules): array {
	$schedules['cron_warden_5min'] = [
		'interval' => 300, // 5 minutes
		'display' => __('Cron Warden - Every 5 minutes', 'cron-warden')
	];

	return $schedules;
}

/**
 * Check and publish missed scheduled posts
 */
function cron_warden_check_missed_posts(): void {
	global $wpdb;

	// Get missed posts with prepared statement for security
	$query = $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} 
         WHERE post_status = %s 
         AND post_date <= %s 
         LIMIT %d",
		'future',
		current_time('mysql'),
		10
	);

	$missed_post_ids = $wpdb->get_col($query);

	if (empty($missed_post_ids)) {
		return;
	}

	// Publish each missed post
	foreach ($missed_post_ids as $post_id) {
		$post_id = (int) $post_id;

		// Verify post exists and is still scheduled
		$post = get_post($post_id);
		if ($post && $post->post_status === 'future') {
			$result = wp_publish_post($post_id);

			// Log success/failure if WP_DEBUG is enabled
			if (defined('WP_DEBUG') && WP_DEBUG) {
				if ($result) {
					error_log(sprintf('Cron Warden: Published post ID %d (%s)', $post_id, $post->post_title));
				} else {
					error_log(sprintf('Cron Warden: Failed to publish post ID %d', $post_id));
				}
			}
		}
	}
}

/**
 * Activate plugin - schedule cron job
 */
function cron_warden_activate(): void {
	if (!wp_next_scheduled('cron_warden_check_posts')) {
		wp_schedule_event(time(), 'cron_warden_5min', 'cron_warden_check_posts');
	}

	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log('Cron Warden: Plugin activated and scheduled');
	}
}

/**
 * Deactivate plugin - clear scheduled job
 */
function cron_warden_deactivate(): void {
	$timestamp = wp_next_scheduled('cron_warden_check_posts');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'cron_warden_check_posts');
	}

	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log('Cron Warden: Plugin deactivated and unscheduled');
	}
}

/**
 * Add settings link to plugins page
 */
function cron_warden_add_settings_link(array $links): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		admin_url('tools.php?page=cron-warden-status'),
		__('Status', 'cron-warden')
	);

	array_unshift($links, $settings_link);
	return $links;
}

/**
 * Add simple status page to Tools menu
 */
function cron_warden_add_admin_menu(): void {
	add_management_page(
		__('Cron Warden Status', 'cron-warden'),
		__('Cron Warden', 'cron-warden'),
		'manage_options',
		'cron-warden-status',
		'cron_warden_status_page'
	);
}

/**
 * Display simple status page
 */
function cron_warden_status_page(): void {
	global $wpdb;

	// Get current stats
	$next_run = wp_next_scheduled('cron_warden_check_posts');
	$missed_count = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} 
         WHERE post_status = 'future' 
         AND post_date <= NOW()"
	);

	// Handle manual run
	if (isset($_POST['manual_run']) && wp_verify_nonce($_POST['_wpnonce'], 'cron_warden_manual')) {
		cron_warden_check_missed_posts();
		echo '<div class="notice notice-success"><p>' . __('Manual check completed!', 'cron-warden') . '</p></div>';

		// Refresh stats
		$missed_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_status = 'future' 
             AND post_date <= NOW()"
		);
	}
	?>

	<div class="wrap">
		<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

		<div class="card">
			<h2><?php _e('Status', 'cron-warden'); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php _e('Cron Job Status:', 'cron-warden'); ?></th>
					<td>
						<?php if ($next_run): ?>
							<span style="color: green;">● <?php _e('Active', 'cron-warden'); ?></span>
							<br>
							<small>
								<?php printf(
									__('Next run: %s', 'cron-warden'),
									wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run)
								); ?>
							</small>
						<?php else: ?>
							<span style="color: red;">● <?php _e('Inactive', 'cron-warden'); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php _e('Missed Posts:', 'cron-warden'); ?></th>
					<td>
						<strong style="font-size: 18px; color: <?php echo $missed_count > 0 ? '#d63638' : '#00a32a'; ?>">
							<?php echo (int) $missed_count; ?>
						</strong>
						<?php if ($missed_count > 0): ?>
							<br><small><?php _e('Posts waiting to be published', 'cron-warden'); ?></small>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php _e('Check Interval:', 'cron-warden'); ?></th>
					<td><?php _e('Every 5 minutes', 'cron-warden'); ?></td>
				</tr>
			</table>
		</div>

		<div class="card">
			<h2><?php _e('Manual Actions', 'cron-warden'); ?></h2>
			<form method="post">
				<?php wp_nonce_field('cron_warden_manual'); ?>
				<p>
					<input type="submit"
					       name="manual_run"
					       class="button button-primary"
					       value="<?php esc_attr_e('Run Check Now', 'cron-warden'); ?>" />
				</p>
				<p class="description">
					<?php _e('Manually check for missed posts and publish them immediately.', 'cron-warden'); ?>
				</p>
			</form>
		</div>

		<?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
			<div class="card">
				<h2><?php _e('Debug Info', 'cron-warden'); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php _e('PHP Version:', 'cron-warden'); ?></th>
						<td><?php echo esc_html(PHP_VERSION); ?></td>
					</tr>
					<tr>
						<th><?php _e('WordPress Version:', 'cron-warden'); ?></th>
						<td><?php echo esc_html(get_bloginfo('version')); ?></td>
					</tr>
					<tr>
						<th><?php _e('WP Cron Status:', 'cron-warden'); ?></th>
						<td><?php echo defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'Disabled' : 'Enabled'; ?></td>
					</tr>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
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
add_filter('cron_schedules', 'cron_warden_add_intervals');
add_action('cron_warden_check_posts', 'cron_warden_check_missed_posts');

// Plugin activation/deactivation
register_activation_hook(__FILE__, 'cron_warden_activate');
register_deactivation_hook(__FILE__, 'cron_warden_deactivate');

// Admin interface
if (is_admin()) {
	add_action('admin_menu', 'cron_warden_add_admin_menu');
	add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'cron_warden_add_settings_link');
}