<?php
/**
 * StaticDelivr CDN Fallback Handler
 *
 * Handles the JavaScript fallback system that automatically
 * retries failed CDN resources from the origin server.
 *
 * @package StaticDelivr
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Fallback
 *
 * Manages the client-side fallback script for CDN failures.
 *
 * @since 1.1.0
 */
class StaticDelivr_Fallback {

    /**
     * Ensures the fallback script is only enqueued once per request.
     *
     * @var bool
     */
    private $fallback_script_enqueued = false;

    /**
     * Assets handler instance.
     *
     * @var StaticDelivr_Assets
     */
    private $assets;

    /**
     * Images handler instance.
     *
     * @var StaticDelivr_Images
     */
    private $images;

    /**
     * Singleton instance.
     *
     * @var StaticDelivr_Fallback|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return StaticDelivr_Fallback
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
     * Sets up hooks for fallback script injection.
     */
    private function __construct() {
        $this->assets = StaticDelivr_Assets::get_instance();
        $this->images = StaticDelivr_Images::get_instance();

        // Inject fallback script early in the head.
        add_action( 'wp_head', array( $this, 'inject_fallback_script_early' ), 1 );
        add_action( 'admin_head', array( $this, 'inject_fallback_script_early' ), 1 );
    }

    /**
     * Inject the fallback script directly in the head.
     *
     * @return void
     */
    public function inject_fallback_script_early() {
        if ( $this->fallback_script_enqueued ||
            ( ! $this->assets->is_enabled() && ! $this->images->is_enabled() ) ) {
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

        $debug_enabled = get_option( STATICDELIVR_PREFIX . 'debug_mode', false ) ? 'true' : 'false';

        $script = '(function(){' . "\n";
        $script .= "    var SD_DEBUG = {$debug_enabled};\n";
        $script .= "    var SD_AJAX_URL = '%s';\n";
        $script .= "    var SD_NONCE = '%s';\n";
        $script .= "\n";
        $script .= "    function log() {\n";
        $script .= "        if (SD_DEBUG && console && console.log) {\n";
        $script .= "            console.log.apply(console, ['[StaticDelivr]'].concat(Array.prototype.slice.call(arguments)));\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function reportFailure(type, url, original) {\n";
        $script .= "        try {\n";
        $script .= "            var data = new FormData();\n";
        $script .= "            data.append('action', 'staticdelivr_report_failure');\n";
        $script .= "            data.append('nonce', SD_NONCE);\n";
        $script .= "            data.append('type', type);\n";
        $script .= "            data.append('url', url);\n";
        $script .= "            data.append('original', original || '');\n";
        $script .= "\n";
        $script .= "            if (navigator.sendBeacon) {\n";
        $script .= "                navigator.sendBeacon(SD_AJAX_URL, data);\n";
        $script .= "            } else {\n";
        $script .= "                var xhr = new XMLHttpRequest();\n";
        $script .= "                xhr.open('POST', SD_AJAX_URL, true);\n";
        $script .= "                xhr.send(data);\n";
        $script .= "            }\n";
        $script .= "            log('Reported failure:', type, url);\n";
        $script .= "        } catch(e) {\n";
        $script .= "            log('Failed to report:', e);\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function copyAttributes(from, to) {\n";
        $script .= "        if (!from || !to || !from.attributes) return;\n";
        $script .= "        for (var i = 0; i < from.attributes.length; i++) {\n";
        $script .= "            var attr = from.attributes[i];\n";
        $script .= "            if (!attr || !attr.name) continue;\n";
        $script .= "            if (attr.name === 'src' || attr.name === 'href' || attr.name === 'data-original-src' || attr.name === 'data-original-href') continue;\n";
        $script .= "            try {\n";
        $script .= "                to.setAttribute(attr.name, attr.value);\n";
        $script .= "            } catch(e) {}\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function extractOriginalFromCdnUrl(cdnUrl) {\n";
        $script .= "        if (!cdnUrl) return null;\n";
        $script .= "        if (cdnUrl.indexOf('cdn.staticdelivr.com') === -1) return null;\n";
        $script .= "        try {\n";
        $script .= "            var urlObj = new URL(cdnUrl);\n";
        $script .= "            var originalUrl = urlObj.searchParams.get('url');\n";
        $script .= "            if (originalUrl) {\n";
        $script .= "                log('Extracted original URL from query param:', originalUrl);\n";
        $script .= "                return originalUrl;\n";
        $script .= "            }\n";
        $script .= "        } catch(e) {\n";
        $script .= "            log('Failed to parse CDN URL:', cdnUrl, e);\n";
        $script .= "        }\n";
        $script .= "        return null;\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function handleError(event) {\n";
        $script .= "        var el = event.target || event.srcElement;\n";
        $script .= "        if (!el) return;\n";
        $script .= "\n";
        $script .= "        var tagName = el.tagName ? el.tagName.toUpperCase() : '';\n";
        $script .= "        if (!tagName) return;\n";
        $script .= "\n";
        $script .= "        // Only handle elements we care about\n";
        $script .= "        if (tagName !== 'SCRIPT' && tagName !== 'LINK' && tagName !== 'IMG') return;\n";
        $script .= "\n";
        $script .= "        // Get the failed URL\n";
        $script .= "        var failedUrl = '';\n";
        $script .= "        if (tagName === 'IMG') failedUrl = el.src || el.currentSrc || '';\n";
        $script .= "        else if (tagName === 'SCRIPT') failedUrl = el.src || '';\n";
        $script .= "        else if (tagName === 'LINK') failedUrl = el.href || '';\n";
        $script .= "\n";
        $script .= "        // Only handle StaticDelivr URLs\n";
        $script .= "        if (failedUrl.indexOf('cdn.staticdelivr.com') === -1) return;\n";
        $script .= "\n";
        $script .= "        log('Caught error on:', tagName, failedUrl);\n";
        $script .= "\n";
        $script .= "        // Prevent double-processing\n";
        $script .= "        if (el.getAttribute && el.getAttribute('data-sd-fallback') === 'done') return;\n";
        $script .= "\n";
        $script .= "        // Get original URL\n";
        $script .= "        var original = el.getAttribute('data-original-src') || el.getAttribute('data-original-href');\n";
        $script .= "        if (!original) original = extractOriginalFromCdnUrl(failedUrl);\n";
        $script .= "\n";
        $script .= "        if (!original) {\n";
        $script .= "            log('Could not determine original URL for:', failedUrl);\n";
        $script .= "            return;\n";
        $script .= "        }\n";
        $script .= "\n";
        $script .= "        el.setAttribute('data-sd-fallback', 'done');\n";
        $script .= "        log('Falling back to origin:', tagName, original);\n";
        $script .= "\n";
        $script .= "        // Report the failure\n";
        $script .= "        var reportType = (tagName === 'IMG') ? 'image' : 'asset';\n";
        $script .= "        reportFailure(reportType, failedUrl, original);\n";
        $script .= "\n";
        $script .= "        if (tagName === 'SCRIPT') {\n";
        $script .= "            var newScript = document.createElement('script');\n";
        $script .= "            newScript.src = original;\n";
        $script .= "            newScript.async = el.async;\n";
        $script .= "            newScript.defer = el.defer;\n";
        $script .= "            if (el.type) newScript.type = el.type;\n";
        $script .= "            if (el.noModule) newScript.noModule = true;\n";
        $script .= "            if (el.crossOrigin) newScript.crossOrigin = el.crossOrigin;\n";
        $script .= "            copyAttributes(el, newScript);\n";
        $script .= "            if (el.parentNode) {\n";
        $script .= "                el.parentNode.insertBefore(newScript, el.nextSibling);\n";
        $script .= "                el.parentNode.removeChild(el);\n";
        $script .= "            }\n";
        $script .= "            log('Script fallback complete:', original);\n";
        $script .= "\n";
        $script .= "        } else if (tagName === 'LINK') {\n";
        $script .= "            el.href = original;\n";
        $script .= "            log('Stylesheet fallback complete:', original);\n";
        $script .= "\n";
        $script .= "        } else if (tagName === 'IMG') {\n";
        $script .= "            // Handle srcset first\n";
        $script .= "            if (el.srcset) {\n";
        $script .= "                var newSrcset = el.srcset.split(',').map(function(entry) {\n";
        $script .= "                    var parts = entry.trim().split(/\\s+/);\n";
        $script .= "                    var url = parts[0];\n";
        $script .= "                    var descriptor = parts.slice(1).join(' ');\n";
        $script .= "                    var extracted = extractOriginalFromCdnUrl(url);\n";
        $script .= "                    if (extracted) url = extracted;\n";
        $script .= "                    return descriptor ? url + ' ' + descriptor : url;\n";
        $script .= "                }).join(', ');\n";
        $script .= "                el.srcset = newSrcset;\n";
        $script .= "            }\n";
        $script .= "            el.src = original;\n";
        $script .= "            log('Image fallback complete:', original);\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    // Capture errors in capture phase\n";
        $script .= "    window.addEventListener('error', handleError, true);\n";
        $script .= "\n";
        $script .= "    log('Fallback script initialized (v%s)');\n";
        $script .= '})();';

        return sprintf( $script, esc_js( $ajax_url ), esc_js( $nonce ), STATICDELIVR_VERSION );
    }
}
