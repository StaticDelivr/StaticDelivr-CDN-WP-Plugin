<?php
/**
 * StaticDelivr CDN Image Optimization Handler
 *
 * Handles image optimization and CDN delivery for uploaded images.
 * Rewrites image URLs to use StaticDelivr's image optimization service.
 *
 * @package StaticDelivr
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Images
 *
 * Handles image optimization and URL rewriting for the CDN.
 *
 * @since 1.2.0
 */
class StaticDelivr_Images {

    /**
     * Supported image extensions for optimization.
     *
     * @var array<int, string>
     */
    private $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff' );

    /**
     * Failure tracker instance.
     *
     * @var StaticDelivr_Failure_Tracker
     */
    private $failure_tracker;

    /**
     * Singleton instance.
     *
     * @var StaticDelivr_Images|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return StaticDelivr_Images
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
     * Sets up hooks for image optimization.
     */
    private function __construct() {
        $this->failure_tracker = StaticDelivr_Failure_Tracker::get_instance();

        /**
         * IMAGE REWRITING ARCHITECTURE NOTE:
         * We do NOT hook into 'wp_get_attachment_url'.
         *
         * Hooking into the base attachment URL causes WordPress core logic (like image_downsize)
         * to attempt to calculate thumbnail paths by editing our complex CDN query string.
         * This results in mangled "Malformed" URLs. 
         *
         * By only hooking into final output filters, we ensure WordPress performs its internal 
         * "Path Math" on clean local URLs before we convert the final result to CDN format.
         */
        add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_attachment_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_image_srcset' ), 10, 5 );
        add_filter( 'the_content', array( $this, 'rewrite_content_images' ), 99 );
        add_filter( 'post_thumbnail_html', array( $this, 'rewrite_thumbnail_html' ), 10, 5 );
        add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_attachment_url' ), 10, 2 );
    }

    /**
     * Check if image optimization is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        /**
         * Always disable for the admin dashboard and REST API requests.
         * Gutenberg loads media via the REST API, which is not caught by is_admin().
         * This prevents "Broken Image" icons and CORS issues in the post editor.
         */
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return false;
        }

        return (bool) get_option( STATICDELIVR_PREFIX . 'images_enabled', true );
    }

    /**
     * Get image optimization quality setting.
     *
     * @return int
     */
    public function get_image_quality() {
        return (int) get_option( STATICDELIVR_PREFIX . 'image_quality', 80 );
    }

    /**
     * Get image optimization format setting.
     *
     * @return string
     */
    public function get_image_format() {
        return get_option( STATICDELIVR_PREFIX . 'image_format', 'webp' );
    }

    /**
     * Check if a URL is routable from the internet.
     *
     * Localhost and private IPs cannot be fetched by the CDN.
     *
     * @param string $url URL to check.
     * @return bool True if URL is publicly accessible.
     */
    public function is_url_routable( $url ) {
        $bypass_localhost = get_option( STATICDELIVR_PREFIX . 'bypass_localhost', false );
        if ( $bypass_localhost ) {
            $this->debug_log( 'Localhost bypass enabled - treating URL as routable: ' . $url );
            return true;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );

        if ( empty( $host ) ) {
            $this->debug_log( 'URL has no host: ' . $url );
            return false;
        }

        $localhost_patterns = array( 'localhost', '127.0.0.1', '::1', '.local', '.test', '.dev', '.localhost' );

        foreach ( $localhost_patterns as $pattern ) {
            if ( $host === $pattern || substr( $host, -strlen( $pattern ) ) === $pattern ) {
                $this->debug_log( 'URL is localhost/dev environment: ' . $url );
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
    public function build_image_cdn_url( $original_url, $width = null, $height = null ) {
        if ( empty( $original_url ) ) {
            return $original_url;
        }

        $this->debug_log( '--- Processing Image URL ---' );
        $this->debug_log( 'Input URL: ' . $original_url );

        // 1. Skip if already a StaticDelivr URL
        if ( strpos( $original_url, 'cdn.staticdelivr.com' ) !== false ) {
            $this->debug_log( 'Skipped: URL already belongs to StaticDelivr domain.' );
            return $original_url;
        }

        // 2. Normalize relative/protocol-relative URLs
        if ( strpos( $original_url, '//' ) === 0 ) {
            $original_url = 'https:' . $original_url;
        } elseif ( strpos( $original_url, '/' ) === 0 ) {
            $original_url = home_url( $original_url );
        }

        // 3. Check routability (localhost check)
        if ( ! $this->is_url_routable( $original_url ) ) {
            $this->debug_log( 'Skipped: URL is not routable from the internet.' );
            return $original_url;
        }

        // 4. Check failure cache
        if ( $this->failure_tracker->is_image_blocked( $original_url ) ) {
            $this->debug_log( 'Skipped: URL is currently blocked due to previous CDN failures.' );
            return $original_url;
        }

        // 5. Validate extension
        $path = wp_parse_url( $original_url, PHP_URL_PATH );
        if ( ! $path ) {
            $this->debug_log( 'Skipped: Could not parse URL path.' );
            return $original_url;
        }
        
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->image_extensions, true ) ) {
            $this->debug_log( 'Skipped: Extension not supported for optimization (' . $extension . ').' );
            return $original_url;
        }

        // 6. Build the CDN URL
        $params = array( 'url' => $original_url );
        
        $quality = $this->get_image_quality();
        if ( $quality < 100 ) { $params['q'] = $quality; }

        $format = $this->get_image_format();
        if ( $format !== 'auto' ) { $params['format'] = $format; }

        if ( $width )  { $params['w'] = (int) $width; }
        if ( $height ) { $params['h'] = (int) $height; }

        $cdn_url = STATICDELIVR_IMG_CDN_BASE . '?' . http_build_query( $params );
        $this->debug_log( 'Success: CDN URL created -> ' . $cdn_url );

        return $cdn_url;
    }

    /**
     * Log debug message if debug mode is enabled.
     *
     * @param string $message Debug message to log.
     */
    private function debug_log( $message ) {
        if ( ! get_option( STATICDELIVR_PREFIX . 'debug_mode', false ) ) {
            return;
        }
        error_log( '[StaticDelivr Images] ' . $message );
    }

    /**
     * Rewrite attachment image src array.
     */
    public function rewrite_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $this->is_enabled() || ! $image || ! is_array( $image ) ) {
            return $image;
        }
        $image[0] = $this->build_image_cdn_url( $image[0], $image[1] ?? null, $image[2] ?? null );
        return $image;
    }

    /**
     * Rewrite image srcset URLs.
     */
    public function rewrite_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->is_enabled() || ! is_array( $sources ) ) {
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
     * Pass-through for the raw attachment URL.
     * We no longer rewrite here to prevent core Path Math corruption.
     */
    public function rewrite_attachment_url( $url, $attachment_id ) {
        return $url; 
    }

    /**
     * Rewrite image URLs in post content.
     */
    public function rewrite_content_images( $content ) {
        if ( ! $this->is_enabled() || empty( $content ) ) {
            return $content;
        }

        // Match img tags robustly (handles > symbols inside attributes like alt text)
        $content = preg_replace_callback( '/<img\s+.*?>/is', array( $this, 'rewrite_img_tag' ), $content );

        // Match background-image in inline styles.
        $content = preg_replace_callback(
            '/background(-image)?\s*:\s*url\s*\([\'"]?([^\'")\s]+)[\'"]?\)/i',
            array( $this, 'rewrite_background_image' ),
            $content
        );

        return $content;
    }

    /**
     * Rewrite a single img tag found in content.
     */
    public function rewrite_img_tag( $matches ) {
        $img_tag = $matches[0];

        if ( strpos( $img_tag, 'cdn.staticdelivr.com' ) !== false ) {
            return $img_tag;
        }

        if ( preg_match( '/src=["\']data:/i', $img_tag ) || preg_match( '/\.svg["\'\s>]/i', $img_tag ) ) {
            return $img_tag;
        }

        $width  = preg_match( '/width=["\']?(\d+)/i', $img_tag, $w_match ) ? (int)$w_match[1] : null;
        $height = preg_match( '/height=["\']?(\d+)/i', $img_tag, $h_match ) ? (int)$h_match[1] : null;

        // Smart Attribute Injection: If dimensions are missing, try to find them via the WP ID class
        if ( ( ! $width || ! $height ) && preg_match( '/wp-image-([0-9]+)/i', $img_tag, $id_match ) ) {
            $attachment_id = (int) $id_match[1];
            $meta = wp_get_attachment_metadata( $attachment_id );

            if ( $meta ) {
                if ( ! $width && ! empty( $meta['width'] ) ) {
                    $width = $meta['width'];
                    $img_tag = str_replace( '<img', '<img width="' . esc_attr( $width ) . '"', $img_tag );
                }
                if ( ! $height && ! empty( $meta['height'] ) ) {
                    $height = $meta['height'];
                    $img_tag = str_replace( '<img', '<img height="' . esc_attr( $height ) . '"', $img_tag );
                }
            }
        }

        return preg_replace_callback(
            '/src=["\']([^"\']+)["\']/i',
            function ( $src_match ) use ( $width, $height ) {
                $original_src = $src_match[1];
                $cdn_src      = $this->build_image_cdn_url( $original_src, $width, $height );

                if ( $cdn_src !== $original_src ) {
                    return 'src="' . esc_attr( $cdn_src ) . '" data-original-src="' . esc_attr( $original_src ) . '"';
                }
                return $src_match[0];
            },
            $img_tag
        );
    }

    /**
     * Rewrite background-image URL.
     */
    public function rewrite_background_image( $matches ) {
        $url = $matches[2];
        if ( strpos( $url, 'cdn.staticdelivr.com' ) !== false || strpos( $url, 'data:' ) === 0 ) {
            return $matches[0];
        }
        $cdn_url = $this->build_image_cdn_url( $url );
        return str_replace( $url, $cdn_url, $matches[0] );
    }

    /**
     * Pass-through for post thumbnails.
     * Handled more efficiently by attachment filters.
     */
    public function rewrite_thumbnail_html( $html, $post_id, $thumbnail_id, $size, $attr ) {
        return $html;
    }
}