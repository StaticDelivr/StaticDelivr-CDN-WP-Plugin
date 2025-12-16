<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Enhance your WordPress site's performance by rewriting theme, plugin, and core file URLs to use the high-performance StaticDelivr CDN, reducing load times and server bandwidth.
 * Version: 1.1.0
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

    /**
     * Stores original asset URLs by handle for later fallback usage.
     *
     * @var array<string,string>
     */
    private $original_sources = [];

    /**
     * Ensures the fallback script is only enqueued once per request.
     *
     * @var bool
     */
    private $fallback_script_enqueued = false;

    public function __construct() {
        add_filter('style_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_filter('script_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_filter('script_loader_tag', [$this, 'inject_script_original_attribute'], 10, 3);
        add_filter('style_loader_tag', [$this, 'inject_style_original_attribute'], 10, 4);
        add_action('wp_head', [$this, 'inject_fallback_script_early'], 1);
        add_action('admin_head', [$this, 'inject_fallback_script_early'], 1);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Extract the clean WordPress path from a given URL path.
     *
     * @param string $path The original path.
     * @return string The extracted WordPress path or the original path if no match.
     */
    private function extract_wp_path($path) {
        $wp_patterns = ['wp-includes/', 'wp-content/'];
        foreach ($wp_patterns as $pattern) {
            $index = strpos($path, $pattern);
            if ($index !== false) {
                return substr($path, $index);
            }
        }
        return $path;
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

        // Extract the clean WordPress path
        if (isset($parsed_url['path'])) {
            $clean_path = $this->extract_wp_path($parsed_url['path']);

            // Rewrite WordPress core files
            if (strpos($clean_path, 'wp-includes/') === 0) {
                $rewritten = sprintf('https://cdn.staticdelivr.com/wp/core/trunk/%s', ltrim($clean_path, '/'));
                $this->remember_original_source($handle, $src);
                return $rewritten;
            }

            // Rewrite theme and plugin URLs
            if (strpos($clean_path, 'wp-content/') === 0) {
                $path_parts = explode('/', $clean_path);

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

                    $rewritten = sprintf('https://cdn.staticdelivr.com/wp/themes/%s/%s/%s', $theme_name, $version, $file_path);
                    $this->remember_original_source($handle, $src);
                    return $rewritten;
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

                    $rewritten = sprintf('https://cdn.staticdelivr.com/wp/plugins/%s/tags/%s/%s', $plugin_name, $tag_name, $file_path);
                    $this->remember_original_source($handle, $src);
                    return $rewritten;
                }
            }
        }

        return $src;
    }

    /**
     * Track the original asset URL for a given handle so we can fallback later if needed.
     *
     * @param string $handle Asset handle.
     * @param string $src Original URL.
     * @return void
     */
    private function remember_original_source($handle, $src) {
        if (empty($handle) || empty($src)) {
            return;
        }
        if (!isset($this->original_sources[$handle])) {
            $this->original_sources[$handle] = $src;
        }
    }

    /**
     * Inject data-original-src into rewritten script tags.
     *
     * @param string $tag Complete script tag HTML.
     * @param string $handle Asset handle.
     * @param string $src Final script src.
     * @return string
     */
    public function inject_script_original_attribute($tag, $handle, $src) {
        if (empty($this->original_sources[$handle]) || strpos($tag, 'data-original-src=') !== false) {
            return $tag;
        }

        $original = esc_attr($this->original_sources[$handle]);
        // Use preg_replace to add data-original-src attribute to the script tag.
        // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- modifying existing enqueued script tag, not outputting a new script.
        return preg_replace('/(<script\b)/i', '$1 data-original-src="' . $original . '"', $tag, 1);
    }

    /**
     * Inject data-original-href into rewritten stylesheet link tags.
     *
     * @param string $html Complete link tag HTML.
     * @param string $handle Asset handle.
     * @param string $href Final stylesheet href.
     * @param string $media Media attribute.
     * @return string
     */
    public function inject_style_original_attribute($html, $handle, $href, $media) {
        if (empty($this->original_sources[$handle]) || strpos($html, 'data-original-href=') !== false) {
            return $html;
        }

        $original = esc_attr($this->original_sources[$handle]);
        return str_replace('<link', '<link data-original-href="' . $original . '"', $html);
    }

    /**
     * Inject the fallback script directly in the head (before any scripts load).
     */
    public function inject_fallback_script_early() {
        if ($this->fallback_script_enqueued || !get_option(STATICDELIVR_PREFIX . 'enabled', true)) {
            return;
        }

        $this->fallback_script_enqueued = true;
        $handle = STATICDELIVR_PREFIX . 'fallback';
        $inline = $this->get_fallback_inline_script();

        // Register a script with an empty src so WordPress outputs an inline script
        // and the script is properly registered/enqueued per WP best practices.
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), '1.1.0', false);
        }

        // Add the inline script before the script tag so it runs early.
        wp_add_inline_script($handle, $inline, 'before');
        wp_enqueue_script($handle);
    }

    /**
     * Front-end JS for retrying failed CDN assets via their original origin URLs.
     *
     * @return string
     */
    private function get_fallback_inline_script() {
        $script = '(function(){';
        $script .= 'function copyAttributes(from, to){';
        $script .= 'if (!from || !to || !from.attributes) return;';
        $script .= 'for (var i = 0; i < from.attributes.length; i++) {';
        $script .= 'var attr = from.attributes[i];';
        $script .= 'if (!attr || !attr.name) continue;';
        $script .= "if (attr.name === 'src' || attr.name === 'href' || attr.name === 'data-original-src' || attr.name === 'data-original-href') {";
        $script .= 'continue;';
        $script .= '}';
        $script .= 'to.setAttribute(attr.name, attr.value);';
        $script .= '}';
        $script .= '}';
        $script .= 'function handleError(event){';
        $script .= 'var el = event && (event.target || event.srcElement);';
        $script .= 'if (!el || !el.tagName || !el.dataset) return;';
        $script .= "if (el.dataset.staticdelivrFallbackDone === '1') return;";
        $script .= "var original = el.getAttribute('data-original-src') || el.getAttribute('data-original-href');";
        $script .= 'if (!original) return;';
        $script .= "console.log('[StaticDelivr] CDN asset failed, falling back to origin:', el.tagName, original);";
        $script .= "el.dataset.staticdelivrFallbackDone = '1';";
        $script .= "if (el.tagName === 'SCRIPT') {";
        $script .= "var replacement = document.createElement('script');";
        $script .= 'replacement.src = original;';
        $script .= 'replacement.async = el.async;';
        $script .= 'replacement.defer = el.defer;';
        $script .= "replacement.type = el.type || 'text/javascript';";
        $script .= 'if (el.noModule) {';
        $script .= 'replacement.noModule = true;';
        $script .= '}';
        $script .= 'if (el.crossOrigin) {';
        $script .= 'replacement.crossOrigin = el.crossOrigin;';
        $script .= '}';
        $script .= 'copyAttributes(el, replacement);';
        $script .= 'if (el.parentNode) {';
        $script .= 'el.parentNode.insertBefore(replacement, el.nextSibling);';
        $script .= 'el.parentNode.removeChild(el);';
        $script .= '}';
        $script .= "} else if (el.tagName === 'LINK') {";
        $script .= 'copyAttributes(el, el);';
        $script .= 'el.href = original;';
        $script .= '}';
        $script .= '}';
        $script .= "window.addEventListener('error', handleError, true);";
        $script .= "console.log('[StaticDelivr] Fallback script initialized');";
        $script .= '})();';
        return $script;
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
                            <input type="checkbox" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'enabled'); ?>" value="1" <?php checked(1, get_option(STATICDELIVR_PREFIX . 'enabled', true)); ?> />
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
