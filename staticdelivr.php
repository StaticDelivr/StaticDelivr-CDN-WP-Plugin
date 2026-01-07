<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Speed up your WordPress site with free CDN delivery and automatic image optimization. Reduces load times and bandwidth costs.
 * Version: 1.3.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Coozywana
 * Author URI: https://staticdelivr.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: staticdelivr
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
if (!defined('STATICDELIVR_VERSION')) {
    define('STATICDELIVR_VERSION', '1.3.1');
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
if (!defined('STATICDELIVR_IMG_CDN_BASE')) {
    define('STATICDELIVR_IMG_CDN_BASE', 'https://cdn.staticdelivr.com/img/images');
}

// Activation hook - set default options
register_activation_hook(__FILE__, 'staticdelivr_activate');
function staticdelivr_activate() {
    // Enable both features by default for new installs
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

    // Set flag to show welcome notice
    set_transient(STATICDELIVR_PREFIX . 'activation_notice', true, 60);
}

// Add Settings link to plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'staticdelivr_action_links');
function staticdelivr_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings')) . '">' . __('Settings', 'staticdelivr') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add helpful links in plugin meta row
add_filter('plugin_row_meta', 'staticdelivr_row_meta', 10, 2);
function staticdelivr_row_meta($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">' . __('Website', 'staticdelivr') . '</a>';
        $links[] = '<a href="https://staticdelivr.com/become-a-sponsor" target="_blank" rel="noopener noreferrer">' . __('Support Development', 'staticdelivr') . '</a>';
    }
    return $links;
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

    /**
     * Supported image extensions for optimization.
     *
     * @var array<int,string>
     */
    private $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff'];

    /**
     * Cache for plugin/theme versions to avoid repeated filesystem work per request.
     *
     * @var array<string,string>
     */
    private $version_cache = [];

    public function __construct() {
        // CSS/JS rewriting hooks
        add_filter('style_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_filter('script_loader_src', [$this, 'rewrite_url'], 10, 2);
        add_filter('script_loader_tag', [$this, 'inject_script_original_attribute'], 10, 3);
        add_filter('style_loader_tag', [$this, 'inject_style_original_attribute'], 10, 4);
        add_action('wp_head', [$this, 'inject_fallback_script_early'], 1);
        add_action('admin_head', [$this, 'inject_fallback_script_early'], 1);

        // Image optimization hooks
        add_filter('wp_get_attachment_image_src', [$this, 'rewrite_attachment_image_src'], 10, 4);
        add_filter('wp_calculate_image_srcset', [$this, 'rewrite_image_srcset'], 10, 5);
        add_filter('the_content', [$this, 'rewrite_content_images'], 99);
        add_filter('post_thumbnail_html', [$this, 'rewrite_thumbnail_html'], 10, 5);
        add_filter('wp_get_attachment_url', [$this, 'rewrite_attachment_url'], 10, 2);

        // Admin hooks
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_activation_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
    }

    /**
     * Enqueue admin styles for settings page.
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'settings_page_' . STATICDELIVR_PREFIX . 'cdn-settings') {
            return;
        }

        // Inline styles for the settings page
        wp_add_inline_style('wp-admin', $this->get_admin_styles());
    }

    /**
     * Get admin CSS styles.
     */
    private function get_admin_styles() {
        return '
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
        ';
    }

    /**
     * Show activation notice.
     */
    public function show_activation_notice() {
        if (!get_transient(STATICDELIVR_PREFIX . 'activation_notice')) {
            return;
        }

        delete_transient(STATICDELIVR_PREFIX . 'activation_notice');

        $settings_url = admin_url('options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings');
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong>🚀 StaticDelivr CDN is now active!</strong>
                Your site is already optimized with CDN delivery and image optimization enabled by default.
                <a href="<?php echo esc_url($settings_url); ?>">View Settings</a> to customize.
            </p>
        </div>
        <?php
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
     * Check if image optimization is enabled.
     *
     * @return bool
     */
    private function is_image_optimization_enabled() {
        return (bool) get_option(STATICDELIVR_PREFIX . 'images_enabled', false);
    }

    /**
     * Check if assets (CSS/JS) optimization is enabled.
     *
     * @return bool
     */
    private function is_assets_optimization_enabled() {
        return (bool) get_option(STATICDELIVR_PREFIX . 'assets_enabled', false);
    }

    /**
     * Get image optimization quality setting.
     *
     * @return int
     */
    private function get_image_quality() {
        return (int) get_option(STATICDELIVR_PREFIX . 'image_quality', 80);
    }

    /**
     * Get image optimization format setting.
     *
     * @return string
     */
    private function get_image_format() {
        return get_option(STATICDELIVR_PREFIX . 'image_format', 'webp');
    }

    /**
     * Build StaticDelivr image CDN URL.
     *
     * @param string $original_url The original image URL.
     * @param int|null $width Optional width.
     * @param int|null $height Optional height.
     * @return string The CDN URL.
     */
    private function build_image_cdn_url($original_url, $width = null, $height = null) {
        if (empty($original_url)) {
            return $original_url;
        }

        // Don't rewrite if already a StaticDelivr URL
        if (strpos($original_url, 'cdn.staticdelivr.com') !== false) {
            return $original_url;
        }

        // Ensure absolute URL
        if (strpos($original_url, '//') === 0) {
            $original_url = 'https:' . $original_url;
        } elseif (strpos($original_url, '/') === 0) {
            $original_url = home_url($original_url);
        }

        // Validate it's an image URL
        $extension = strtolower(pathinfo(wp_parse_url($original_url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($extension, $this->image_extensions, true)) {
            return $original_url;
        }

        // Build CDN URL with optimization parameters
        $params = [];

        // URL parameter is required
        $params['url'] = $original_url;

        $quality = $this->get_image_quality();
        if ($quality && $quality < 100) {
            $params['q'] = $quality;
        }

        $format = $this->get_image_format();
        if ($format && $format !== 'auto') {
            $params['format'] = $format;
        }

        if ($width) {
            $params['w'] = (int) $width;
        }

        if ($height) {
            $params['h'] = (int) $height;
        }

        // Build CDN URL with query parameters
        return STATICDELIVR_IMG_CDN_BASE . '?' . http_build_query($params);
    }

    /**
     * Rewrite attachment image src array.
     *
     * @param array|false $image Image data array or false.
     * @param int $attachment_id Attachment ID.
     * @param string|int[] $size Requested image size.
     * @param bool $icon Whether to use icon.
     * @return array|false
     */
    public function rewrite_attachment_image_src($image, $attachment_id, $size, $icon) {
        if (!$this->is_image_optimization_enabled() || !$image || !is_array($image)) {
            return $image;
        }

        $original_url = $image[0];
        $width = isset($image[1]) ? $image[1] : null;
        $height = isset($image[2]) ? $image[2] : null;

        $image[0] = $this->build_image_cdn_url($original_url, $width, $height);

        return $image;
    }

    /**
     * Rewrite image srcset URLs.
     *
     * @param array $sources Array of image sources.
     * @param array $size_array Array of width and height.
     * @param string $image_src The src attribute.
     * @param array $image_meta Image metadata.
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    public function rewrite_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->is_image_optimization_enabled() || !is_array($sources)) {
            return $sources;
        }

        foreach ($sources as $width => &$source) {
            if (isset($source['url'])) {
                $source['url'] = $this->build_image_cdn_url($source['url'], (int) $width);
            }
        }

        return $sources;
    }

    /**
     * Rewrite attachment URL.
     *
     * @param string $url The attachment URL.
     * @param int $attachment_id Attachment ID.
     * @return string
     */
    public function rewrite_attachment_url($url, $attachment_id) {
        if (!$this->is_image_optimization_enabled()) {
            return $url;
        }

        // Check if it's an image attachment
        $mime_type = get_post_mime_type($attachment_id);
        if (!$mime_type || strpos($mime_type, 'image/') !== 0) {
            return $url;
        }

        return $this->build_image_cdn_url($url);
    }

    /**
     * Rewrite image URLs in post content.
     *
     * @param string $content The post content.
     * @return string
     */
    public function rewrite_content_images($content) {
        if (!$this->is_image_optimization_enabled() || empty($content)) {
            return $content;
        }

        // Match img tags
        $pattern = '/<img[^>]+>/i';
        $content = preg_replace_callback($pattern, [$this, 'rewrite_img_tag'], $content);

        // Match background-image in inline styles
        $bg_pattern = '/background(-image)?\s*:\s*url\s*\([\'"]?([^\'")\s]+)[\'"]?\)/i';
        $content = preg_replace_callback($bg_pattern, [$this, 'rewrite_background_image'], $content);

        return $content;
    }

    /**
     * Rewrite a single img tag.
     *
     * @param array $matches Regex matches.
     * @return string
     */
    private function rewrite_img_tag($matches) {
        $img_tag = $matches[0];

        // Skip if already processed or is a StaticDelivr URL
        if (strpos($img_tag, 'cdn.staticdelivr.com') !== false) {
            return $img_tag;
        }

        // Skip data URIs and SVGs
        if (preg_match('/src=["\']data:/i', $img_tag) || preg_match('/\.svg["\'\s>]/i', $img_tag)) {
            return $img_tag;
        }

        // Extract width and height if present
        $width = null;
        $height = null;

        if (preg_match('/width=["\']?(\d+)/i', $img_tag, $w_match)) {
            $width = (int) $w_match[1];
        }
        if (preg_match('/height=["\']?(\d+)/i', $img_tag, $h_match)) {
            $height = (int) $h_match[1];
        }

        // Rewrite src attribute
        $img_tag = preg_replace_callback(
            '/src=["\']([^"\']+)["\']/i',
            function ($src_match) use ($width, $height) {
                $original_src = $src_match[1];
                $cdn_src = $this->build_image_cdn_url($original_src, $width, $height);
                return 'src="' . esc_attr($cdn_src) . '" data-original-src="' . esc_attr($original_src) . '"';
            },
            $img_tag
        );

        // Rewrite srcset attribute
        $img_tag = preg_replace_callback(
            '/srcset=["\']([^"\']+)["\']/i',
            function ($srcset_match) {
                $srcset = $srcset_match[1];
                $sources = explode(',', $srcset);
                $new_sources = [];

                foreach ($sources as $source) {
                    $source = trim($source);
                    if (preg_match('/^(.+?)\s+(\d+w|\d+x)$/i', $source, $parts)) {
                        $url = trim($parts[1]);
                        $descriptor = $parts[2];

                        // Extract width from descriptor
                        $width = null;
                        if (preg_match('/(\d+)w/', $descriptor, $w_match)) {
                            $width = (int) $w_match[1];
                        }

                        $cdn_url = $this->build_image_cdn_url($url, $width);
                        $new_sources[] = $cdn_url . ' ' . $descriptor;
                    } else {
                        $new_sources[] = $source;
                    }
                }

                return 'srcset="' . esc_attr(implode(', ', $new_sources)) . '"';
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
    private function rewrite_background_image($matches) {
        $full_match = $matches[0];
        $url = $matches[2];

        // Skip if already a CDN URL or data URI
        if (strpos($url, 'cdn.staticdelivr.com') !== false || strpos($url, 'data:') === 0) {
            return $full_match;
        }

        $cdn_url = $this->build_image_cdn_url($url);
        return str_replace($url, $cdn_url, $full_match);
    }

    /**
     * Rewrite post thumbnail HTML.
     *
     * @param string $html The thumbnail HTML.
     * @param int $post_id Post ID.
     * @param int $thumbnail_id Thumbnail attachment ID.
     * @param string|int[] $size Image size.
     * @param string|array $attr Image attributes.
     * @return string
     */
    public function rewrite_thumbnail_html($html, $post_id, $thumbnail_id, $size, $attr) {
        if (!$this->is_image_optimization_enabled() || empty($html)) {
            return $html;
        }

        return $this->rewrite_img_tag([$html]);
    }

    /**
     * Get theme version by stylesheet (folder name), cached.
     *
     * @param string $theme_slug Theme folder name.
     * @return string
     */
    private function get_theme_version($theme_slug) {
        $key = 'theme:' . $theme_slug;
        if (isset($this->version_cache[$key])) {
            return $this->version_cache[$key];
        }
        $theme = wp_get_theme($theme_slug);
        $version = (string) $theme->get('Version');
        $this->version_cache[$key] = $version;
        return $version;
    }

    /**
     * Get plugin version by slug (folder name), cached.
     *
     * This fixes the bug where the code assumed:
     *   plugins/{slug}/{slug}.php
     * and also fixes the use of STATICDELIVR_PLUGIN_DIR (wrong base dir).
     *
     * @param string $plugin_slug Plugin folder name (slug).
     * @return string
     */
    private function get_plugin_version($plugin_slug) {
        $key = 'plugin:' . $plugin_slug;
        if (isset($this->version_cache[$key])) {
            return $this->version_cache[$key];
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        // $plugin_file looks like "wordpress-seo/wp-seo.php", "hello-dolly/hello.php", etc.
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $plugin_slug . '/') === 0) {
                $version = isset($plugin_data['Version']) ? (string) $plugin_data['Version'] : '';
                $this->version_cache[$key] = $version;
                return $version;
            }
        }

        $this->version_cache[$key] = '';
        return '';
    }

    /**
     * Rewrite the URL to use StaticDelivr CDN.
     *
     * @param string $src The original source URL.
     * @param string $handle The resource handle.
     * @return string The modified URL.
     */
    public function rewrite_url($src, $handle) {
        // Check if assets optimization is enabled
        if (!$this->is_assets_optimization_enabled()) {
            return $src;
        }

        $parsed_url = wp_parse_url($src);

        // Extract the clean WordPress path
        if (!isset($parsed_url['path'])) {
            return $src;
        }

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

            if (in_array('themes', $path_parts, true)) {
                // Rewrite theme URLs
                $themes_index = array_search('themes', $path_parts, true);
                $theme_name = $path_parts[$themes_index + 1] ?? '';
                $version = $this->get_theme_version($theme_name);
                $file_path = implode('/', array_slice($path_parts, $themes_index + 2));

                // Skip rewriting if version is not found
                if (empty($version)) {
                    return $src;
                }

                $rewritten = sprintf('https://cdn.staticdelivr.com/wp/themes/%s/%s/%s', $theme_name, $version, $file_path);
                $this->remember_original_source($handle, $src);
                return $rewritten;
            }

            if (in_array('plugins', $path_parts, true)) {
                // Rewrite plugin URLs
                $plugins_index = array_search('plugins', $path_parts, true);
                $plugin_name = $path_parts[$plugins_index + 1] ?? '';
                $version = $this->get_plugin_version($plugin_name);
                $file_path = implode('/', array_slice($path_parts, $plugins_index + 2));

                // Skip rewriting if version is not found
                if (empty($version)) {
                    return $src;
                }

                $rewritten = sprintf('https://cdn.staticdelivr.com/wp/plugins/%s/tags/%s/%s', $plugin_name, $version, $file_path);
                $this->remember_original_source($handle, $src);
                return $rewritten;
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
        // Only inject if at least one optimization feature is enabled
        if ($this->fallback_script_enqueued || (!$this->is_assets_optimization_enabled() && !$this->is_image_optimization_enabled())) {
            return;
        }

        $this->fallback_script_enqueued = true;
        $handle = STATICDELIVR_PREFIX . 'fallback';
        $inline = $this->get_fallback_inline_script();

        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), '1.2.1', false);
        }

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
        $script .= 'var SD_DEBUG = true;';
        $script .= 'function copyAttributes(from, to){';
        $script .= 'if (!from || !to || !from.attributes) return;';
        $script .= 'for (var i = 0; i < from.attributes.length; i++) {';
        $script .= 'var attr = from.attributes[i];';
        $script .= 'if (!attr || !attr.name) continue;';
        $script .= "if (attr.name === 'src' || attr.name === 'href' || attr.name === 'data-original-src' || attr.name === 'data-original-href') continue;";
        $script .= 'to.setAttribute(attr.name, attr.value);';
        $script .= '}';
        $script .= '}';

        $script .= 'function extractOriginalFromCdnUrl(cdnUrl){';
        $script .= 'if (!cdnUrl) return null;';
        $script .= 'if (cdnUrl.indexOf("cdn.staticdelivr.com") === -1) return null;';
        $script .= 'try {';
        $script .= 'var urlObj = new URL(cdnUrl);';
        $script .= 'var originalUrl = urlObj.searchParams.get("url");';
        $script .= 'if (SD_DEBUG && originalUrl) console.log("[StaticDelivr] Extracted original URL:", originalUrl);';
        $script .= 'return originalUrl || null;';
        $script .= '} catch(e) {';
        $script .= 'if (SD_DEBUG) console.log("[StaticDelivr] Failed to parse CDN URL:", cdnUrl, e);';
        $script .= 'return null;';
        $script .= '}';
        $script .= '}';

        $script .= 'function handleError(event){';
        $script .= 'var el = event.target || event.srcElement;';
        $script .= 'if (!el) return;';
        $script .= 'var tagName = el.tagName ? el.tagName.toUpperCase() : "";';
        $script .= 'if (!tagName) return;';

        $script .= 'if (SD_DEBUG) {';
        $script .= 'var currentSrc = el.src || el.href || el.currentSrc || "";';
        $script .= 'if (currentSrc.indexOf("staticdelivr") !== -1) {';
        $script .= 'console.log("[StaticDelivr] Caught error on:", tagName, currentSrc);';
        $script .= '}';
        $script .= '}';

        $script .= 'if (el.getAttribute && el.getAttribute("data-sd-fallback") === "done") return;';

        $script .= 'var failedUrl = "";';
        $script .= 'if (tagName === "IMG") failedUrl = el.src || el.currentSrc || "";';
        $script .= 'else if (tagName === "SCRIPT") failedUrl = el.src || "";';
        $script .= 'else if (tagName === "LINK") failedUrl = el.href || "";';
        $script .= 'else return;';

        $script .= 'if (failedUrl.indexOf("cdn.staticdelivr.com") === -1) return;';

        $script .= 'var original = el.getAttribute("data-original-src") || el.getAttribute("data-original-href");';
        $script .= 'if (!original) original = extractOriginalFromCdnUrl(failedUrl);';

        $script .= 'if (!original) {';
        $script .= 'if (SD_DEBUG) console.log("[StaticDelivr] Could not determine original URL for:", failedUrl);';
        $script .= 'return;';
        $script .= '}';

        $script .= 'el.setAttribute("data-sd-fallback", "done");';
        $script .= 'console.log("[StaticDelivr] CDN failed, falling back to origin:", tagName, original);';

        $script .= 'if (tagName === "SCRIPT") {';
        $script .= 'var newScript = document.createElement("script");';
        $script .= 'newScript.src = original;';
        $script .= 'newScript.async = el.async;';
        $script .= 'newScript.defer = el.defer;';
        $script .= 'if (el.type) newScript.type = el.type;';
        $script .= 'if (el.noModule) newScript.noModule = true;';
        $script .= 'if (el.crossOrigin) newScript.crossOrigin = el.crossOrigin;';
        $script .= 'copyAttributes(el, newScript);';
        $script .= 'if (el.parentNode) {';
        $script .= 'el.parentNode.insertBefore(newScript, el.nextSibling);';
        $script .= 'el.parentNode.removeChild(el);';
        $script .= '}';
        $script .= 'console.log("[StaticDelivr] Script fallback complete:", original);';

        $script .= '} else if (tagName === "LINK") {';
        $script .= 'el.href = original;';
        $script .= 'console.log("[StaticDelivr] Stylesheet fallback complete:", original);';

        $script .= '} else if (tagName === "IMG") {';
        $script .= 'if (el.srcset) {';
        $script .= 'var newSrcset = el.srcset.split(",").map(function(entry) {';
        $script .= 'var parts = entry.trim().split(/\\s+/);';
        $script .= 'var url = parts[0];';
        $script .= 'var descriptor = parts.slice(1).join(" ");';
        $script .= 'var extracted = extractOriginalFromCdnUrl(url);';
        $script .= 'if (extracted) url = extracted;';
        $script .= 'return descriptor ? url + " " + descriptor : url;';
        $script .= '}).join(", ");';
        $script .= 'el.srcset = newSrcset;';
        $script .= '}';
        $script .= 'el.src = original;';
        $script .= 'console.log("[StaticDelivr] Image fallback complete:", original);';
        $script .= '}';

        $script .= '}';

        $script .= 'window.addEventListener("error", handleError, true);';
        $script .= 'console.log("[StaticDelivr] Fallback script initialized (v1.2.1)");';
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
        // Assets (CSS/JS) optimization setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'assets_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => true,
            )
        );

        // Image optimization setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'images_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => true,
            )
        );

        // Image quality setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'image_quality',
            array(
                'type'              => 'integer',
                'sanitize_callback' => [$this, 'sanitize_image_quality'],
                'default'           => 80,
            )
        );

        // Image format setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'image_format',
            array(
                'type'              => 'string',
                'sanitize_callback' => [$this, 'sanitize_image_format'],
                'default'           => 'webp',
            )
        );
    }

    /**
     * Sanitize image quality value.
     *
     * @param mixed $value The input value.
     * @return int
     */
    public function sanitize_image_quality($value) {
        $quality = absint($value);
        if ($quality < 1) {
            return 1;
        }
        if ($quality > 100) {
            return 100;
        }
        return $quality;
    }

    /**
     * Sanitize image format value.
     *
     * @param mixed $value The input value.
     * @return string
     */
    public function sanitize_image_format($value) {
        $allowed_formats = ['auto', 'webp', 'avif', 'jpeg', 'png'];
        if (in_array($value, $allowed_formats, true)) {
            return $value;
        }
        return 'webp';
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        $assets_enabled = get_option(STATICDELIVR_PREFIX . 'assets_enabled', true);
        $images_enabled = get_option(STATICDELIVR_PREFIX . 'images_enabled', true);
        $image_quality = get_option(STATICDELIVR_PREFIX . 'image_quality', 80);
        $image_format = get_option(STATICDELIVR_PREFIX . 'image_format', 'webp');
        $site_url = home_url();
        ?>
        <div class="wrap">
            <h1>StaticDelivr CDN</h1>
            <p>Optimize your WordPress site by delivering assets through the <a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">StaticDelivr CDN</a>.</p>

            <!-- Status Bar -->
            <div class="staticdelivr-status-bar">
                <div class="staticdelivr-status-item">
                    <span class="label">Assets CDN:</span>
                    <span class="value <?php echo $assets_enabled ? 'active' : 'inactive'; ?>">
                        <?php echo $assets_enabled ? '● Enabled' : '○ Disabled'; ?>
                    </span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label">Image Optimization:</span>
                    <span class="value <?php echo $images_enabled ? 'active' : 'inactive'; ?>">
                        <?php echo $images_enabled ? '● Enabled' : '○ Disabled'; ?>
                    </span>
                </div>
                <?php if ($images_enabled): ?>
                <div class="staticdelivr-status-item">
                    <span class="label">Quality:</span>
                    <span class="value"><?php echo esc_html($image_quality); ?>%</span>
                </div>
                <div class="staticdelivr-status-item">
                    <span class="label">Format:</span>
                    <span class="value"><?php echo esc_html(strtoupper($image_format)); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(STATICDELIVR_PREFIX . 'cdn_settings'); ?>

                <h2 class="title">Assets Optimization (CSS &amp; JavaScript)</h2>
                <p class="description">Rewrite URLs of WordPress core files, themes, and plugins to use StaticDelivr CDN.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Assets CDN</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'assets_enabled'); ?>" value="1" <?php checked(1, $assets_enabled); ?> />
                                Enable CDN for CSS &amp; JavaScript files
                            </label>
                            <p class="description">Serves WordPress core, theme, and plugin assets from StaticDelivr CDN for faster loading.</p>
                            <div class="staticdelivr-example">
                                <code><?php echo esc_html($site_url); ?>/wp-includes/js/jquery.js</code>
                                <span class="becomes">→</span>
                                <code>https://cdn.staticdelivr.com/wp/core/trunk/wp-includes/js/jquery.js</code>
                            </div>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Image Optimization</h2>
                <p class="description">Automatically optimize and deliver images through StaticDelivr CDN. This can dramatically reduce image file sizes (e.g., 2MB → 20KB) and improve loading times.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Image Optimization</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'images_enabled'); ?>" value="1" <?php checked(1, $images_enabled); ?> id="staticdelivr-images-toggle" />
                                Enable CDN for images
                            </label>
                            <p class="description">Optimizes and delivers all images through StaticDelivr CDN with automatic format conversion and compression.</p>
                            <div class="staticdelivr-example">
                                <code><?php echo esc_html($site_url); ?>/wp-content/uploads/photo.jpg (2MB)</code>
                                <span class="becomes">→</span>
                                <code>https://cdn.staticdelivr.com/img/images?url=...&amp;q=80&amp;format=webp (~20KB)</code>
                            </div>
                        </td>
                    </tr>
                    <tr valign="top" id="staticdelivr-quality-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
                        <th scope="row">Image Quality</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'image_quality'); ?>" value="<?php echo esc_attr($image_quality); ?>" min="1" max="100" step="1" class="small-text" <?php echo $images_enabled ? '' : 'disabled'; ?> />
                            <p class="description">Quality level for optimized images (1-100). Lower values = smaller files. Recommended: 75-85 for best balance of quality and size.</p>
                        </td>
                    </tr>
                    <tr valign="top" id="staticdelivr-format-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
                        <th scope="row">Image Format</th>
                        <td>
                            <select name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'image_format'); ?>" <?php echo $images_enabled ? '' : 'disabled'; ?>>
                                <option value="auto" <?php selected($image_format, 'auto'); ?>>Auto (Best for browser)</option>
                                <option value="webp" <?php selected($image_format, 'webp'); ?>>WebP (Recommended)</option>
                                <option value="avif" <?php selected($image_format, 'avif'); ?>>AVIF (Best compression)</option>
                                <option value="jpeg" <?php selected($image_format, 'jpeg'); ?>>JPEG</option>
                                <option value="png" <?php selected($image_format, 'png'); ?>>PNG</option>
                            </select>
                            <p class="description">
                                <strong>WebP</strong>: Great compression, widely supported.<br>
                                <strong>AVIF</strong>: Best compression, newer format.<br>
                                <strong>Auto</strong>: Automatically selects the best format based on browser support.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">How It Works</h2>
                <div style="background: #f0f0f1; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                    <h4 style="margin-top: 0;">Assets (CSS &amp; JS)</h4>
                    <p style="margin-bottom: 5px;"><code><?php echo esc_html($site_url); ?>/wp-includes/js/jquery.js</code></p>
                    <p style="margin-bottom: 15px;">→ <code>https://cdn.staticdelivr.com/wp/core/trunk/wp-includes/js/jquery.js</code></p>

                    <h4>Images</h4>
                    <p style="margin-bottom: 5px;"><code><?php echo esc_html($site_url); ?>/wp-content/uploads/photo.jpg</code> (2MB)</p>
                    <p style="margin-bottom: 0;">→ <code>https://cdn.staticdelivr.com/img/images?url=...&amp;q=80&amp;format=webp</code> (~20KB)</p>
                </div>

                <h2 class="title">Benefits</h2>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong>Faster Loading</strong>: Assets served from global CDN edge servers closest to your visitors.</li>
                    <li><strong>Bandwidth Savings</strong>: Reduce your server's bandwidth usage significantly.</li>
                    <li><strong>Image Optimization</strong>: Automatically compress and convert images to modern formats.</li>
                    <li><strong>Automatic Fallback</strong>: If CDN fails, assets automatically load from your server.</li>
                </ul>

                <?php submit_button(); ?>
            </form>

            <script>
            document.getElementById('staticdelivr-images-toggle').addEventListener('change', function() {
                var qualityRow = document.getElementById('staticdelivr-quality-row');
                var formatRow = document.getElementById('staticdelivr-format-row');
                var qualityInput = qualityRow.querySelector('input');
                var formatInput = formatRow.querySelector('select');

                if (this.checked) {
                    qualityRow.style.opacity = '1';
                    formatRow.style.opacity = '1';
                    qualityInput.disabled = false;
                    formatInput.disabled = false;
                } else {
                    qualityRow.style.opacity = '0.5';
                    formatRow.style.opacity = '0.5';
                    qualityInput.disabled = true;
                    formatInput.disabled = true;
                }
            });
            </script>
        </div>
        <?php
    }
}

new StaticDelivr();
