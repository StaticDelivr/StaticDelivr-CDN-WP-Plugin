<?php
/**
 * Plugin Name: StaticDelivr CDN
 * Description: Enhance your WordPress site's performance by rewriting theme, plugin, and core file URLs to use the high-performance StaticDelivr CDN, reducing load times and server bandwidth. Includes automatic image optimization.
 * Version: 1.2.0
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
if (!defined('STATICDELIVR_IMG_CDN_BASE')) {
    define('STATICDELIVR_IMG_CDN_BASE', 'https://cdn.staticdelivr.com/img/images');
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
     * @var array
     */
    private $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff'];

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
        $cdn_url = STATICDELIVR_IMG_CDN_BASE . '?' . http_build_query($params);

        return $cdn_url;
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
                $source['url'] = $this->build_image_cdn_url($source['url'], $width);
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
        // Only inject if at least one optimization feature is enabled
        if ($this->fallback_script_enqueued || (!$this->is_assets_optimization_enabled() && !$this->is_image_optimization_enabled())) {
            return;
        }

        $this->fallback_script_enqueued = true;
        $handle = STATICDELIVR_PREFIX . 'fallback';
        $inline = $this->get_fallback_inline_script();

        // Register a script with an empty src so WordPress outputs an inline script
        // and the script is properly registered/enqueued per WP best practices.
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), '1.2.0', false);
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
        $script .= "} else if (el.tagName === 'IMG') {";
        $script .= 'el.src = original;';
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
        // Assets (CSS/JS) optimization setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'assets_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => false,
            )
        );

        // Image optimization setting
        register_setting(
            STATICDELIVR_PREFIX . 'cdn_settings',
            STATICDELIVR_PREFIX . 'images_enabled',
            array(
                'type'              => 'boolean',
                'sanitize_callback' => 'absint',
                'default'           => false,
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
        ?>
        <div class="wrap">
            <h1>StaticDelivr CDN Settings</h1>
            <p>Optimize your WordPress site by delivering assets through the <a href="https://staticdelivr.com" target="_blank">StaticDelivr CDN</a>.</p>
            
            <form method="post" action="options.php">
                <?php
                settings_fields(STATICDELIVR_PREFIX . 'cdn_settings');
                do_settings_sections(STATICDELIVR_PREFIX . 'cdn_settings');
                ?>
                
                <h2 class="title">Assets Optimization (CSS &amp; JavaScript)</h2>
                <p class="description">Rewrite URLs of WordPress core files, themes, and plugins to use StaticDelivr CDN.</p>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Enable Assets CDN</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'assets_enabled'); ?>" value="1" <?php checked(1, get_option(STATICDELIVR_PREFIX . 'assets_enabled', false)); ?> />
                                Enable CDN for CSS &amp; JavaScript files
                            </label>
                            <p class="description">Serves WordPress core, theme, and plugin assets from StaticDelivr CDN for faster loading.</p>
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
                                <input type="checkbox" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'images_enabled'); ?>" value="1" <?php checked(1, get_option(STATICDELIVR_PREFIX . 'images_enabled', false)); ?> />
                                Enable CDN for images
                            </label>
                            <p class="description">Optimizes and delivers all images through StaticDelivr CDN with automatic format conversion and compression.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Image Quality</th>
                        <td>
                            <input type="number" name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'image_quality'); ?>" value="<?php echo esc_attr(get_option(STATICDELIVR_PREFIX . 'image_quality', 80)); ?>" min="1" max="100" step="1" class="small-text" />
                            <p class="description">Quality level for optimized images (1-100). Lower values = smaller files. Recommended: 75-85 for best balance of quality and size.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Image Format</th>
                        <td>
                            <?php $current_format = get_option(STATICDELIVR_PREFIX . 'image_format', 'webp'); ?>
                            <select name="<?php echo esc_attr(STATICDELIVR_PREFIX . 'image_format'); ?>">
                                <option value="auto" <?php selected($current_format, 'auto'); ?>>Auto (Best for browser)</option>
                                <option value="webp" <?php selected($current_format, 'webp'); ?>>WebP (Recommended)</option>
                                <option value="avif" <?php selected($current_format, 'avif'); ?>>AVIF (Best compression)</option>
                                <option value="jpeg" <?php selected($current_format, 'jpeg'); ?>>JPEG</option>
                                <option value="png" <?php selected($current_format, 'png'); ?>>PNG</option>
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
                    <p style="margin-bottom: 5px;"><code>https://example.com/wp-includes/js/jquery.js</code></p>
                    <p style="margin-bottom: 15px;">→ <code>https://cdn.staticdelivr.com/wp/core/trunk/wp-includes/js/jquery.js</code></p>
                    
                    <h4>Images</h4>
                    <p style="margin-bottom: 5px;"><code>https://example.com/wp-content/uploads/photo.jpg</code> (2MB)</p>
                    <p style="margin-bottom: 0;">→ <code>https://cdn.staticdelivr.com/img/...?q=80&amp;f=webp</code> (~20KB)</p>
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
        </div>
        <?php
    }
}

new StaticDelivr();
