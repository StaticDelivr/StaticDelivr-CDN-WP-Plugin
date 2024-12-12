<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Enhance your WordPress site’s performance by rewriting theme, plugin, and core file URLs to use the high-performance StaticDelivr CDN, reducing load times and server bandwidth.
 * Version: 1.0.0
 * Author: Coozywana
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class StaticDelivrCDN {

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
        if (!get_option('staticdelivr_enabled', true)) {
            return $src;
        }

        $parsed_url = parse_url($src);

        // Rewrite WordPress core files
        if (isset($parsed_url['path']) && strpos($parsed_url['path'], 'wp-includes/') !== false) {
            $file_path = ltrim($parsed_url['path'], '/');
            return sprintf('https://cdn.staticdelivr.com/wp/core/trunk/%s', $file_path);
        }

        // Rewrite theme and plugin URLs
        if (isset($parsed_url['path']) && strpos($parsed_url['path'], 'wp-content/') !== false) {
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
                $plugin_file_path = WP_PLUGIN_DIR . '/' . $plugin_name . '/' . $plugin_name . '.php';
                $plugin_data = get_plugin_data($plugin_file_path);
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
            'staticdelivr-cdn-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting('staticdelivr_cdn_settings', 'staticdelivr_enabled');
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
                settings_fields('staticdelivr_cdn_settings');
                do_settings_sections('staticdelivr_cdn_settings');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable StaticDelivr CDN</th>
                        <td>
                            <input type="checkbox" name="staticdelivr_enabled" value="1" <?php checked(1, get_option('staticdelivr_enabled', true)); ?> />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new StaticDelivrCDN();
