<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Speed up your WordPress site with free CDN delivery and automatic image optimization. Reduces load times and bandwidth costs.
 * Version: 2.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Coozywana
 * Author URI: https://staticdelivr.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: staticdelivr
 *
 * @package StaticDelivr
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
if (!defined('STATICDELIVR_VERSION')) {
    define('STATICDELIVR_VERSION', '2.2.0');
}
if (!defined('STATICDELIVR_PLUGIN_FILE')) {
    define('STATICDELIVR_PLUGIN_FILE', __FILE__);
}
if (!defined('STATICDELIVR_PLUGIN_DIR')) {
    define('STATICDELIVR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('STATICDELIVR_PLUGIN_URL')) {
    define('STATICDELIVR_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('STATICDELIVR_PREFIX')) {
    define('STATICDELIVR_PREFIX', 'staticdelivr_');
}
if (!defined('STATICDELIVR_CDN_BASE')) {
    define('STATICDELIVR_CDN_BASE', 'https://cdn.staticdelivr.com');
}
if (!defined('STATICDELIVR_IMG_CDN_BASE')) {
    define('STATICDELIVR_IMG_CDN_BASE', 'https://cdn.staticdelivr.com/img/images');
}

// Verification cache settings.
if (!defined('STATICDELIVR_CACHE_DURATION')) {
    define('STATICDELIVR_CACHE_DURATION', 7 * DAY_IN_SECONDS); // 7 days.
}
if (!defined('STATICDELIVR_API_TIMEOUT')) {
    define('STATICDELIVR_API_TIMEOUT', 3); // 3 seconds.
}
if (!defined('STATICDELIVR_FAILURE_CACHE_DURATION')) {
    define('STATICDELIVR_FAILURE_CACHE_DURATION', DAY_IN_SECONDS); // 24 hours.
}
if (!defined('STATICDELIVR_FAILURE_THRESHOLD')) {
    define('STATICDELIVR_FAILURE_THRESHOLD', 2); // Block after 2 failures.
}

/**
 * Load plugin classes.
 *
 * Includes all required class files in dependency order.
 *
 * @return void
 */
function staticdelivr_load_classes()
{
    $includes_path = STATICDELIVR_PLUGIN_DIR . 'includes/';

    // Load classes in dependency order.
    require_once $includes_path . 'class-staticdelivr-failure-tracker.php';
    require_once $includes_path . 'class-staticdelivr-verification.php';
    require_once $includes_path . 'class-staticdelivr-assets.php';
    require_once $includes_path . 'class-staticdelivr-images.php';
    require_once $includes_path . 'class-staticdelivr-google-fonts.php';
    require_once $includes_path . 'class-staticdelivr-fallback.php';
    require_once $includes_path . 'class-staticdelivr-admin.php';
    require_once $includes_path . 'class-staticdelivr.php';
}

/**
 * Load plugin text domain for translations.
 *
 * @return void
 */
function staticdelivr_load_textdomain()
{
    load_plugin_textdomain(
        'staticdelivr',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('init', 'staticdelivr_load_textdomain');

/**
 * Initialize the plugin.
 *
 * Loads classes and starts the plugin.
 *
 * @return void
 */
function staticdelivr_init()
{
    staticdelivr_load_classes();
    StaticDelivr::get_instance();
}

// Initialize plugin after WordPress is loaded.
add_action('plugins_loaded', 'staticdelivr_init');

// Activation hook - set default options.
register_activation_hook(__FILE__, 'staticdelivr_activate');

/**
 * Plugin activation callback.
 *
 * Sets default options and schedules cleanup cron.
 *
 * @return void
 */
function staticdelivr_activate()
{
    // Enable features by default for new installs.
    if (get_option(STATICDELIVR_PREFIX . 'assets_enabled') === false) {
        update_option(STATICDELIVR_PREFIX . 'assets_enabled', 1);
    }
    if (get_option(STATICDELIVR_PREFIX . 'images_enabled') === false) {
        update_option(STATICDELIVR_PREFIX . 'images_enabled', 1);
    }
    if (get_option(STATICDELIVR_PREFIX . 'image_quality') === false) {
        update_option(STATICDELIVR_PREFIX . 'image_quality', 80);
    }
    if (get_option(STATICDELIVR_PREFIX . 'image_format') === false) {
        update_option(STATICDELIVR_PREFIX . 'image_format', 'webp');
    }
    if (get_option(STATICDELIVR_PREFIX . 'google_fonts_enabled') === false) {
        update_option(STATICDELIVR_PREFIX . 'google_fonts_enabled', 1);
    }

    // Schedule daily cleanup cron.
    if (!wp_next_scheduled(STATICDELIVR_PREFIX . 'daily_cleanup')) {
        wp_schedule_event(time(), 'daily', STATICDELIVR_PREFIX . 'daily_cleanup');
    }

    // Set flag to show welcome notice.
    set_transient(STATICDELIVR_PREFIX . 'activation_notice', true, 60);
}

// Deactivation hook - cleanup.
register_deactivation_hook(__FILE__, 'staticdelivr_deactivate');

/**
 * Plugin deactivation callback.
 *
 * Clears scheduled cron events.
 *
 * @return void
 */
function staticdelivr_deactivate()
{
    wp_clear_scheduled_hook(STATICDELIVR_PREFIX . 'daily_cleanup');
}

// Add Settings link to plugins page.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'staticdelivr_action_links');

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function staticdelivr_action_links($links)
{
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings')) . '">' . __('Settings', 'staticdelivr') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add helpful links in plugin meta row.
add_filter('plugin_row_meta', 'staticdelivr_row_meta', 10, 2);

/**
 * Add additional links to plugin row meta.
 *
 * @param array  $links Existing meta links.
 * @param string $file  Plugin file path.
 * @return array Modified meta links.
 */
function staticdelivr_row_meta($links, $file)
{
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">' . __('Website', 'staticdelivr') . '</a>';
        $links[] = '<a href="https://staticdelivr.com/become-a-sponsor" target="_blank" rel="noopener noreferrer">' . __('Support Development', 'staticdelivr') . '</a>';
    }
    return $links;
}

/**
 * Get the main StaticDelivr plugin instance.
 *
 * Helper function to access the plugin instance from anywhere.
 *
 * @return StaticDelivr|null Plugin instance or null if not initialized.
 */
function staticdelivr()
{
    if (class_exists('StaticDelivr')) {
        return StaticDelivr::get_instance();
    }
    return null;
}
