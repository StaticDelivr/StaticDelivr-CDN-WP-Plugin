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
        $script .= "    function log() { if (SD_DEBUG && console) console.log.apply(console, ['[StaticDelivr]'].concat(Array.prototype.slice.call(arguments))); }\n";
        $script .= "\n";
        $script .= "    function reportFailure(type, url, original) {\n";
        $script .= "        try {\n";
        $script .= "            var data = new FormData();\n";
        $script .= "            data.append('action', 'staticdelivr_report_failure');\n";
        $script .= "            data.append('nonce', SD_NONCE);\n";
        $script .= "            data.append('type', type);\n";
        $script .= "            data.append('url', url);\n";
        $script .= "            data.append('original', original || '');\n";
        $script .= "            if (navigator.sendBeacon) navigator.sendBeacon(SD_AJAX_URL, data);\n";
        $script .= "            else { var xhr = new XMLHttpRequest(); xhr.open('POST', SD_AJAX_URL, true); xhr.send(data); }\n";
        $script .= "            log('Reported failure:', type, url);\n";
        $script .= "        } catch(e) { log('Failed to report:', e); }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function copyAttributes(from, to) {\n";
        $script .= "        if (!from || !to || !from.attributes) return;\n";
        $script .= "        for (var i = 0; i < from.attributes.length; i++) {\n";
        $script .= "            var attr = from.attributes[i];\n";
        $script .= "            if (!attr || !attr.name) continue;\n";
        $script .= "            if (attr.name === 'src' || attr.name === 'href' || attr.name === 'data-original-src' || attr.name === 'data-original-href') continue;\n";
        $script .= "            try { to.setAttribute(attr.name, attr.value); } catch(e) {}\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function extractOriginalFromCdnUrl(cdnUrl) {\n";
        $script .= "        if (!cdnUrl) return null;\n";
        $script .= "        if (cdnUrl.indexOf('cdn.staticdelivr.com') === -1) return null;\n";
        $script .= "        try {\n";
        $script .= "            var urlObj = new URL(cdnUrl);\n";
        $script .= "            return urlObj.searchParams.get('url');\n";
        $script .= "        } catch(e) { return null; }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    // --- CORE FIXER LOGIC ---\n";
        $script .= "    function performFallback(el, original, failedUrl) {\n";
        $script .= "        if (el.getAttribute('data-sd-fallback') === 'done') return;\n";
        $script .= "        el.setAttribute('data-sd-fallback', 'done');\n";
        $script .= "        log('Forcing fallback on:', original);\n";
        $script .= "\n";
        $script .= "        // CRITICAL: Remove srcset/loading to bypass browser interventions\n";
        $script .= "        el.removeAttribute('srcset');\n";
        $script .= "        el.removeAttribute('sizes');\n";
        $script .= "        el.removeAttribute('loading');\n";
        $script .= "        el.src = original;\n";
        $script .= "\n";
        $script .= "        if (failedUrl) reportFailure('image', failedUrl, original);\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    function handleError(event) {\n";
        $script .= "        var el = event.target || event.srcElement;\n";
        $script .= "        if (!el) return;\n";
        $script .= "\n";
        $script .= "        var tagName = el.tagName ? el.tagName.toUpperCase() : '';\n";
        $script .= "        if (!tagName) return;\n";
        $script .= "\n";
        $script .= "        var failedUrl = (tagName === 'IMG') ? (el.currentSrc || el.src) : (el.href || el.src);\n";
        $script .= "        if (!failedUrl || failedUrl.indexOf('cdn.staticdelivr.com') === -1) return;\n";
        $script .= "\n";
        $script .= "        log('Caught error on:', tagName, failedUrl);\n";
        $script .= "\n";
        $script .= "        var original = el.getAttribute('data-original-src') || el.getAttribute('data-original-href');\n";
        $script .= "        if (!original) original = extractOriginalFromCdnUrl(failedUrl);\n";
        $script .= "        if (!original) return;\n";
        $script .= "\n";
        $script .= "        if (tagName === 'IMG') {\n";
        $script .= "            performFallback(el, original, failedUrl);\n";
        $script .= "        } else if (tagName === 'SCRIPT') {\n";
        $script .= "            el.setAttribute('data-sd-fallback', 'done');\n";
        $script .= "            reportFailure('asset', failedUrl, original);\n";
        $script .= "            var newScript = document.createElement('script');\n";
        $script .= "            newScript.src = original;\n";
        $script .= "            copyAttributes(el, newScript);\n";
        $script .= "            if(el.parentNode) { el.parentNode.insertBefore(newScript, el.nextSibling); el.parentNode.removeChild(el); }\n";
        $script .= "        } else if (tagName === 'LINK') {\n";
        $script .= "            el.setAttribute('data-sd-fallback', 'done');\n";
        $script .= "            reportFailure('asset', failedUrl, original);\n";
        $script .= "            el.href = original;\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    // --- THE SWEEPER (Catches silent failures) ---\n";
        $script .= "    function scanForBrokenImages() {\n";
        $script .= "        var imgs = document.querySelectorAll('img');\n";
        $script .= "        for (var i = 0; i < imgs.length; i++) {\n";
        $script .= "            var img = imgs[i];\n";
        $script .= "            if (img.getAttribute('data-sd-fallback') === 'done') continue;\n";
        $script .= "            var src = img.currentSrc || img.src;\n";
        $script .= "            // If it's a CDN image AND it has 0 natural width (broken), force fix it\n";
        $script .= "            if (src && src.indexOf('cdn.staticdelivr.com') > -1) {\n";
        $script .= "                // If complete but 0 width (broken), fix it\n";
        $script .= "                if (img.complete && img.naturalWidth === 0) {\n";
        $script .= "                    log('Sweeper found silent failure:', src);\n";
        $script .= "                    var original = img.getAttribute('data-original-src') || extractOriginalFromCdnUrl(src);\n";
        $script .= "                    if (original) performFallback(img, original, src);\n";
        $script .= "                }\n";
        $script .= "            }\n";
        $script .= "        }\n";
        $script .= "    }\n";
        $script .= "\n";
        $script .= "    window.addEventListener('error', handleError, true);\n";
        $script .= "    window.addEventListener('load', function() { setTimeout(scanForBrokenImages, 2500); });\n"; // Run after lazy load might have failed
        $script .= "    log('Fallback script initialized (v%s)');\n";
        $script .= '})();';

        return sprintf( $script, esc_js( $ajax_url ), esc_js( $nonce ), STATICDELIVR_VERSION );
    }
}
