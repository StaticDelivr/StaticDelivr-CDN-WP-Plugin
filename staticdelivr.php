<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Enhance your WordPress site’s performance by rewriting theme, plugin, and core file URLs to use the high-performance StaticDelivr CDN, reducing load times and server bandwidth.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Coozywana
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
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

class StaticDelivr {

    public function __construct() {
        add_filter('style_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_filter('script_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Rewrite the URL to use StaticDelivr CDN.
     *
     * @param string $src The original source URL.
     * @param string $handle The resource handle.
     * @return string The modified URL.
     */
    public function rewrite_url($src, $handle) {
        // Check if the plugin is enabled
        if (!get_option(STATICDELIVR_PREFIX . 'enabled', true)) {
            return $src;
        }

        $parsed_url = wp_parse_url($src);
        
        if (!isset($parsed_url['path'])) {
            return $src;
        }
    
        // Handle WordPress core files
        if (strpos($parsed_url['path'], 'wp-includes/') !== false) {
            // Find the position of wp-includes and extract everything from there
            $wp_includes_pos = strpos($parsed_url['path'], 'wp-includes/');
            $file_path = substr($parsed_url['path'], $wp_includes_pos);
 
            return sprintf('https://cdn.staticdelivr.com/wp/core/trunk/%s%s', $file_path);
        }
    
        // Handle theme and plugin URLs 
        if (strpos($parsed_url['path'], 'wp-content/') !== false) {
            $path_parts = explode('/', ltrim($parsed_url['path'], '/'));

            if (in_array('themes', $path_parts)) {
                // Rewrite theme URLs
                $theme_name = $path_parts[array_search('themes', $path_parts) + 1] ?? '';
                $theme = wp_get_theme($theme_name);
                $version = $theme->get('Version');
                $file_path = implode('/', array_slice($path_parts, array_search('themes', $path_parts) + 2));

                // Skip rewriting if version is not found
                if (empty($version)) {
                    return $src;
                }

                return sprintf('https://cdn.staticdelivr.com/wp/themes/%s/%s/%s', $theme_name, $version, $file_path);
            } elseif (in_array('plugins', $path_parts)) {
                // Rewrite plugin URLs
                $plugin_name = $path_parts[array_search('plugins', $path_parts) + 1] ?? '';
                $plugin_file_path = STATICDELIVR_PLUGIN_DIR . $plugin_name . '/' . $plugin_name . '.php';
                $plugin_data = file_exists($plugin_file_path) ? get_plugin_data($plugin_file_path) : [];
                $tag_name = $plugin_data['Version'] ?? '';
                $file_path = implode('/', array_slice($path_parts, array_search('plugins', $path_parts) + 2));
    
                // Skip rewriting if tag name is not found
                if (empty($tag_name)) {
                    return $src;
                }
    
                return sprintf('https://cdn.staticdelivr.com/wp/plugins/%s/tags/%s/%s', $plugin_name, $tag_name, $file_path);
            }
        }
    
        return $src;
    }

    /**
     * Add settings page to the WordPress admin.
     */
    public function add_settings_page() {
        add_options_page(
            'StaticDelivr CDN Settings',
            'StaticDelivr CDN',
            'manage_options',
            STATICDELIVR_PREFIX . 'cdn-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => false,
            )
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>StaticDelivr CDN Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(STATICDELIVR_PREFIX . 'cdn_settings');
                do_settings_sections(STATICDELIVR_PREFIX . 'cdn_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable StaticDelivr CDN</th>
                        <td>
                            <input type="checkbox" name="<?php echo STATICDELIVR_PREFIX . 'enabled'; ?>" value="1" <?php checked(1, get_option(STATICDELIVR_PREFIX . 'enabled', true)); ?> />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new StaticDelivr();
