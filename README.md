# Cron Warden

A simple, modern WordPress plugin that automatically republishes missed scheduled posts. Built for PHP 8+ with enhanced security and user-friendly interface.

## 🚀 Features

- **Automatic Recovery**: Finds and publishes posts that missed their scheduled time
- **Regular Monitoring**: Runs every 5 minutes to catch missed posts quickly
- **Simple Interface**: Clean status page with manual check option
- **Modern & Secure**: Built for PHP 8+ with prepared SQL statements
- **Debug Logging**: Optional logging when WP_DEBUG is enabled
- **Performance Optimized**: Processes up to 10 posts per check

## 📋 Requirements

- **WordPress**: 6.0 or higher
- **PHP**: 8.0 or higher
- **MySQL**: 5.7+ or MariaDB 10.3+

## 🛠 Installation

1. **Download** the plugin files
2. **Upload** to `/wp-content/plugins/cron-warden/` directory
3. **Activate** the plugin through WordPress admin
4. **Check status** at Tools → Cron Warden

### File Structure:
```
cron-warden/
├── cron-warden.php    # Main plugin file
└── README.md          # This documentation
```

## ⚙️ How It Works

### Automatic Process:
1. **Cron Job**: Runs every 5 minutes automatically
2. **Detection**: Finds posts with `post_status='future'` and `post_date <= NOW()`
3. **Publishing**: Uses WordPress native `wp_publish_post()` function
4. **Logging**: Records activity when WP_DEBUG is enabled

### Manual Process:
1. Navigate to **Tools → Cron Warden**
2. Click **"Run Check Now"** button
3. View results and updated statistics

## 📊 Status Page

The admin interface shows:

- **Cron Job Status**: Active/Inactive with next run time
- **Missed Posts Count**: Real-time count of posts waiting to be published
- **Manual Check Button**: Immediate check and publish option
- **Debug Information**: PHP version, WordPress version, cron status (when WP_DEBUG enabled)

## 🔧 Configuration

### Default Settings:
- **Check Interval**: Every 5 minutes (300 seconds)
- **Posts Per Check**: 10 posts maximum
- **Post Types**: All post types with scheduled publishing
- **Logging**: Enabled only when `WP_DEBUG` is true

### Customization (for developers):
```php
// Change check interval (example: every 2 minutes)
add_filter('cron_schedules', function($schedules) {
    $schedules['cron_warden_5min']['interval'] = 120;
    return $schedules;
});

// Hook into post publishing
add_action('wp_publish_post', function($post_id) {
    // Your custom code when any post is published
});
```

## 🆚 Comparison with Original Plugin

| Feature | Original Plugin | Cron Warden |
|---------|----------------|-------------|
| PHP Version | 5.6+ | 8.0+ |
| Code Style | Procedural | Modern OOP-style functions |
| Security | Basic | Prepared SQL statements |
| Admin Interface | None | Simple status page |
| Manual Control | None | Run check button |
| Monitoring | None | Real-time statistics |
| Logging | None | Debug logging |
| Error Handling | Basic | Enhanced validation |
| Posts Per Check | 5 | 10 |
| Documentation | Minimal | Comprehensive |

## 🔒 Security Features

- **SQL Injection Protection**: All database queries use prepared statements
- **Nonce Verification**: CSRF protection for admin actions
- **Capability Checks**: Only users with `manage_options` can access admin features
- **Input Validation**: All user inputs are properly sanitized
- **Direct Access Prevention**: Files protected from direct execution

## 🐛 Troubleshooting

### Common Issues:

**Cron Job Not Running:**
```php
// Check if cron is disabled
if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    echo "WordPress cron is disabled";
}

// Check next scheduled run
$next_run = wp_next_scheduled('cron_warden_check_posts');
echo $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled';
```

**Posts Not Publishing:**
1. Check if posts are actually missed (future status with past date)
2. Verify WordPress user permissions
3. Enable WP_DEBUG to see error logs
4. Try manual check from admin page

**Plugin Not Activating:**
- Ensure PHP 8.0+ is installed
- Check for plugin conflicts
- Verify file permissions

### Debug Mode:
Enable debugging in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `/wp-content/debug.log` for Cron Warden activity.

## 📝 Development

### Key Functions:
- `cron_warden_check_missed_posts()` - Main function that finds and publishes posts
- `cron_warden_activate()` - Sets up cron job on plugin activation
- `cron_warden_deactivate()` - Cleans up cron job on deactivation
- `cron_warden_status_page()` - Displays admin interface

### Database Query:
```sql
SELECT ID FROM wp_posts 
WHERE post_status = 'future' 
AND post_date <= NOW() 
LIMIT 10
```

### Hooks Used:
- `cron_schedules` - Adds custom 5-minute interval
- `cron_warden_check_posts` - Custom action for cron job
- `admin_menu` - Adds status page to Tools menu

## ⚡ Performance

### Optimizations:
- **Limited Queries**: Maximum 10 posts per check
- **Efficient SQL**: Simple, indexed queries
- **No External Requests**: Uses only WordPress core functions
- **Minimal Memory Usage**: Lightweight code structure

### Server Impact:
- **CPU Usage**: Minimal (runs for seconds every 5 minutes)
- **Memory**: ~1MB during execution
- **Database**: Single SELECT and UPDATE per missed post

## 📞 Support

### Self-Help:
1. Check the status page at Tools → Cron Warden
2. Enable WP_DEBUG for detailed logs
3. Try manual check to test functionality
4. Review this documentation

### Getting Help:
- **WordPress.org**: [Plugin Support Forum](https://wordpress.org/support/)
- **GitHub**: [Create an Issue](https://github.com/HubYura/cron-warden/issues)
- **Documentation**: [Plugin Website](https://HubYura/cron-warden)

## 📄 License

GPL v2 or later - https://www.gnu.org/licenses/gpl-2.0.html

## 🔄 Changelog

### v2.0.0
- ✨ Complete rewrite for PHP 8+
- ✨ Added admin status page
- ✨ Enhanced security with prepared statements
- ✨ Manual check functionality
- ✨ Real-time statistics
- ✨ Debug logging support
- 🔧 Increased posts per check from 5 to 10
- 🔒 Added nonce verification
- 📚 Comprehensive documentation

### v1.0.1 (Original)
- 🎯 Basic missed post recovery
- ⏰ 5-minute cron schedule
- 📝 Minimal functionality

---

**Built with ❤️ for the WordPress community**

*Simple, secure, and reliable missed post recovery.*