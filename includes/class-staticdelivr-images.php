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

        // Image optimization hooks.
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
        // Check if localhost bypass is enabled for debugging.
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
                $this->debug_log( 'URL is localhost/dev environment (' . $pattern . '): ' . $url );
                return false;
            }
        }

        // Check for private IP ranges.
        $ip = gethostbyname( $host );
        if ( $ip !== $host ) {
            // Check if IP is in private range.
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                $this->debug_log( 'URL resolves to private/reserved IP (' . $ip . '): ' . $url );
                return false;
            }
        }

        $this->debug_log( 'URL is routable: ' . $url );
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
            $this->debug_log( 'Skipped: Empty URL' );
            return $original_url;
        }

        $this->debug_log( '=== Processing Image URL ===' );
        $this->debug_log( 'Original URL: ' . $original_url );

        // Check if it's a StaticDelivr URL.
        if ( strpos( $original_url, 'cdn.staticdelivr.com' ) !== false ) {
            // Check if it's a properly formed CDN URL with query parameters.
            if ( strpos( $original_url, '/img/images?' ) !== false && strpos( $original_url, 'url=' ) !== false ) {
                // This is a valid, properly formed CDN URL - skip it.
                $this->debug_log( 'Skipped: Already a valid StaticDelivr CDN URL' );
                return $original_url;
            } else {
                // This is a malformed/old CDN URL.
                // FIX: Do NOT try to guess the date or scan the DB. Fail gracefully to the original URL.
                $this->debug_log( 'WARNING: Detected malformed CDN URL. Cannot safely recover original path.' );
                return $original_url;
            }
        }

        // Ensure absolute URL.
        if ( strpos( $original_url, '//' ) === 0 ) {
            $original_url = 'https:' . $original_url;
            $this->debug_log( 'Normalized protocol-relative URL: ' . $original_url );
        } elseif ( strpos( $original_url, '/' ) === 0 ) {
            $original_url = home_url( $original_url );
            $this->debug_log( 'Normalized relative URL: ' . $original_url );
        }

        // Check if URL is routable (not localhost/private).
        if ( ! $this->is_url_routable( $original_url ) ) {
            $this->debug_log( 'Skipped: URL not routable (localhost/private network)' );
            return $original_url;
        }

        // Check failure cache.
        if ( $this->failure_tracker->is_image_blocked( $original_url ) ) {
            $this->debug_log( 'Skipped: URL in failure cache (previously failed to load from CDN)' );
            return $original_url;
        }

        // Validate it's an image URL.
        // FIX: Added null check for wp_parse_url result to prevent PHP 8 fatal errors.
        $path = wp_parse_url( $original_url, PHP_URL_PATH );
        if ( ! $path ) {
            $this->debug_log( 'Skipped: Malformed URL path' );
            return $original_url;
        }
        
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, $this->image_extensions, true ) ) {
            $this->debug_log( 'Skipped: Not an image extension (' . $extension . ')' );
            return $original_url;
        }

        $this->debug_log( 'Valid image extension: ' . $extension );

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

        $cdn_url = STATICDELIVR_IMG_CDN_BASE . '?' . http_build_query( $params );
        $this->debug_log( 'CDN URL created: ' . $cdn_url );
        $this->debug_log( 'Parameters: quality=' . $quality . ', format=' . $format . ', width=' . $width . ', height=' . $height );

        return $cdn_url;
    }

    /**
     * Log debug message if debug mode is enabled.
     *
     * @param string $message Debug message to log.
     * @return void
     */
    private function debug_log( $message ) {
        if ( ! get_option( STATICDELIVR_PREFIX . 'debug_mode', false ) ) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[StaticDelivr Images] ' . $message );
    }

    /**
     * Rewrite attachment image src array.
     *
     * @param array|false  $image         Image data array or false.
     * @param int          $attachment_id Attachment ID.
     * @param string|int[] $size          Requested image size.
     * @param bool         $icon          Whether to use icon.
     * @return array|false
     */
    public function rewrite_attachment_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $this->is_enabled() || ! $image || ! is_array( $image ) ) {
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
     * Rewrite attachment URL.
     *
     * @param string $url           The attachment URL.
     * @param int    $attachment_id Attachment ID.
     * @return string
     */
    public function rewrite_attachment_url( $url, $attachment_id ) {
        if ( ! $this->is_enabled() ) {
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
        if ( ! $this->is_enabled() || empty( $content ) ) {
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
    public function rewrite_img_tag( $matches ) {
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
    public function rewrite_background_image( $matches ) {
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
        if ( ! $this->is_enabled() || empty( $html ) ) {
            return $html;
        }

        // FIX: Use rewrite_content_images to properly parse HTML tags instead of treating the whole string as one img.
        return $this->rewrite_content_images( $html );
    }
}