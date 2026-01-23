<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Speed up your WordPress site with free CDN delivery and automatic image optimization. Reduces load times and bandwidth costs.
 * Version: 1.7.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Coozywana
 * Author URI: https://staticdelivr.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: staticdelivr
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants.
if ( ! defined( 'STATICDELIVR_VERSION' ) ) {
    define( 'STATICDELIVR_VERSION', '1.7.0' );
}
if ( ! defined( 'STATICDELIVR_PLUGIN_FILE' ) ) {
    define( 'STATICDELIVR_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'STATICDELIVR_PLUGIN_DIR' ) ) {
    define( 'STATICDELIVR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'STATICDELIVR_PLUGIN_URL' ) ) {
    define( 'STATICDELIVR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'STATICDELIVR_PREFIX' ) ) {
    define( 'STATICDELIVR_PREFIX', 'staticdelivr_' );
}
if ( ! defined( 'STATICDELIVR_CDN_BASE' ) ) {
    define( 'STATICDELIVR_CDN_BASE', 'https://cdn.staticdelivr.com' );
}
if ( ! defined( 'STATICDELIVR_IMG_CDN_BASE' ) ) {
    define( 'STATICDELIVR_IMG_CDN_BASE', 'https://cdn.staticdelivr.com/img/images' );
}

// Verification cache settings.
if ( ! defined( 'STATICDELIVR_CACHE_DURATION' ) ) {
    define( 'STATICDELIVR_CACHE_DURATION', 7 * DAY_IN_SECONDS ); // 7 days.
}
if ( ! defined( 'STATICDELIVR_API_TIMEOUT' ) ) {
    define( 'STATICDELIVR_API_TIMEOUT', 3 ); // 3 seconds.
}
if ( ! defined( 'STATICDELIVR_FAILURE_CACHE_DURATION' ) ) {
    define( 'STATICDELIVR_FAILURE_CACHE_DURATION', DAY_IN_SECONDS ); // 24 hours.
}
if ( ! defined( 'STATICDELIVR_FAILURE_THRESHOLD' ) ) {
    define( 'STATICDELIVR_FAILURE_THRESHOLD', 2 ); // Block after 2 failures.
}

// Activation hook - set default options.
register_activation_hook( __FILE__, 'staticdelivr_activate' );

/**
 * Plugin activation callback.
 *
 * Sets default options and schedules cleanup cron.
 *
 * @return void
 */
function staticdelivr_activate() {
    // Enable features by default for new installs.
    if ( get_option( STATICDELIVR_PREFIX . 'assets_enabled' ) === false ) {
        update_option( STATICDELIVR_PREFIX . 'assets_enabled', 1 );
    }
    if ( get_option( STATICDELIVR_PREFIX . 'images_enabled' ) === false ) {
        update_option( STATICDELIVR_PREFIX . 'images_enabled', 1 );
    }
    if ( get_option( STATICDELIVR_PREFIX . 'image_quality' ) === false ) {
        update_option( STATICDELIVR_PREFIX . 'image_quality', 80 );
    }
    if ( get_option( STATICDELIVR_PREFIX . 'image_format' ) === false ) {
        update_option( STATICDELIVR_PREFIX . 'image_format', 'webp' );
    }
    if ( get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled' ) === false ) {
        update_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', 1 );
    }

    // Schedule daily cleanup cron.
    if ( ! wp_next_scheduled( STATICDELIVR_PREFIX . 'daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', STATICDELIVR_PREFIX . 'daily_cleanup' );
    }

    // Set flag to show welcome notice.
    set_transient( STATICDELIVR_PREFIX . 'activation_notice', true, 60 );
}

// Deactivation hook - cleanup.
register_deactivation_hook( __FILE__, 'staticdelivr_deactivate' );

/**
 * Plugin deactivation callback.
 *
 * Clears scheduled cron events.
 *
 * @return void
 */
function staticdelivr_deactivate() {
    wp_clear_scheduled_hook( STATICDELIVR_PREFIX . 'daily_cleanup' );
}

// Add Settings link to plugins page.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'staticdelivr_action_links' );

/**
 * Add settings link to plugin action links.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function staticdelivr_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings' ) ) . '">' . __( 'Settings', 'staticdelivr' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

// Add helpful links in plugin meta row.
add_filter( 'plugin_row_meta', 'staticdelivr_row_meta', 10, 2 );

/**
 * Add additional links to plugin row meta.
 *
 * @param array  $links Existing meta links.
 * @param string $file  Plugin file path.
 * @return array Modified meta links.
 */
function staticdelivr_row_meta( $links, $file ) {
    if ( plugin_basename( __FILE__ ) === $file ) {
        $links[] = '<a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">' . __( 'Website', 'staticdelivr' ) . '</a>';
        $links[] = '<a href="https://staticdelivr.com/become-a-sponsor" target="_blank" rel="noopener noreferrer">' . __( 'Support Development', 'staticdelivr' ) . '</a>';
    }
    return $links;
}

/**
 * Main StaticDelivr CDN class.
 *
 * Handles URL rewriting for assets, images, and Google Fonts
 * to serve them through the StaticDelivr CDN.
 *
 * @since 1.0.0
 */
class StaticDelivr {

    /**
     * Stores original asset URLs by handle for fallback usage.
     *
     * @var array<string, string>
     */
    private $original_sources = array();

    /**
     * Ensures the fallback script is only enqueued once per request.
     *
     * @var bool
     */
    private $fallback_script_enqueued = false;

    /**
     * Supported image extensions for optimization.
     *
     * @var array<int, string>
     */
    private $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff' );

    /**
     * Cache for plugin/theme versions to avoid repeated filesystem work per request.
     *
     * @var array<string, string>
     */
    private $version_cache = array();

    /**
     * Cached WordPress version.
     *
     * @var string|null
     */
    private $wp_version_cache = null;

    /**
     * Flag to track if output buffering is active.
     *
     * @var bool
     */
    private $output_buffering_started = false;

    /**
     * In-memory cache for wordpress.org verification results.
     *
     * Loaded once from database, used throughout request.
     *
     * @var array|null
     */
    private $verification_cache = null;

    /**
     * Flag to track if verification cache was modified and needs saving.
     *
     * @var bool
     */
    private $verification_cache_dirty = false;

    /**
     * In-memory cache for failed resources.
     *
     * @var array|null
     */
    private $failure_cache = null;

    /**
     * Flag to track if failure cache was modified.
     *
     * @var bool
     */
    private $failure_cache_dirty = false;

    /**
     * Constructor.
     *
     * Sets up all hooks and filters for the plugin.
     */
    public function __construct() {
        // CSS/JS rewriting hooks.
        add_filter( 'style_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
        add_filter( 'script_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
        add_filter( 'script_loader_tag', array( $this, 'inject_script_original_attribute' ), 10, 3 );
        add_filter( 'style_loader_tag', array( $this, 'inject_style_original_attribute' ), 10, 4 );
        add_action( 'wp_head', array( $this, 'inject_fallback_script_early' ), 1 );
        add_action( 'admin_head', array( $this, 'inject_fallback_script_early' ), 1 );

        // Image optimization hooks.
        add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_attachment_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_image_srcset' ), 10, 5 );
        add_filter( 'the_content', array( $this, 'rewrite_content_images' ), 99 );
        add_filter( 'post_thumbnail_html', array( $this, 'rewrite_thumbnail_html' ), 10, 5 );
        add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 10, 2 );

        // Google Fonts hooks.
        add_filter( 'style_loader_src', array( $this, 'rewrite_google_fonts_enqueued' ), 1, 2 );
        add_filter( 'wp_resource_hints', array( $this, 'filter_resource_hints' ), 10, 2 );

        // Output buffer for hardcoded Google Fonts in HTML.
        add_action( 'template_redirect', array( $this, 'start_google_fonts_output_buffer' ), -999 );
        add_action( 'shutdown', array( $this, 'end_google_fonts_output_buffer' ), 999 );

        // Admin hooks.
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'show_activation_notice' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );

        // Theme/plugin change hooks - clear relevant cache entries.
        add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );
        add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );
        add_action( 'deleted_plugin', array( $this, 'on_plugin_deleted' ), 10, 2 );

        // Cron hook for daily cleanup.
        add_action( STATICDELIVR_PREFIX . 'daily_cleanup', array( $this, 'daily_cleanup_task' ) );

        // Save caches on shutdown if modified.
        add_action( 'shutdown', array( $this, 'maybe_save_verification_cache' ), 0 );
        add_action( 'shutdown', array( $this, 'maybe_save_failure_cache' ), 0 );

        // AJAX endpoint for failure reporting.
        add_action( 'wp_ajax_staticdelivr_report_failure', array( $this, 'ajax_report_failure' ) );
        add_action( 'wp_ajax_nopriv_staticdelivr_report_failure', array( $this, 'ajax_report_failure' ) );
    }

    // =========================================================================
    // FAILURE TRACKING SYSTEM
    // =========================================================================

    /**
     * Load failure cache from database.
     *
     * @return void
     */
    private function load_failure_cache() {
        if ( null !== $this->failure_cache ) {
            return;
        }

        $cache = get_transient( STATICDELIVR_PREFIX . 'failed_resources' );

        if ( ! is_array( $cache ) ) {
            $cache = array();
        }

        $this->failure_cache = wp_parse_args(
            $cache,
            array(
                'images' => array(),
                'assets' => array(),
            )
        );
    }

    /**
     * Save failure cache if modified.
     *
     * @return void
     */
    public function maybe_save_failure_cache() {
        if ( $this->failure_cache_dirty && null !== $this->failure_cache ) {
            set_transient(
                STATICDELIVR_PREFIX . 'failed_resources',
                $this->failure_cache,
                STATICDELIVR_FAILURE_CACHE_DURATION
            );
            $this->failure_cache_dirty = false;
        }
    }

    /**
     * Generate a short hash for a URL.
     *
     * @param string $url The URL to hash.
     * @return string 16-character hash.
     */
    private function hash_url( $url ) {
        return substr( md5( $url ), 0, 16 );
    }

    /**
     * Check if a resource has exceeded the failure threshold.
     *
     * @param string $type Resource type: 'image' or 'asset'.
     * @param string $key  Resource identifier (URL hash or slug).
     * @return bool True if should be blocked.
     */
    private function is_resource_blocked( $type, $key ) {
        $this->load_failure_cache();

        $cache_key = ( 'image' === $type ) ? 'images' : 'assets';

        if ( ! isset( $this->failure_cache[ $cache_key ][ $key ] ) ) {
            return false;
        }

        $entry = $this->failure_cache[ $cache_key ][ $key ];

        // Check if entry has expired (shouldn't happen with transient, but safety check).
        if ( isset( $entry['last'] ) ) {
            $age = time() - (int) $entry['last'];
            if ( $age > STATICDELIVR_FAILURE_CACHE_DURATION ) {
                unset( $this->failure_cache[ $cache_key ][ $key ] );
                $this->failure_cache_dirty = true;
                return false;
            }
        }

        // Check threshold.
        $count = isset( $entry['count'] ) ? (int) $entry['count'] : 0;
        return $count >= STATICDELIVR_FAILURE_THRESHOLD;
    }

    /**
     * Record a resource failure.
     *
     * @param string $type     Resource type: 'image' or 'asset'.
     * @param string $key      Resource identifier.
     * @param string $original Original URL for reference.
     * @return void
     */
    private function record_failure( $type, $key, $original = '' ) {
        $this->load_failure_cache();

        $cache_key = ( 'image' === $type ) ? 'images' : 'assets';
        $now       = time();

        if ( isset( $this->failure_cache[ $cache_key ][ $key ] ) ) {
            $this->failure_cache[ $cache_key ][ $key ]['count']++;
            $this->failure_cache[ $cache_key ][ $key ]['last'] = $now;
        } else {
            $this->failure_cache[ $cache_key ][ $key ] = array(
                'count'    => 1,
                'first'    => $now,
                'last'     => $now,
                'original' => $original,
            );
        }

        $this->failure_cache_dirty = true;
    }

    /**
     * AJAX handler for failure reporting from client.
     *
     * @return void
     */
    public function ajax_report_failure() {
        // Verify nonce.
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'staticdelivr_failure_report' ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
        }

        $type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        $original = isset( $_POST['original'] ) ? esc_url_raw( wp_unslash( $_POST['original'] ) ) : '';

        if ( empty( $type ) || empty( $url ) ) {
            wp_send_json_error( 'Missing parameters', 400 );
        }

        // Validate type.
        if ( ! in_array( $type, array( 'image', 'asset' ), true ) ) {
            wp_send_json_error( 'Invalid type', 400 );
        }

        // Generate key based on type.
        if ( 'image' === $type ) {
            $key = $this->hash_url( $original ? $original : $url );
        } else {
            // For assets, try to extract theme/plugin slug.
            $key = $this->extract_asset_key_from_url( $url );
            if ( empty( $key ) ) {
                $key = $this->hash_url( $url );
            }
        }

        $this->record_failure( $type, $key, $original ? $original : $url );
        $this->maybe_save_failure_cache();

        wp_send_json_success( array( 'recorded' => true ) );
    }

    /**
     * Extract asset key (theme/plugin slug) from CDN URL.
     *
     * @param string $url CDN URL.
     * @return string|null Asset key or null.
     */
    private function extract_asset_key_from_url( $url ) {
        // Pattern: /wp/themes/{slug}/ or /wp/plugins/{slug}/
        if ( preg_match( '#/wp/(themes|plugins)/([^/]+)/#', $url, $matches ) ) {
            return $matches[1] . ':' . $matches[2];
        }
        return null;
    }

    /**
     * Check if an image URL is blocked due to previous failures.
     *
     * @param string $url Original image URL.
     * @return bool True if blocked.
     */
    private function is_image_blocked( $url ) {
        $key = $this->hash_url( $url );
        return $this->is_resource_blocked( 'image', $key );
    }

    /**
     * Get failure statistics for admin display.
     *
     * @return array Failure statistics.
     */
    public function get_failure_stats() {
        $this->load_failure_cache();

        $stats = array(
            'images' => array(
                'total'   => 0,
                'blocked' => 0,
                'items'   => array(),
            ),
            'assets' => array(
                'total'   => 0,
                'blocked' => 0,
                'items'   => array(),
            ),
        );

        foreach ( array( 'images', 'assets' ) as $type ) {
            if ( ! empty( $this->failure_cache[ $type ] ) ) {
                foreach ( $this->failure_cache[ $type ] as $key => $entry ) {
                    $stats[ $type ]['total']++;
                    $count = isset( $entry['count'] ) ? (int) $entry['count'] : 0;

                    if ( $count >= STATICDELIVR_FAILURE_THRESHOLD ) {
                        $stats[ $type ]['blocked']++;
                    }

                    $stats[ $type ]['items'][ $key ] = array(
                        'count'    => $count,
                        'blocked'  => $count >= STATICDELIVR_FAILURE_THRESHOLD,
                        'original' => isset( $entry['original'] ) ? $entry['original'] : '',
                        'last'     => isset( $entry['last'] ) ? $entry['last'] : 0,
                    );
                }
            }
        }

        return $stats;
    }

    /**
     * Clear the failure cache.
     *
     * @return void
     */
    public function clear_failure_cache() {
        delete_transient( STATICDELIVR_PREFIX . 'failed_resources' );
        $this->failure_cache       = null;
        $this->failure_cache_dirty = false;
    }

    // =========================================================================
    // VERIFICATION SYSTEM - WordPress.org Detection
    // =========================================================================

    /**
     * Check if a theme or plugin exists on wordpress.org.
     *
     * Uses a multi-layer caching strategy:
     * 1. In-memory cache (for current request)
     * 2. Database cache (persisted between requests)
     * 3. WordPress update transients (built-in WordPress data)
     * 4. WordPress.org API (last resort, with timeout)
     *
     * @param string $type Asset type: 'theme' or 'plugin'.
     * @param string $slug Asset slug (folder name).
     * @return bool True if asset exists on wordpress.org, false otherwise.
     */
    public function is_asset_on_wporg( $type, $slug ) {
        if ( empty( $type ) || empty( $slug ) ) {
            return false;
        }

        // Normalize inputs.
        $type = sanitize_key( $type );
        $slug = sanitize_file_name( $slug );

        // For themes, check if it's a child theme and get parent.
        if ( 'theme' === $type ) {
            $parent_slug = $this->get_parent_theme_slug( $slug );
            if ( $parent_slug && $parent_slug !== $slug ) {
                // This is a child theme - check if parent is on wordpress.org.
                // Child themes themselves are never on wordpress.org, but their parent's files are.
                $slug = $parent_slug;
            }
        }

        // Load verification cache from database if not already loaded.
        $this->load_verification_cache();

        // Check in-memory/database cache first.
        $cached_result = $this->get_cached_verification( $type, $slug );
        if ( null !== $cached_result ) {
            return $cached_result;
        }

        // Check WordPress update transients (fast, already available).
        $transient_result = $this->check_wporg_transients( $type, $slug );
        if ( null !== $transient_result ) {
            $this->cache_verification_result( $type, $slug, $transient_result, 'transient' );
            return $transient_result;
        }

        // Last resort: Query wordpress.org API (slow, but definitive).
        $api_result = $this->query_wporg_api( $type, $slug );
        $this->cache_verification_result( $type, $slug, $api_result, 'api' );

        return $api_result;
    }

    /**
     * Load verification cache from database into memory.
     *
     * Only loads once per request for performance.
     *
     * @return void
     */
    private function load_verification_cache() {
        if ( null !== $this->verification_cache ) {
            return; // Already loaded.
        }

        $cache = get_option( STATICDELIVR_PREFIX . 'verified_assets', array() );

        // Ensure proper structure.
        if ( ! is_array( $cache ) ) {
            $cache = array();
        }

        $this->verification_cache = wp_parse_args(
            $cache,
            array(
                'themes'       => array(),
                'plugins'      => array(),
                'last_cleanup' => 0,
            )
        );
    }

    /**
     * Get cached verification result.
     *
     * @param string $type Asset type: 'theme' or 'plugin'.
     * @param string $slug Asset slug.
     * @return bool|null Cached result or null if not cached/expired.
     */
    private function get_cached_verification( $type, $slug ) {
        $key = ( 'theme' === $type ) ? 'themes' : 'plugins';

        if ( ! isset( $this->verification_cache[ $key ][ $slug ] ) ) {
            return null;
        }

        $entry = $this->verification_cache[ $key ][ $slug ];

        // Check if entry has required fields.
        if ( ! isset( $entry['on_wporg'] ) || ! isset( $entry['checked_at'] ) ) {
            return null;
        }

        // Check if cache has expired.
        $age = time() - (int) $entry['checked_at'];
        if ( $age > STATICDELIVR_CACHE_DURATION ) {
            return null; // Expired.
        }

        return (bool) $entry['on_wporg'];
    }

    /**
     * Cache a verification result.
     *
     * @param string $type     Asset type: 'theme' or 'plugin'.
     * @param string $slug     Asset slug.
     * @param bool   $on_wporg Whether asset is on wordpress.org.
     * @param string $method   Verification method used: 'transient' or 'api'.
     * @return void
     */
    private function cache_verification_result( $type, $slug, $on_wporg, $method ) {
        $key = ( 'theme' === $type ) ? 'themes' : 'plugins';

        $this->verification_cache[ $key ][ $slug ] = array(
            'on_wporg'   => (bool) $on_wporg,
            'checked_at' => time(),
            'method'     => sanitize_key( $method ),
        );

        $this->verification_cache_dirty = true;
    }

    /**
     * Save verification cache to database if it was modified.
     *
     * Called on shutdown to batch database writes.
     *
     * @return void
     */
    public function maybe_save_verification_cache() {
        if ( $this->verification_cache_dirty && null !== $this->verification_cache ) {
            update_option( STATICDELIVR_PREFIX . 'verified_assets', $this->verification_cache, false );
            $this->verification_cache_dirty = false;
        }
    }

    /**
     * Check WordPress update transients for asset information.
     *
     * WordPress automatically tracks which themes/plugins are from wordpress.org
     * via the update system. This is the fastest verification method.
     *
     * @param string $type Asset type: 'theme' or 'plugin'.
     * @param string $slug Asset slug.
     * @return bool|null True if found, false if definitively not found, null if inconclusive.
     */
    private function check_wporg_transients( $type, $slug ) {
        if ( 'theme' === $type ) {
            return $this->check_theme_transient( $slug );
        } else {
            return $this->check_plugin_transient( $slug );
        }
    }

    /**
     * Check update_themes transient for a theme.
     *
     * @param string $slug Theme slug.
     * @return bool|null True if on wordpress.org, false if not, null if inconclusive.
     */
    private function check_theme_transient( $slug ) {
        $transient = get_site_transient( 'update_themes' );

        if ( ! $transient || ! is_object( $transient ) ) {
            return null; // Transient doesn't exist yet.
        }

        // Check 'checked' array - contains all themes WordPress knows about.
        if ( isset( $transient->checked ) && is_array( $transient->checked ) ) {
            // If theme is in 'response' or 'no_update', it's on wordpress.org.
            if ( isset( $transient->response[ $slug ] ) || isset( $transient->no_update[ $slug ] ) ) {
                return true;
            }

            // If theme is in 'checked' but not in response/no_update,
            // it means WordPress checked it and it's not on wordpress.org.
            if ( isset( $transient->checked[ $slug ] ) ) {
                return false;
            }
        }

        // Theme not found in any array - inconclusive.
        return null;
    }

    /**
     * Check update_plugins transient for a plugin.
     *
     * @param string $slug Plugin slug (folder name).
     * @return bool|null True if on wordpress.org, false if not, null if inconclusive.
     */
    private function check_plugin_transient( $slug ) {
        $transient = get_site_transient( 'update_plugins' );

        if ( ! $transient || ! is_object( $transient ) ) {
            return null; // Transient doesn't exist yet.
        }

        // Plugin files are stored as 'folder/file.php' format.
        // We need to find any entry that starts with our slug.
        $found_in_checked = false;

        // Check 'checked' array first to see if WordPress knows about this plugin.
        if ( isset( $transient->checked ) && is_array( $transient->checked ) ) {
            foreach ( array_keys( $transient->checked ) as $plugin_file ) {
                if ( strpos( $plugin_file, $slug . '/' ) === 0 || $plugin_file === $slug . '.php' ) {
                    $found_in_checked = true;

                    // Now check if it's in response (has update) or no_update (up to date).
                    if ( isset( $transient->response[ $plugin_file ] ) || isset( $transient->no_update[ $plugin_file ] ) ) {
                        return true; // On wordpress.org.
                    }
                }
            }
        }

        // If found in checked but not in response/no_update, it's not on wordpress.org.
        if ( $found_in_checked ) {
            return false;
        }

        return null; // Inconclusive.
    }

    /**
     * Query wordpress.org API to verify if asset exists.
     *
     * This is the slowest method but provides a definitive answer.
     * Results are cached to avoid repeated API calls.
     *
     * @param string $type Asset type: 'theme' or 'plugin'.
     * @param string $slug Asset slug.
     * @return bool True if asset exists on wordpress.org, false otherwise.
     */
    private function query_wporg_api( $type, $slug ) {
        if ( 'theme' === $type ) {
            return $this->query_wporg_themes_api( $slug );
        } else {
            return $this->query_wporg_plugins_api( $slug );
        }
    }

    /**
     * Query wordpress.org Themes API.
     *
     * @param string $slug Theme slug.
     * @return bool True if theme exists, false otherwise.
     */
    private function query_wporg_themes_api( $slug ) {
        // Use WordPress built-in themes API function if available.
        if ( ! function_exists( 'themes_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $args = array(
            'slug'   => $slug,
            'fields' => array(
                'description' => false,
                'sections'    => false,
                'tags'        => false,
                'screenshot'  => false,
                'ratings'     => false,
                'downloaded'  => false,
                'downloadlink' => false,
            ),
        );

        // Set a short timeout to avoid blocking page load.
        add_filter( 'http_request_timeout', array( $this, 'set_api_timeout' ) );
        $response = themes_api( 'theme_information', $args );
        remove_filter( 'http_request_timeout', array( $this, 'set_api_timeout' ) );

        if ( is_wp_error( $response ) ) {
            // API error - could be timeout, network issue, or theme not found.
            // Check error code to distinguish.
            $error_data = $response->get_error_data();
            if ( isset( $error_data['status'] ) && 404 === $error_data['status'] ) {
                return false; // Definitively not on wordpress.org.
            }
            // For other errors (timeout, network), be pessimistic and assume not available.
            // This prevents broken pages if API is slow.
            return false;
        }

        // Valid response means theme exists.
        return ( is_object( $response ) && isset( $response->slug ) );
    }

    /**
     * Query wordpress.org Plugins API.
     *
     * @param string $slug Plugin slug.
     * @return bool True if plugin exists, false otherwise.
     */
    private function query_wporg_plugins_api( $slug ) {
        // Use WordPress built-in plugins API function if available.
        if ( ! function_exists( 'plugins_api' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        $args = array(
            'slug'   => $slug,
            'fields' => array(
                'description'  => false,
                'sections'     => false,
                'tags'         => false,
                'screenshots'  => false,
                'ratings'      => false,
                'downloaded'   => false,
                'downloadlink' => false,
                'icons'        => false,
                'banners'      => false,
            ),
        );

        // Set a short timeout to avoid blocking page load.
        add_filter( 'http_request_timeout', array( $this, 'set_api_timeout' ) );
        $response = plugins_api( 'plugin_information', $args );
        remove_filter( 'http_request_timeout', array( $this, 'set_api_timeout' ) );

        if ( is_wp_error( $response ) ) {
            // Same logic as themes - be pessimistic on errors.
            return false;
        }

        // Valid response means plugin exists.
        return ( is_object( $response ) && isset( $response->slug ) );
    }

    /**
     * Filter callback to set API request timeout.
     *
     * @param int $timeout Default timeout.
     * @return int Modified timeout.
     */
    public function set_api_timeout( $timeout ) {
        return STATICDELIVR_API_TIMEOUT;
    }

    /**
     * Get parent theme slug if the given theme is a child theme.
     *
     * @param string $theme_slug Theme slug to check.
     * @return string|null Parent theme slug or null if not a child theme.
     */
    private function get_parent_theme_slug( $theme_slug ) {
        $theme = wp_get_theme( $theme_slug );

        if ( ! $theme->exists() ) {
            return null;
        }

        $parent = $theme->parent();

        if ( $parent && $parent->exists() ) {
            return $parent->get_stylesheet();
        }

        return null;
    }

    /**
     * Daily cleanup task - remove stale cache entries.
     *
     * Scheduled via WordPress cron.
     *
     * @return void
     */
    public function daily_cleanup_task() {
        $this->load_verification_cache();
        $this->cleanup_verification_cache();
        $this->maybe_save_verification_cache();

        // Failure cache auto-expires via transient, but clean up old entries.
        $this->cleanup_failure_cache();
    }

    /**
     * Clean up expired and orphaned cache entries.
     *
     * Removes:
     * - Entries older than cache duration
     * - Entries for themes/plugins that are no longer installed
     *
     * @return void
     */
    private function cleanup_verification_cache() {
        $now = time();

        // Get list of installed themes and plugins.
        $installed_themes  = array_keys( wp_get_themes() );
        $installed_plugins = $this->get_installed_plugin_slugs();

        // Clean up themes.
        if ( isset( $this->verification_cache['themes'] ) && is_array( $this->verification_cache['themes'] ) ) {
            foreach ( $this->verification_cache['themes'] as $slug => $entry ) {
                $should_remove = false;

                // Remove if expired.
                if ( isset( $entry['checked_at'] ) ) {
                    $age = $now - (int) $entry['checked_at'];
                    if ( $age > STATICDELIVR_CACHE_DURATION ) {
                        $should_remove = true;
                    }
                }

                // Remove if theme no longer installed.
                if ( ! in_array( $slug, $installed_themes, true ) ) {
                    $should_remove = true;
                }

                if ( $should_remove ) {
                    unset( $this->verification_cache['themes'][ $slug ] );
                    $this->verification_cache_dirty = true;
                }
            }
        }

        // Clean up plugins.
        if ( isset( $this->verification_cache['plugins'] ) && is_array( $this->verification_cache['plugins'] ) ) {
            foreach ( $this->verification_cache['plugins'] as $slug => $entry ) {
                $should_remove = false;

                // Remove if expired.
                if ( isset( $entry['checked_at'] ) ) {
                    $age = $now - (int) $entry['checked_at'];
                    if ( $age > STATICDELIVR_CACHE_DURATION ) {
                        $should_remove = true;
                    }
                }

                // Remove if plugin no longer installed.
                if ( ! in_array( $slug, $installed_plugins, true ) ) {
                    $should_remove = true;
                }

                if ( $should_remove ) {
                    unset( $this->verification_cache['plugins'][ $slug ] );
                    $this->verification_cache_dirty = true;
                }
            }
        }

        $this->verification_cache['last_cleanup'] = $now;
        $this->verification_cache_dirty           = true;
    }

    /**
     * Clean up old failure cache entries.
     *
     * @return void
     */
    private function cleanup_failure_cache() {
        $this->load_failure_cache();

        $now     = time();
        $changed = false;

        foreach ( array( 'images', 'assets' ) as $type ) {
            if ( ! empty( $this->failure_cache[ $type ] ) ) {
                foreach ( $this->failure_cache[ $type ] as $key => $entry ) {
                    if ( isset( $entry['last'] ) ) {
                        $age = $now - (int) $entry['last'];
                        if ( $age > STATICDELIVR_FAILURE_CACHE_DURATION ) {
                            unset( $this->failure_cache[ $type ][ $key ] );
                            $changed = true;
                        }
                    }
                }
            }
        }

        if ( $changed ) {
            $this->failure_cache_dirty = true;
            $this->maybe_save_failure_cache();
        }
    }

    /**
     * Get list of installed plugin slugs (folder names).
     *
     * @return array List of plugin slugs.
     */
    private function get_installed_plugin_slugs() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $slugs       = array();

        foreach ( array_keys( $all_plugins ) as $plugin_file ) {
            if ( strpos( $plugin_file, '/' ) !== false ) {
                $slugs[] = dirname( $plugin_file );
            } else {
                // Single-file plugin like hello.php.
                $slugs[] = str_replace( '.php', '', $plugin_file );
            }
        }

        return array_unique( $slugs );
    }

    /**
     * Handle theme switch event.
     *
     * Clears cache for old theme to force re-verification on next load.
     *
     * @param string   $new_name  New theme name.
     * @param WP_Theme $new_theme New theme object.
     * @param WP_Theme $old_theme Old theme object.
     * @return void
     */
    public function on_theme_switch( $new_name, $new_theme, $old_theme ) {
        if ( $old_theme && $old_theme->exists() ) {
            $this->invalidate_cache_entry( 'theme', $old_theme->get_stylesheet() );
        }
        // Pre-verify new theme.
        if ( $new_theme && $new_theme->exists() ) {
            $this->is_asset_on_wporg( 'theme', $new_theme->get_stylesheet() );
        }
    }

    /**
     * Handle plugin activation.
     *
     * @param string $plugin       Plugin file path.
     * @param bool   $network_wide Whether activated network-wide.
     * @return void
     */
    public function on_plugin_activated( $plugin, $network_wide ) {
        $slug = $this->get_plugin_slug_from_file( $plugin );
        if ( $slug ) {
            // Pre-verify the plugin.
            $this->is_asset_on_wporg( 'plugin', $slug );
        }
    }

    /**
     * Handle plugin deactivation.
     *
     * @param string $plugin       Plugin file path.
     * @param bool   $network_wide Whether deactivated network-wide.
     * @return void
     */
    public function on_plugin_deactivated( $plugin, $network_wide ) {
        // Keep cache entry - plugin might be reactivated.
    }

    /**
     * Handle plugin deletion.
     *
     * @param string $plugin  Plugin file path.
     * @param bool   $deleted Whether deletion was successful.
     * @return void
     */
    public function on_plugin_deleted( $plugin, $deleted ) {
        if ( $deleted ) {
            $slug = $this->get_plugin_slug_from_file( $plugin );
            if ( $slug ) {
                $this->invalidate_cache_entry( 'plugin', $slug );
            }
        }
    }

    /**
     * Extract plugin slug from plugin file path.
     *
     * @param string $plugin_file Plugin file path (e.g., 'woocommerce/woocommerce.php').
     * @return string|null Plugin slug or null.
     */
    private function get_plugin_slug_from_file( $plugin_file ) {
        if ( strpos( $plugin_file, '/' ) !== false ) {
            return dirname( $plugin_file );
        }
        return str_replace( '.php', '', $plugin_file );
    }

    /**
     * Invalidate (remove) a cache entry.
     *
     * @param string $type Asset type: 'theme' or 'plugin'.
     * @param string $slug Asset slug.
     * @return void
     */
    private function invalidate_cache_entry( $type, $slug ) {
        $this->load_verification_cache();

        $key = ( 'theme' === $type ) ? 'themes' : 'plugins';

        if ( isset( $this->verification_cache[ $key ][ $slug ] ) ) {
            unset( $this->verification_cache[ $key ][ $slug ] );
            $this->verification_cache_dirty = true;
        }
    }

    /**
     * Get all verified assets for display in admin.
     *
     * @return array Verification data organized by type.
     */
    public function get_verification_summary() {
        $this->load_verification_cache();

        $summary = array(
            'themes'  => array(
                'cdn'   => array(), // On wordpress.org - served from CDN.
                'local' => array(), // Not on wordpress.org - served locally.
            ),
            'plugins' => array(
                'cdn'   => array(),
                'local' => array(),
            ),
        );

        // Process themes.
        $installed_themes = wp_get_themes();
        foreach ( $installed_themes as $slug => $theme ) {
            $parent_slug = $this->get_parent_theme_slug( $slug );
            $check_slug  = $parent_slug ? $parent_slug : $slug;

            $cached = isset( $this->verification_cache['themes'][ $check_slug ] )
                ? $this->verification_cache['themes'][ $check_slug ]
                : null;

            $info = array(
                'name'       => $theme->get( 'Name' ),
                'version'    => $theme->get( 'Version' ),
                'is_child'   => ! empty( $parent_slug ),
                'parent'     => $parent_slug,
                'checked_at' => $cached ? $cached['checked_at'] : null,
                'method'     => $cached ? $cached['method'] : null,
            );

            if ( $cached && $cached['on_wporg'] ) {
                $summary['themes']['cdn'][ $slug ] = $info;
            } else {
                $summary['themes']['local'][ $slug ] = $info;
            }
        }

        // Process plugins.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $slug = $this->get_plugin_slug_from_file( $plugin_file );
            if ( ! $slug ) {
                continue;
            }

            $cached = isset( $this->verification_cache['plugins'][ $slug ] )
                ? $this->verification_cache['plugins'][ $slug ]
                : null;

            $info = array(
                'name'       => $plugin_data['Name'],
                'version'    => $plugin_data['Version'],
                'file'       => $plugin_file,
                'checked_at' => $cached ? $cached['checked_at'] : null,
                'method'     => $cached ? $cached['method'] : null,
            );

            if ( $cached && $cached['on_wporg'] ) {
                $summary['plugins']['cdn'][ $slug ] = $info;
            } else {
                $summary['plugins']['local'][ $slug ] = $info;
            }
        }

        return $summary;
    }

    // =========================================================================
    // ADMIN INTERFACE
    // =========================================================================

    /**
     * Enqueue admin styles for settings page.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_styles( $hook ) {
        if ( 'settings_page_' . STATICDELIVR_PREFIX . 'cdn-settings' !== $hook ) {
            return;
        }

        wp_add_inline_style( 'wp-admin', $this->get_admin_styles() );
    }

    /**
     * Get admin CSS styles.
     *
     * @return string CSS styles.
     */
    private function get_admin_styles() {
        return '
            .staticdelivr-wrap {
                max-width: 900px;
            }
            .staticdelivr-status-bar {
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                padding: 12px 15px;
                margin: 15px 0 20px;
                display: flex;
                gap: 25px;
                flex-wrap: wrap;
                align-items: center;
            }
            .staticdelivr-status-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .staticdelivr-status-item .label {
                color: #50575e;
            }
            .staticdelivr-status-item .value {
                font-weight: 600;
            }
            .staticdelivr-status-item .value.active {
                color: #00a32a;
            }
            .staticdelivr-status-item .value.inactive {
                color: #b32d2e;
            }
            .staticdelivr-example {
                background: #f6f7f7;
                padding: 12px 15px;
                margin: 10px 0 0;
                font-family: Consolas, Monaco, monospace;
                font-size: 12px;
                overflow-x: auto;
                border-left: 4px solid #2271b1;
            }
            .staticdelivr-example code {
                background: none;
                padding: 0;
            }
            .staticdelivr-example .becomes {
                color: #2271b1;
                display: block;
                margin: 6px 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }
            .staticdelivr-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                margin-left: 8px;
            }
            .staticdelivr-badge-privacy {
                background: #d4edda;
                color: #155724;
            }
            .staticdelivr-badge-gdpr {
                background: #cce5ff;
                color: #004085;
            }
            .staticdelivr-badge-new {
                background: #fff3cd;
                color: #856404;
            }
            .staticdelivr-info-box {
                background: #f6f7f7;
                padding: 15px;
                margin: 15px 0;
                border-left: 4px solid #2271b1;
            }
            .staticdelivr-info-box h4 {
                margin-top: 0;
                color: #1d2327;
            }
            .staticdelivr-info-box ul {
                margin-bottom: 0;
            }
            .staticdelivr-assets-list {
                margin: 15px 0;
            }
            .staticdelivr-assets-list h4 {
                margin: 15px 0 10px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .staticdelivr-assets-list h4 .count {
                background: #dcdcde;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: normal;
            }
            .staticdelivr-assets-list ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            .staticdelivr-assets-list li {
                padding: 8px 12px;
                background: #fff;
                border: 1px solid #dcdcde;
                margin-bottom: -1px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .staticdelivr-assets-list li:first-child {
                border-radius: 4px 4px 0 0;
            }
            .staticdelivr-assets-list li:last-child {
                border-radius: 0 0 4px 4px;
            }
            .staticdelivr-assets-list li:only-child {
                border-radius: 4px;
            }
            .staticdelivr-assets-list .asset-name {
                font-weight: 500;
            }
            .staticdelivr-assets-list .asset-meta {
                font-size: 12px;
                color: #646970;
            }
            .staticdelivr-assets-list .asset-badge {
                font-size: 11px;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .staticdelivr-assets-list .asset-badge.cdn {
                background: #d4edda;
                color: #155724;
            }
            .staticdelivr-assets-list .asset-badge.local {
                background: #f8d7da;
                color: #721c24;
            }
            .staticdelivr-assets-list .asset-badge.child {
                background: #e2e3e5;
                color: #383d41;
            }
            .staticdelivr-empty-state {
                padding: 20px;
                text-align: center;
                color: #646970;
                font-style: italic;
            }
            .staticdelivr-failure-stats {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 15px;
                margin: 15px 0;
                border-radius: 4px;
            }
            .staticdelivr-failure-stats h4 {
                margin-top: 0;
            }
            .staticdelivr-failure-stats .stat-row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .staticdelivr-failure-stats .stat-row:last-child {
                border-bottom: none;
            }
            .staticdelivr-clear-cache-btn {
                margin-top: 10px;
            }
        ';
    }

    /**
     * Show activation notice.
     *
     * @return void
     */
    public function show_activation_notice() {
        if ( ! get_transient( STATICDELIVR_PREFIX . 'activation_notice' ) ) {
            return;
        }

        delete_transient( STATICDELIVR_PREFIX . 'activation_notice' );

        $settings_url = admin_url( 'options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings' );
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong><?php esc_html_e( 'StaticDelivr CDN is now active!', 'staticdelivr' ); ?></strong>
                <?php esc_html_e( 'Your site is already optimized with CDN delivery, image optimization, and privacy-first Google Fonts enabled by default.', 'staticdelivr' ); ?>
                <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'View Settings', 'staticdelivr' ); ?></a>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // SETTINGS & OPTIONS
    // =========================================================================

    /**
     * Check if image optimization is enabled.
     *
     * @return bool
     */
    private function is_image_optimization_enabled() {
        return (bool) get_option( STATICDELIVR_PREFIX . 'images_enabled', true );
    }

    /**
     * Check if assets (CSS/JS) optimization is enabled.
     *
     * @return bool
     */
    private function is_assets_optimization_enabled() {
        return (bool) get_option( STATICDELIVR_PREFIX . 'assets_enabled', true );
    }

    /**
     * Check if Google Fonts rewriting is enabled.
     *
     * @return bool
     */
    private function is_google_fonts_enabled() {
        return (bool) get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', true );
    }

    /**
     * Get image optimization quality setting.
     *
     * @return int
     */
    private function get_image_quality() {
        return (int) get_option( STATICDELIVR_PREFIX . 'image_quality', 80 );
    }

    /**
     * Get image optimization format setting.
     *
     * @return string
     */
    private function get_image_format() {
        return get_option( STATICDELIVR_PREFIX . 'image_format', 'webp' );
    }

    /**
     * Get the current WordPress version (cached).
     *
     * Extracts clean version number from development/RC versions.
     *
     * @return string The WordPress version (e.g., "6.9" or "6.9.1").
     */
    private function get_wp_version() {
        if ( null !== $this->wp_version_cache ) {
            return $this->wp_version_cache;
        }

        $raw_version = get_bloginfo( 'version' );

        // Extract just the version number from development versions.
        if ( preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $raw_version, $matches ) ) {
            $this->wp_version_cache = $matches[1];
        } else {
            $this->wp_version_cache = $raw_version;
        }

        return $this->wp_version_cache;
    }

    // =========================================================================
    // URL REWRITING - ASSETS (CSS/JS)
    // =========================================================================

    /**
     * Extract the clean WordPress path from a given URL path.
     *
     * @param string $path The original path.
     * @return string The extracted WordPress path or the original path.
     */
    private function extract_wp_path( $path ) {
        $wp_patterns = array( 'wp-includes/', 'wp-content/' );
        foreach ( $wp_patterns as $pattern ) {
            $index = strpos( $path, $pattern );
            if ( false !== $index ) {
                return substr( $path, $index );
            }
        }
        return $path;
    }

    /**
     * Get theme version by stylesheet (folder name), cached.
     *
     * @param string $theme_slug Theme folder name.
     * @return string Theme version or empty string.
     */
    private function get_theme_version( $theme_slug ) {
        $key = 'theme:' . $theme_slug;
        if ( isset( $this->version_cache[ $key ] ) ) {
            return $this->version_cache[ $key ];
        }
        $theme                      = wp_get_theme( $theme_slug );
        $version                    = (string) $theme->get( 'Version' );
        $this->version_cache[ $key ] = $version;
        return $version;
    }

    /**
     * Get plugin version by slug (folder name), cached.
     *
     * @param string $plugin_slug Plugin folder name.
     * @return string Plugin version or empty string.
     */
    private function get_plugin_version( $plugin_slug ) {
        $key = 'plugin:' . $plugin_slug;
        if ( isset( $this->version_cache[ $key ] ) ) {
            return $this->version_cache[ $key ];
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            if ( strpos( $plugin_file, $plugin_slug . '/' ) === 0 || $plugin_file === $plugin_slug . '.php' ) {
                $version                     = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
                $this->version_cache[ $key ] = $version;
                return $version;
            }
        }

        $this->version_cache[ $key ] = '';
        return '';
    }

    /**
     * Rewrite asset URL to use StaticDelivr CDN.
     *
     * Only rewrites URLs for assets that exist on wordpress.org.
     *
     * @param string $src    The original source URL.
     * @param string $handle The resource handle.
     * @return string The modified URL or original if not rewritable.
     */
    public function rewrite_url( $src, $handle ) {
        // Check if assets optimization is enabled.
        if ( ! $this->is_assets_optimization_enabled() ) {
            return $src;
        }

        $parsed_url = wp_parse_url( $src );

        // Extract the clean WordPress path.
        if ( ! isset( $parsed_url['path'] ) ) {
            return $src;
        }

        $clean_path = $this->extract_wp_path( $parsed_url['path'] );

        // Rewrite WordPress core files - always available on CDN.
        if ( strpos( $clean_path, 'wp-includes/' ) === 0 ) {
            $wp_version = $this->get_wp_version();
            $rewritten  = sprintf(
                '%s/wp/core/tags/%s/%s',
                STATICDELIVR_CDN_BASE,
                $wp_version,
                ltrim( $clean_path, '/' )
            );
            $this->remember_original_source( $handle, $src );
            return $rewritten;
        }

        // Rewrite theme and plugin URLs.
        if ( strpos( $clean_path, 'wp-content/' ) === 0 ) {
            $path_parts = explode( '/', $clean_path );

            if ( in_array( 'themes', $path_parts, true ) ) {
                return $this->maybe_rewrite_theme_url( $src, $handle, $path_parts );
            }

            if ( in_array( 'plugins', $path_parts, true ) ) {
                return $this->maybe_rewrite_plugin_url( $src, $handle, $path_parts );
            }
        }

        return $src;
    }

    /**
     * Attempt to rewrite a theme asset URL.
     *
     * Only rewrites if theme exists on wordpress.org.
     *
     * @param string $src        Original source URL.
     * @param string $handle     Resource handle.
     * @param array  $path_parts URL path parts.
     * @return string Rewritten URL or original.
     */
    private function maybe_rewrite_theme_url( $src, $handle, $path_parts ) {
        $themes_index = array_search( 'themes', $path_parts, true );
        $theme_slug   = isset( $path_parts[ $themes_index + 1 ] ) ? $path_parts[ $themes_index + 1 ] : '';

        if ( empty( $theme_slug ) ) {
            return $src;
        }

        // Check if theme is on wordpress.org.
        if ( ! $this->is_asset_on_wporg( 'theme', $theme_slug ) ) {
            return $src; // Not on wordpress.org - serve locally.
        }

        $version = $this->get_theme_version( $theme_slug );
        if ( empty( $version ) ) {
            return $src;
        }

        // For child themes, the URL already points to correct theme folder.
        // The is_asset_on_wporg check handles parent theme verification.
        $file_path = implode( '/', array_slice( $path_parts, $themes_index + 2 ) );

        $rewritten = sprintf(
            '%s/wp/themes/%s/%s/%s',
            STATICDELIVR_CDN_BASE,
            $theme_slug,
            $version,
            $file_path
        );

        $this->remember_original_source( $handle, $src );
        return $rewritten;
    }

    /**
     * Attempt to rewrite a plugin asset URL.
     *
     * Only rewrites if plugin exists on wordpress.org.
     *
     * @param string $src        Original source URL.
     * @param string $handle     Resource handle.
     * @param array  $path_parts URL path parts.
     * @return string Rewritten URL or original.
     */
    private function maybe_rewrite_plugin_url( $src, $handle, $path_parts ) {
        $plugins_index = array_search( 'plugins', $path_parts, true );
        $plugin_slug   = isset( $path_parts[ $plugins_index + 1 ] ) ? $path_parts[ $plugins_index + 1 ] : '';

        if ( empty( $plugin_slug ) ) {
            return $src;
        }

        // Check if plugin is on wordpress.org.
        if ( ! $this->is_asset_on_wporg( 'plugin', $plugin_slug ) ) {
            return $src; // Not on wordpress.org - serve locally.
        }

        $version = $this->get_plugin_version( $plugin_slug );
        if ( empty( $version ) ) {
            return $src;
        }

        $file_path = implode( '/', array_slice( $path_parts, $plugins_index + 2 ) );

        $rewritten = sprintf(
            '%s/wp/plugins/%s/tags/%s/%s',
            STATICDELIVR_CDN_BASE,
            $plugin_slug,
            $version,
            $file_path
        );

        $this->remember_original_source( $handle, $src );
        return $rewritten;
    }

    /**
     * Track the original asset URL for fallback purposes.
     *
     * @param string $handle Asset handle.
     * @param string $src    Original URL.
     * @return void
     */
    private function remember_original_source( $handle, $src ) {
        if ( empty( $handle ) || empty( $src ) ) {
            return;
        }
        if ( ! isset( $this->original_sources[ $handle ] ) ) {
            $this->original_sources[ $handle ] = $src;
        }
    }

    /**
     * Inject data-original-src attribute into rewritten script tags.
     *
     * @param string $tag    Complete script tag HTML.
     * @param string $handle Asset handle.
     * @param string $src    Final script src.
     * @return string Modified script tag.
     */
    public function inject_script_original_attribute( $tag, $handle, $src ) {
        if ( empty( $this->original_sources[ $handle ] ) || strpos( $tag, 'data-original-src=' ) !== false ) {
            return $tag;
        }

        $original = esc_attr( $this->original_sources[ $handle ] );
        return preg_replace( '/(<script\b)/i', '$1 data-original-src="' . $original . '"', $tag, 1 );
    }

    /**
     * Inject data-original-href attribute into rewritten stylesheet link tags.
     *
     * @param string $html   Complete link tag HTML.
     * @param string $handle Asset handle.
     * @param string $href   Final stylesheet href.
     * @param string $media  Media attribute.
     * @return string Modified link tag.
     */
    public function inject_style_original_attribute( $html, $handle, $href, $media ) {
        if ( empty( $this->original_sources[ $handle ] ) || strpos( $html, 'data-original-href=' ) !== false ) {
            return $html;
        }

        $original = esc_attr( $this->original_sources[ $handle ] );
        return str_replace( '<link', '<link data-original-href="' . $original . '"', $html );
    }

    // =========================================================================
    // IMAGE OPTIMIZATION
    // =========================================================================

    /**
     * Check if a URL is routable from the internet.
     *
     * Localhost and private IPs cannot be fetched by the CDN.
     *
     * @param string $url URL to check.
     * @return bool True if URL is publicly accessible.
     */
    private function is_url_routable( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );

        if ( empty( $host ) ) {
            return false;
        }

        // Check for localhost variations.
        $localhost_patterns = array(
            'localhost',
            '127.0.0.1',
            '::1',
            '.local',
            '.test',
            '.dev',
            '.localhost',
        );

        foreach ( $localhost_patterns as $pattern ) {
            if ( $host === $pattern || substr( $host, -strlen( $pattern ) ) === $pattern ) {
                return false;
            }
        }

        // Check for private IP ranges.
        $ip = gethostbyname( $host );
        if ( $ip !== $host ) {
            // Check if IP is in private range.
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build StaticDelivr image CDN URL.
     *
     * @param string   $original_url The original image URL.
     * @param int|null $width        Optional width.
     * @param int|null $height       Optional height.
     * @return string The CDN URL or original if not optimizable.
     */
    private function build_image_cdn_url( $original_url, $width = null, $height = null ) {
        if ( empty( $original_url ) ) {
            return $original_url;
        }

        // Don't rewrite if already a StaticDelivr URL.
        if ( strpos( $original_url, 'cdn.staticdelivr.com' ) !== false ) {
            return $original_url;
        }

        // Ensure absolute URL.
        if ( strpos( $original_url, '//' ) === 0 ) {
            $original_url = 'https:' . $original_url;
        } elseif ( strpos( $original_url, '/' ) === 0 ) {
            $original_url = home_url( $original_url );
        }

        // Check if URL is routable (not localhost/private).
        if ( ! $this->is_url_routable( $original_url ) ) {
            return $original_url;
        }

        // Check failure cache.
        if ( $this->is_image_blocked( $original_url ) ) {
            return $original_url;
        }

        // Validate it's an image URL.
        $extension = strtolower( pathinfo( wp_parse_url( $original_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->image_extensions, true ) ) {
            return $original_url;
        }

        // Build CDN URL with optimization parameters.
        $params = array();

        // URL parameter is required.
        $params['url'] = $original_url;

        $quality = $this->get_image_quality();
        if ( $quality && $quality < 100 ) {
            $params['q'] = $quality;
        }

        $format = $this->get_image_format();
        if ( $format && 'auto' !== $format ) {
            $params['format'] = $format;
        }

        if ( $width ) {
            $params['w'] = (int) $width;
        }

        if ( $height ) {
            $params['h'] = (int) $height;
        }

        return STATICDELIVR_IMG_CDN_BASE . '?' . http_build_query( $params );
    }

    /**
     * Rewrite attachment image src array.
     *
     * @param array|false $image         Image data array or false.
     * @param int         $attachment_id Attachment ID.
     * @param string|int[]$size          Requested image size.
     * @param bool        $icon          Whether to use icon.
     * @return array|false
     */
    public function rewrite_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $this->is_image_optimization_enabled() || ! $image || ! is_array( $image ) ) {
            return $image;
        }

        $original_url = $image[0];
        $width        = isset( $image[1] ) ? $image[1] : null;
        $height       = isset( $image[2] ) ? $image[2] : null;

        $image[0] = $this->build_image_cdn_url( $original_url, $width, $height );

        return $image;
    }

    /**
     * Rewrite image srcset URLs.
     *
     * @param array  $sources       Array of image sources.
     * @param array  $size_array    Array of width and height.
     * @param string $image_src     The src attribute.
     * @param array  $image_meta    Image metadata.
     * @param int    $attachment_id Attachment ID.
     * @return array
     */
    public function rewrite_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->is_image_optimization_enabled() || ! is_array( $sources ) ) {
            return $sources;
        }

        foreach ( $sources as $width => &$source ) {
            if ( isset( $source['url'] ) ) {
                $source['url'] = $this->build_image_cdn_url( $source['url'], (int) $width );
            }
        }

        return $sources;
    }

    /**
     * Rewrite attachment URL.
     *
     * @param string $url           The attachment URL.
     * @param int    $attachment_id Attachment ID.
     * @return string
     */
    public function rewrite_attachment_url( $url, $attachment_id ) {
        if ( ! $this->is_image_optimization_enabled() ) {
            return $url;
        }

        // Check if it's an image attachment.
        $mime_type = get_post_mime_type( $attachment_id );
        if ( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
            return $url;
        }

        return $this->build_image_cdn_url( $url );
    }

    /**
     * Rewrite image URLs in post content.
     *
     * @param string $content The post content.
     * @return string
     */
    public function rewrite_content_images( $content ) {
        if ( ! $this->is_image_optimization_enabled() || empty( $content ) ) {
            return $content;
        }

        // Match img tags.
        $content = preg_replace_callback( '/<img[^>]+>/i', array( $this, 'rewrite_img_tag' ), $content );

        // Match background-image in inline styles.
        $content = preg_replace_callback(
            '/background(-image)?\s*:\s*url\s*\([\'"]?([^\'")\s]+)[\'"]?\)/i',
            array( $this, 'rewrite_background_image' ),
            $content
        );

        return $content;
    }

    /**
     * Rewrite a single img tag.
     *
     * @param array $matches Regex matches.
     * @return string
     */
    private function rewrite_img_tag( $matches ) {
        $img_tag = $matches[0];

        // Skip if already processed or is a StaticDelivr URL.
        if ( strpos( $img_tag, 'cdn.staticdelivr.com' ) !== false ) {
            return $img_tag;
        }

        // Skip data URIs and SVGs.
        if ( preg_match( '/src=["\']data:/i', $img_tag ) || preg_match( '/\.svg["\'\s>]/i', $img_tag ) ) {
            return $img_tag;
        }

        // Extract width and height if present.
        $width  = null;
        $height = null;

        if ( preg_match( '/width=["\']?(\d+)/i', $img_tag, $w_match ) ) {
            $width = (int) $w_match[1];
        }
        if ( preg_match( '/height=["\']?(\d+)/i', $img_tag, $h_match ) ) {
            $height = (int) $h_match[1];
        }

        // Rewrite src attribute.
        $img_tag = preg_replace_callback(
            '/src=["\']([^"\']+)["\']/i',
            function ( $src_match ) use ( $width, $height ) {
                $original_src = $src_match[1];
                $cdn_src      = $this->build_image_cdn_url( $original_src, $width, $height );

                // Only add data-original-src if URL was actually rewritten.
                if ( $cdn_src !== $original_src ) {
                    return 'src="' . esc_attr( $cdn_src ) . '" data-original-src="' . esc_attr( $original_src ) . '"';
                }
                return $src_match[0];
            },
            $img_tag
        );

        // Rewrite srcset attribute.
        $img_tag = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/i',
            function ( $srcset_match ) {
                $srcset      = $srcset_match[1];
                $sources     = explode( ',', $srcset );
                $new_sources = array();

                foreach ( $sources as $source ) {
                    $source = trim( $source );
                    if ( preg_match( '/^(.+?)\s+(\d+w|\d+x)$/i', $source, $parts ) ) {
                        $url        = trim( $parts[1] );
                        $descriptor = $parts[2];

                        $width = null;
                        if ( preg_match( '/(\d+)w/', $descriptor, $w_match ) ) {
                            $width = (int) $w_match[1];
                        }

                        $cdn_url       = $this->build_image_cdn_url( $url, $width );
                        $new_sources[] = $cdn_url . ' ' . $descriptor;
                    } else {
                        $new_sources[] = $source;
                    }
                }

                return 'srcset="' . esc_attr( implode( ', ', $new_sources ) ) . '"';
            },
            $img_tag
        );

        return $img_tag;
    }

    /**
     * Rewrite background-image URL.
     *
     * @param array $matches Regex matches.
     * @return string
     */
    private function rewrite_background_image( $matches ) {
        $full_match = $matches[0];
        $url        = $matches[2];

        // Skip if already a CDN URL or data URI.
        if ( strpos( $url, 'cdn.staticdelivr.com' ) !== false || strpos( $url, 'data:' ) === 0 ) {
            return $full_match;
        }

        $cdn_url = $this->build_image_cdn_url( $url );
        return str_replace( $url, $cdn_url, $full_match );
    }

    /**
     * Rewrite post thumbnail HTML.
     *
     * @param string       $html         The thumbnail HTML.
     * @param int          $post_id      Post ID.
     * @param int          $thumbnail_id Thumbnail attachment ID.
     * @param string|int[] $size         Image size.
     * @param string|array $attr         Image attributes.
     * @return string
     */
    public function rewrite_thumbnail_html( $html, $post_id, $thumbnail_id, $size, $attr ) {
        if ( ! $this->is_image_optimization_enabled() || empty( $html ) ) {
            return $html;
        }

        return $this->rewrite_img_tag( array( $html ) );
    }

    // =========================================================================
    // GOOGLE FONTS
    // =========================================================================

    /**
     * Check if a URL is a Google Fonts URL.
     *
     * @param string $url The URL to check.
     * @return bool
     */
    private function is_google_fonts_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }
        return ( strpos( $url, 'fonts.googleapis.com' ) !== false || strpos( $url, 'fonts.gstatic.com' ) !== false );
    }

    /**
     * Rewrite Google Fonts URL to use StaticDelivr proxy.
     *
     * @param string $url The original URL.
     * @return string The rewritten URL or original.
     */
    private function rewrite_google_fonts_url( $url ) {
        if ( empty( $url ) ) {
            return $url;
        }

        // Don't rewrite if already a StaticDelivr URL.
        if ( strpos( $url, 'cdn.staticdelivr.com' ) !== false ) {
            return $url;
        }

        // Rewrite fonts.googleapis.com to StaticDelivr.
        if ( strpos( $url, 'fonts.googleapis.com' ) !== false ) {
            return str_replace( 'fonts.googleapis.com', 'cdn.staticdelivr.com/gfonts', $url );
        }

        // Rewrite fonts.gstatic.com to StaticDelivr (font files).
        if ( strpos( $url, 'fonts.gstatic.com' ) !== false ) {
            return str_replace( 'fonts.gstatic.com', 'cdn.staticdelivr.com/gstatic-fonts', $url );
        }

        return $url;
    }

    /**
     * Rewrite enqueued Google Fonts stylesheets.
     *
     * @param string $src    The stylesheet source URL.
     * @param string $handle The stylesheet handle.
     * @return string
     */
    public function rewrite_google_fonts_enqueued( $src, $handle ) {
        if ( ! $this->is_google_fonts_enabled() ) {
            return $src;
        }

        if ( $this->is_google_fonts_url( $src ) ) {
            return $this->rewrite_google_fonts_url( $src );
        }

        return $src;
    }

    /**
     * Filter resource hints to update Google Fonts preconnect/prefetch.
     *
     * @param array  $urls          Array of URLs.
     * @param string $relation_type The relation type.
     * @return array
     */
    public function filter_resource_hints( $urls, $relation_type ) {
        if ( ! $this->is_google_fonts_enabled() ) {
            return $urls;
        }

        if ( 'dns-prefetch' !== $relation_type && 'preconnect' !== $relation_type ) {
            return $urls;
        }

        $staticdelivr_added = false;

        foreach ( $urls as $key => $url ) {
            $href = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;

            if ( strpos( $href, 'fonts.googleapis.com' ) !== false ||
                strpos( $href, 'fonts.gstatic.com' ) !== false ) {
                unset( $urls[ $key ] );
                $staticdelivr_added = true;
            }
        }

        // Add StaticDelivr preconnect if we removed Google Fonts hints.
        if ( $staticdelivr_added ) {
            if ( 'preconnect' === $relation_type ) {
                $urls[] = array(
                    'href'        => STATICDELIVR_CDN_BASE,
                    'crossorigin' => 'anonymous',
                );
            } else {
                $urls[] = STATICDELIVR_CDN_BASE;
            }
        }

        return array_values( $urls );
    }

    /**
     * Start output buffering to catch Google Fonts in HTML output.
     *
     * @return void
     */
    public function start_google_fonts_output_buffer() {
        if ( ! $this->is_google_fonts_enabled() ) {
            return;
        }

        // Don't buffer non-HTML requests.
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return;
        }

        if ( is_feed() ) {
            return;
        }

        $this->output_buffering_started = true;
        ob_start();
    }

    /**
     * End output buffering and process Google Fonts URLs.
     *
     * @return void
     */
    public function end_google_fonts_output_buffer() {
        if ( ! $this->output_buffering_started ) {
            return;
        }

        $html = ob_get_clean();

        if ( ! empty( $html ) ) {
            echo $this->process_google_fonts_buffer( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Process the output buffer to rewrite Google Fonts URLs.
     *
     * @param string $html The HTML output.
     * @return string
     */
    public function process_google_fonts_buffer( $html ) {
        if ( empty( $html ) ) {
            return $html;
        }

        $html = str_replace( 'fonts.googleapis.com', 'cdn.staticdelivr.com/gfonts', $html );
        $html = str_replace( 'fonts.gstatic.com', 'cdn.staticdelivr.com/gstatic-fonts', $html );

        return $html;
    }

    // =========================================================================
    // FALLBACK SYSTEM
    // =========================================================================

    /**
     * Inject the fallback script directly in the head.
     *
     * @return void
     */
    public function inject_fallback_script_early() {
        if ( $this->fallback_script_enqueued ||
            ( ! $this->is_assets_optimization_enabled() && ! $this->is_image_optimization_enabled() ) ) {
            return;
        }

        $this->fallback_script_enqueued = true;
        $handle                         = STATICDELIVR_PREFIX . 'fallback';
        $inline                         = $this->get_fallback_inline_script();

        if ( ! wp_script_is( $handle, 'registered' ) ) {
            wp_register_script( $handle, '', array(), STATICDELIVR_VERSION, false );
        }

        wp_add_inline_script( $handle, $inline, 'before' );
        wp_enqueue_script( $handle );
    }

    /**
     * Get the fallback JavaScript code.
     *
     * @return string
     */
    private function get_fallback_inline_script() {
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'staticdelivr_failure_report' );

        $script = <<<JS
(function(){
    var SD_DEBUG = false;
    var SD_AJAX_URL = '%s';
    var SD_NONCE = '%s';

    function log() {
        if (SD_DEBUG && console && console.log) {
            console.log.apply(console, ['[StaticDelivr]'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    function reportFailure(type, url, original) {
        try {
            var data = new FormData();
            data.append('action', 'staticdelivr_report_failure');
            data.append('nonce', SD_NONCE);
            data.append('type', type);
            data.append('url', url);
            data.append('original', original || '');

            if (navigator.sendBeacon) {
                navigator.sendBeacon(SD_AJAX_URL, data);
            } else {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', SD_AJAX_URL, true);
                xhr.send(data);
            }
            log('Reported failure:', type, url);
        } catch(e) {
            log('Failed to report:', e);
        }
    }

    function copyAttributes(from, to) {
        if (!from || !to || !from.attributes) return;
        for (var i = 0; i < from.attributes.length; i++) {
            var attr = from.attributes[i];
            if (!attr || !attr.name) continue;
            if (attr.name === 'src' || attr.name === 'href' || attr.name === 'data-original-src' || attr.name === 'data-original-href') continue;
            try {
                to.setAttribute(attr.name, attr.value);
            } catch(e) {}
        }
    }

    function extractOriginalFromCdnUrl(cdnUrl) {
        if (!cdnUrl) return null;
        if (cdnUrl.indexOf('cdn.staticdelivr.com') === -1) return null;
        try {
            var urlObj = new URL(cdnUrl);
            var originalUrl = urlObj.searchParams.get('url');
            if (originalUrl) {
                log('Extracted original URL from query param:', originalUrl);
                return originalUrl;
            }
        } catch(e) {
            log('Failed to parse CDN URL:', cdnUrl, e);
        }
        return null;
    }

    function handleError(event) {
        var el = event.target || event.srcElement;
        if (!el) return;

        var tagName = el.tagName ? el.tagName.toUpperCase() : '';
        if (!tagName) return;

        // Only handle elements we care about
        if (tagName !== 'SCRIPT' && tagName !== 'LINK' && tagName !== 'IMG') return;

        // Get the failed URL
        var failedUrl = '';
        if (tagName === 'IMG') failedUrl = el.src || el.currentSrc || '';
        else if (tagName === 'SCRIPT') failedUrl = el.src || '';
        else if (tagName === 'LINK') failedUrl = el.href || '';

        // Only handle StaticDelivr URLs
        if (failedUrl.indexOf('cdn.staticdelivr.com') === -1) return;

        log('Caught error on:', tagName, failedUrl);

        // Prevent double-processing
        if (el.getAttribute && el.getAttribute('data-sd-fallback') === 'done') return;

        // Get original URL
        var original = el.getAttribute('data-original-src') || el.getAttribute('data-original-href');
        if (!original) original = extractOriginalFromCdnUrl(failedUrl);

        if (!original) {
            log('Could not determine original URL for:', failedUrl);
            return;
        }

        el.setAttribute('data-sd-fallback', 'done');
        log('Falling back to origin:', tagName, original);

        // Report the failure
        var reportType = (tagName === 'IMG') ? 'image' : 'asset';
        reportFailure(reportType, failedUrl, original);

        if (tagName === 'SCRIPT') {
            var newScript = document.createElement('script');
            newScript.src = original;
            newScript.async = el.async;
            newScript.defer = el.defer;
            if (el.type) newScript.type = el.type;
            if (el.noModule) newScript.noModule = true;
            if (el.crossOrigin) newScript.crossOrigin = el.crossOrigin;
            copyAttributes(el, newScript);
            if (el.parentNode) {
                el.parentNode.insertBefore(newScript, el.nextSibling);
                el.parentNode.removeChild(el);
            }
            log('Script fallback complete:', original);

        } else if (tagName === 'LINK') {
            el.href = original;
            log('Stylesheet fallback complete:', original);

        } else if (tagName === 'IMG') {
            // Handle srcset first
            if (el.srcset) {
                var newSrcset = el.srcset.split(',').map(function(entry) {
                    var parts = entry.trim().split(/\s+/);
                    var url = parts[0];
                    var descriptor = parts.slice(1).join(' ');
                    var extracted = extractOriginalFromCdnUrl(url);
                    if (extracted) url = extracted;
                    return descriptor ? url + ' ' + descriptor : url;
                }).join(', ');
                el.srcset = newSrcset;
            }
            el.src = original;
            log('Image fallback complete:', original);
        }
    }

    // Capture errors in capture phase
    window.addEventListener('error', handleError, true);

    log('Fallback script initialized (v%s)');
})();
JS;

        return sprintf( $script, esc_js( $ajax_url ), esc_js( $nonce ), STATICDELIVR_VERSION );
    }

    // =========================================================================
    // SETTINGS PAGE
    // =========================================================================

    /**
     * Add settings page to WordPress admin.
     *
     * @return void
     */
    public function add_settings_page() {
        add_options_page(
            __( 'StaticDelivr CDN Settings', 'staticdelivr' ),
            __( 'StaticDelivr CDN', 'staticdelivr' ),
            'manage_options',
            STATICDELIVR_PREFIX . 'cdn-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'assets_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => true,
            )
        );

        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'images_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => true,
            )
        );

        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'image_quality',
            array(
                'type'              => 'integer',
                'sanitize_callback' => array( $this, 'sanitize_image_quality' ),
                'default'           => 80,
            )
        );

        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'image_format',
            array(
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_image_format' ),
                'default'           => 'webp',
            )
        );

        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'google_fonts_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => true,
            )
        );
    }

    /**
     * Sanitize image quality value.
     *
     * @param mixed $value The input value.
     * @return int
     */
    public function sanitize_image_quality( $value ) {
        $quality = absint( $value );
        return max( 1, min( 100, $quality ) );
    }

    /**
     * Sanitize image format value.
     *
     * @param mixed $value The input value.
     * @return string
     */
    public function sanitize_image_format( $value ) {
        $allowed_formats = array( 'auto', 'webp', 'avif', 'jpeg', 'png' );
        return in_array( $value, $allowed_formats, true ) ? $value : 'webp';
    }

    /**
     * Handle clear failure cache action.
     *
     * @return void
     */
    private function handle_clear_failure_cache() {
        if ( isset( $_POST['staticdelivr_clear_failure_cache'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'staticdelivr_clear_failure_cache' ) ) {
            $this->clear_failure_cache();
            add_settings_error(
                STATICDELIVR_PREFIX . 'cdn_settings',
                'cache_cleared',
                __( 'Failure cache cleared successfully.', 'staticdelivr' ),
                'success'
            );
        }
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        // Handle cache clear action.
        $this->handle_clear_failure_cache();

        $assets_enabled       = get_option( STATICDELIVR_PREFIX . 'assets_enabled', true );
        $images_enabled       = get_option( STATICDELIVR_PREFIX . 'images_enabled', true );
        $image_quality        = get_option( STATICDELIVR_PREFIX . 'image_quality', 80 );
        $image_format         = get_option( STATICDELIVR_PREFIX . 'image_format', 'webp' );
        $google_fonts_enabled = get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', true );
        $site_url             = home_url();
        $wp_version           = $this->get_wp_version();
        $verification_summary = $this->get_verification_summary();
        $failure_stats        = $this->get_failure_stats();
        ?>
        <div class="wrap staticdelivr-wrap">
            <h1><?php esc_html_e( 'StaticDelivr CDN', 'staticdelivr' ); ?></h1>
            <p><?php esc_html_e( 'Optimize your WordPress site by delivering assets through the', 'staticdelivr' ); ?>
                <a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">StaticDelivr CDN</a>.
            </p>

            <?php settings_errors(); ?>

            <!-- Status Bar -->
            <div class="staticdelivr-status-bar">
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'WordPress:', 'staticdelivr' ); ?></span>
                    <span class="value"><?php echo esc_html( $wp_version ); ?></span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'Assets CDN:', 'staticdelivr' ); ?></span>
                    <span class="value <?php echo $assets_enabled ? 'active' : 'inactive'; ?>">
                        <?php echo $assets_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
                    </span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'Images:', 'staticdelivr' ); ?></span>
                    <span class="value <?php echo $images_enabled ? 'active' : 'inactive'; ?>">
                        <?php echo $images_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
                    </span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'Google Fonts:', 'staticdelivr' ); ?></span>
                    <span class="value <?php echo $google_fonts_enabled ? 'active' : 'inactive'; ?>">
                        <?php echo $google_fonts_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
                    </span>
                </div>
                <?php if ( $images_enabled ) : ?>
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'Quality:', 'staticdelivr' ); ?></span>
                    <span class="value"><?php echo esc_html( $image_quality ); ?>%</span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label"><?php esc_html_e( 'Format:', 'staticdelivr' ); ?></span>
                    <span class="value"><?php echo esc_html( strtoupper( $image_format ) ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( STATICDELIVR_PREFIX . 'cdn_settings' ); ?>

                <h2 class="title">
                    <?php esc_html_e( 'Assets Optimization (CSS & JavaScript)', 'staticdelivr' ); ?>
                    <span class="staticdelivr-badge staticdelivr-badge-new"><?php esc_html_e( 'Smart Detection', 'staticdelivr' ); ?></span>
                </h2>
                <p class="description"><?php esc_html_e( 'Rewrite URLs of WordPress core files, themes, and plugins to use StaticDelivr CDN. Only assets from wordpress.org are served via CDN - custom themes and plugins are automatically detected and served locally.', 'staticdelivr' ); ?></p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable Assets CDN', 'staticdelivr' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'assets_enabled' ); ?>" value="1" <?php checked( 1, $assets_enabled ); ?> />
                                <?php esc_html_e( 'Enable CDN for CSS & JavaScript files', 'staticdelivr' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Serves WordPress core, theme, and plugin assets from StaticDelivr CDN for faster loading.', 'staticdelivr' ); ?></p>
                            <div class="staticdelivr-example">
                                <code><?php echo esc_html( $site_url ); ?>/wp-includes/js/jquery/jquery.min.js</code>
                                <span class="becomes">→</span>
                                <code><?php echo esc_html( STATICDELIVR_CDN_BASE ); ?>/wp/core/tags/<?php echo esc_html( $wp_version ); ?>/wp-includes/js/jquery/jquery.min.js</code>
                            </div>
                        </td>
                    </tr>
                </table>

                <!-- Asset Verification Summary -->
                <?php if ( $assets_enabled ) : ?>
                <div class="staticdelivr-assets-list">
                    <h4>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'Themes via CDN', 'staticdelivr' ); ?>
                        <span class="count"><?php echo count( $verification_summary['themes']['cdn'] ); ?></span>
                    </h4>
                    <?php if ( ! empty( $verification_summary['themes']['cdn'] ) ) : ?>
                    <ul>
                        <?php foreach ( $verification_summary['themes']['cdn'] as $slug => $info ) : ?>
                        <li>
                            <div>
                                <span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
                                <span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
                                <?php if ( $info['is_child'] ) : ?>
                                    <span class="asset-badge child"><?php esc_html_e( 'Child of', 'staticdelivr' ); ?> <?php echo esc_html( $info['parent'] ); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="asset-badge cdn"><?php esc_html_e( 'CDN', 'staticdelivr' ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else : ?>
                    <p class="staticdelivr-empty-state"><?php esc_html_e( 'No themes from wordpress.org detected.', 'staticdelivr' ); ?></p>
                    <?php endif; ?>

                    <h4>
                        <span class="dashicons dashicons-admin-home" style="color: #646970;"></span>
                        <?php esc_html_e( 'Themes Served Locally', 'staticdelivr' ); ?>
                        <span class="count"><?php echo count( $verification_summary['themes']['local'] ); ?></span>
                    </h4>
                    <?php if ( ! empty( $verification_summary['themes']['local'] ) ) : ?>
                    <ul>
                        <?php foreach ( $verification_summary['themes']['local'] as $slug => $info ) : ?>
                        <li>
                            <div>
                                <span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
                                <span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
                                <?php if ( $info['is_child'] ) : ?>
                                    <span class="asset-badge child"><?php esc_html_e( 'Child Theme', 'staticdelivr' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="asset-badge local"><?php esc_html_e( 'Local', 'staticdelivr' ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else : ?>
                    <p class="staticdelivr-empty-state"><?php esc_html_e( 'All themes are served via CDN.', 'staticdelivr' ); ?></p>
                    <?php endif; ?>

                    <h4>
                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                        <?php esc_html_e( 'Plugins via CDN', 'staticdelivr' ); ?>
                        <span class="count"><?php echo count( $verification_summary['plugins']['cdn'] ); ?></span>
                    </h4>
                    <?php if ( ! empty( $verification_summary['plugins']['cdn'] ) ) : ?>
                    <ul>
                        <?php foreach ( $verification_summary['plugins']['cdn'] as $slug => $info ) : ?>
                        <li>
                            <div>
                                <span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
                                <span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
                            </div>
                            <span class="asset-badge cdn"><?php esc_html_e( 'CDN', 'staticdelivr' ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else : ?>
                    <p class="staticdelivr-empty-state"><?php esc_html_e( 'No plugins from wordpress.org detected.', 'staticdelivr' ); ?></p>
                    <?php endif; ?>

                    <h4>
                        <span class="dashicons dashicons-admin-home" style="color: #646970;"></span>
                        <?php esc_html_e( 'Plugins Served Locally', 'staticdelivr' ); ?>
                        <span class="count"><?php echo count( $verification_summary['plugins']['local'] ); ?></span>
                    </h4>
                    <?php if ( ! empty( $verification_summary['plugins']['local'] ) ) : ?>
                    <ul>
                        <?php foreach ( $verification_summary['plugins']['local'] as $slug => $info ) : ?>
                        <li>
                            <div>
                                <span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
                                <span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
                            </div>
                            <span class="asset-badge local"><?php esc_html_e( 'Local', 'staticdelivr' ); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else : ?>
                    <p class="staticdelivr-empty-state"><?php esc_html_e( 'All plugins are served via CDN.', 'staticdelivr' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="staticdelivr-info-box">
                    <h4><?php esc_html_e( 'How Smart Detection Works', 'staticdelivr' ); ?></h4>
                    <ul>
                        <li><strong><?php esc_html_e( 'WordPress.org Verification', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'The plugin checks if each theme/plugin exists on wordpress.org before attempting to serve it via CDN.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'Custom Themes/Plugins', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Assets from custom or premium themes/plugins are automatically served from your server.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'Child Themes', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Child themes use the parent theme verification - if the parent is on wordpress.org, assets load via CDN.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'Cached Results', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Verification results are cached for 7 days to ensure fast page loads.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'Failure Memory', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'If a CDN resource fails to load, the plugin remembers and serves locally for 24 hours.', 'staticdelivr' ); ?></li>
                    </ul>
                </div>
                <?php endif; ?>

                <h2 class="title"><?php esc_html_e( 'Image Optimization', 'staticdelivr' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Automatically optimize and deliver images through StaticDelivr CDN. This can dramatically reduce image file sizes (e.g., 2MB → 20KB) and improve loading times.', 'staticdelivr' ); ?></p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable Image Optimization', 'staticdelivr' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'images_enabled' ); ?>" value="1" <?php checked( 1, $images_enabled ); ?> id="staticdelivr-images-toggle" />
                                <?php esc_html_e( 'Enable CDN for images', 'staticdelivr' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Optimizes and delivers all images through StaticDelivr CDN with automatic format conversion and compression.', 'staticdelivr' ); ?></p>
                            <div class="staticdelivr-example">
                                <code><?php echo esc_html( $site_url ); ?>/wp-content/uploads/photo.jpg (2MB)</code>
                                <span class="becomes">→</span>
                                <code><?php echo esc_html( STATICDELIVR_IMG_CDN_BASE ); ?>?url=...&amp;q=80&amp;format=webp (~20KB)</code>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top" id="staticdelivr-quality-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
                        <th scope="row"><?php esc_html_e( 'Image Quality', 'staticdelivr' ); ?></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'image_quality' ); ?>" value="<?php echo esc_attr( $image_quality ); ?>" min="1" max="100" step="1" class="small-text" <?php echo $images_enabled ? '' : 'disabled'; ?> />
                            <p class="description"><?php esc_html_e( 'Quality level for optimized images (1-100). Lower values = smaller files. Recommended: 75-85.', 'staticdelivr' ); ?></p>
                        </td>
                    </tr>
                    <tr valign="top" id="staticdelivr-format-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
                        <th scope="row"><?php esc_html_e( 'Image Format', 'staticdelivr' ); ?></th>
                        <td>
                            <select name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'image_format' ); ?>" <?php echo $images_enabled ? '' : 'disabled'; ?>>
                                <option value="auto" <?php selected( $image_format, 'auto' ); ?>><?php esc_html_e( 'Auto (Best for browser)', 'staticdelivr' ); ?></option>
                                <option value="webp" <?php selected( $image_format, 'webp' ); ?>><?php esc_html_e( 'WebP (Recommended)', 'staticdelivr' ); ?></option>
                                <option value="avif" <?php selected( $image_format, 'avif' ); ?>><?php esc_html_e( 'AVIF (Best compression)', 'staticdelivr' ); ?></option>
                                <option value="jpeg" <?php selected( $image_format, 'jpeg' ); ?>><?php esc_html_e( 'JPEG', 'staticdelivr' ); ?></option>
                                <option value="png" <?php selected( $image_format, 'png' ); ?>><?php esc_html_e( 'PNG', 'staticdelivr' ); ?></option>
                            </select>
                            <p class="description">
                                <strong>WebP</strong>: <?php esc_html_e( 'Great compression, widely supported.', 'staticdelivr' ); ?><br>
                                <strong>AVIF</strong>: <?php esc_html_e( 'Best compression, newer format.', 'staticdelivr' ); ?><br>
                                <strong>Auto</strong>: <?php esc_html_e( 'Automatically selects best format based on browser support.', 'staticdelivr' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">
                    <?php esc_html_e( 'Google Fonts (Privacy-First)', 'staticdelivr' ); ?>
                    <span class="staticdelivr-badge staticdelivr-badge-privacy"><?php esc_html_e( 'Privacy', 'staticdelivr' ); ?></span>
                    <span class="staticdelivr-badge staticdelivr-badge-gdpr"><?php esc_html_e( 'GDPR Compliant', 'staticdelivr' ); ?></span>
                </h2>
                <p class="description"><?php esc_html_e( 'Proxy Google Fonts through StaticDelivr CDN to strip tracking cookies and improve privacy.', 'staticdelivr' ); ?></p>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php esc_html_e( 'Enable Google Fonts Proxy', 'staticdelivr' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'google_fonts_enabled' ); ?>" value="1" <?php checked( 1, $google_fonts_enabled ); ?> />
                                <?php esc_html_e( 'Proxy Google Fonts through StaticDelivr', 'staticdelivr' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Automatically rewrites all Google Fonts URLs to use StaticDelivr\'s privacy-respecting proxy.', 'staticdelivr' ); ?></p>
                            <div class="staticdelivr-example">
                                <code>https://fonts.googleapis.com/css2?family=Inter&amp;display=swap</code>
                                <span class="becomes">→</span>
                                <code><?php echo esc_html( STATICDELIVR_CDN_BASE ); ?>/gfonts/css2?family=Inter&amp;display=swap</code>
                            </div>
                        </td>
                    </tr>
                </table>

                <div class="staticdelivr-info-box">
                    <h4><?php esc_html_e( 'Why Proxy Google Fonts?', 'staticdelivr' ); ?></h4>
                    <ul>
                        <li><strong><?php esc_html_e( 'Privacy First', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Strips all user-identifying data and tracking cookies.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'GDPR Compliant', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'No need to declare Google Fonts in your cookie banner.', 'staticdelivr' ); ?></li>
                        <li><strong><?php esc_html_e( 'HTTP/3 & Brotli', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Files served over HTTP/3 with Brotli compression.', 'staticdelivr' ); ?></li>
                    </ul>
                </div>

                <?php submit_button(); ?>
            </form>

            <!-- Failure Statistics -->
            <?php if ( $failure_stats['images']['total'] > 0 || $failure_stats['assets']['total'] > 0 ) : ?>
            <h2 class="title"><?php esc_html_e( 'CDN Failure Statistics', 'staticdelivr' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Resources that failed to load from CDN are automatically served locally. This cache expires after 24 hours.', 'staticdelivr' ); ?></p>

            <div class="staticdelivr-failure-stats">
                <h4><?php esc_html_e( 'Failed Resources', 'staticdelivr' ); ?></h4>
                <div class="stat-row">
                    <span><?php esc_html_e( 'Images:', 'staticdelivr' ); ?></span>
                    <span>
                        <?php
                        printf(
                            /* translators: 1: total failures, 2: blocked count */
                            esc_html__( '%1$d failures (%2$d blocked)', 'staticdelivr' ),
                            $failure_stats['images']['total'],
                            $failure_stats['images']['blocked']
                        );
                        ?>
                    </span>
                </div>
                <div class="stat-row">
                    <span><?php esc_html_e( 'Assets:', 'staticdelivr' ); ?></span>
                    <span>
                        <?php
                        printf(
                            /* translators: 1: total failures, 2: blocked count */
                            esc_html__( '%1$d failures (%2$d blocked)', 'staticdelivr' ),
                            $failure_stats['assets']['total'],
                            $failure_stats['assets']['blocked']
                        );
                        ?>
                    </span>
                </div>

                <form method="post" class="staticdelivr-clear-cache-btn">
                    <?php wp_nonce_field( 'staticdelivr_clear_failure_cache' ); ?>
                    <button type="submit" name="staticdelivr_clear_failure_cache" class="button button-secondary">
                        <?php esc_html_e( 'Clear Failure Cache', 'staticdelivr' ); ?>
                    </button>
                    <p class="description"><?php esc_html_e( 'This will retry all previously failed resources on next page load.', 'staticdelivr' ); ?></p>
                </form>
            </div>
            <?php endif; ?>

            <script>
            (function() {
                var toggle = document.getElementById('staticdelivr-images-toggle');
                if (!toggle) return;

                toggle.addEventListener('change', function() {
                    var qualityRow = document.getElementById('staticdelivr-quality-row');
                    var formatRow = document.getElementById('staticdelivr-format-row');
                    var qualityInput = qualityRow ? qualityRow.querySelector('input') : null;
                    var formatInput = formatRow ? formatRow.querySelector('select') : null;

                    var enabled = this.checked;
                    if (qualityRow) qualityRow.style.opacity = enabled ? '1' : '0.5';
                    if (formatRow) formatRow.style.opacity = enabled ? '1' : '0.5';
                    if (qualityInput) qualityInput.disabled = !enabled;
                    if (formatInput) formatInput.disabled = !enabled;
                });
            })();
            </script>
        </div>
        <?php
    }
}

// Initialize the plugin.
new StaticDelivr();