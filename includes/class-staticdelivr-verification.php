<?php
/**
 * StaticDelivr CDN Verification System
 *
 * Handles verification of whether themes and plugins exist on wordpress.org.
 * Uses a multi-layer caching strategy for optimal performance.
 *
 * @package StaticDelivr
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Verification
 *
 * Verifies if themes/plugins exist on wordpress.org
 * and caches results for performance.
 *
 * @since 1.6.0
 */
class StaticDelivr_Verification {

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
     * Singleton instance.
     *
     * @var StaticDelivr_Verification|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return StaticDelivr_Verification
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * Sets up hooks for verification system.
     */
    private function __construct() {
        // Save cache on shutdown if modified.
        add_action( 'shutdown', array( $this, 'maybe_save_verification_cache' ), 0 );

        // Theme/plugin change hooks - clear relevant cache entries.
        add_action( 'switch_theme', array( $this, 'on_theme_switch' ), 10, 3 );
        add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 2 );
        add_action( 'deleted_plugin', array( $this, 'on_plugin_deleted' ), 10, 2 );

        // Cron hook for daily cleanup.
        add_action( STATICDELIVR_PREFIX . 'daily_cleanup', array( $this, 'daily_cleanup_task' ) );
    }

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
                'description'  => false,
                'sections'     => false,
                'tags'         => false,
                'screenshot'   => false,
                'ratings'      => false,
                'downloaded'   => false,
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

        // Also cleanup failure cache.
        $failure_tracker = StaticDelivr_Failure_Tracker::get_instance();
        $failure_tracker->cleanup_failure_cache();
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
    public function get_plugin_slug_from_file( $plugin_file ) {
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
            $cached = isset( $this->verification_cache['themes'][ $slug ] )
                ? $this->verification_cache['themes'][ $slug ]
                : null;

            $info = array(
                'name'       => $theme->get( 'Name' ),
                'version'    => $theme->get( 'Version' ),
                'is_child'   => $theme->parent() ? true : false,
                'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
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
}
