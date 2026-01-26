<?php
/**
 * StaticDelivr CDN DevTools Handler
 *
 * Provides client-side diagnostic tools for developers and support.
 * Accessible via window.staticDelivr.status() in the browser console.
 *
 * @package StaticDelivr
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_DevTools
 *
 * Injects diagnostic scripts for console debugging.
 *
 * @since 2.5.0
 */
class StaticDelivr_DevTools {

    /**
     * Singleton instance.
     *
     * @var StaticDelivr_DevTools|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return StaticDelivr_DevTools
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Print in footer to avoid blocking render
        add_action( 'wp_footer', array( $this, 'print_console_tools' ) );
        add_action( 'admin_footer', array( $this, 'print_console_tools' ) );
    }

    /**
     * Print the console diagnostic script.
     *
     * @return void
     */
    public function print_console_tools() {
        // Gather current settings
        $settings = array(
            'Version'          => STATICDELIVR_VERSION,
            'Assets CDN'       => get_option( STATICDELIVR_PREFIX . 'assets_enabled', true ) ? 'Enabled' : 'Disabled',
            'Images CDN'       => get_option( STATICDELIVR_PREFIX . 'images_enabled', true ) ? 'Enabled' : 'Disabled',
            'Image Quality'    => get_option( STATICDELIVR_PREFIX . 'image_quality', 80 ),
            'Image Format'     => strtoupper( get_option( STATICDELIVR_PREFIX . 'image_format', 'webp' ) ),
            'Google Fonts'     => get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', true ) ? 'Enabled' : 'Disabled',
            'Debug Mode'       => get_option( STATICDELIVR_PREFIX . 'debug_mode', false ) ? 'ON' : 'OFF',
            'Localhost Bypass' => get_option( STATICDELIVR_PREFIX . 'bypass_localhost', false ) ? 'ON' : 'OFF',
        );

        ?>
        <script>
        (function() {
            window.staticDelivr = {
                status: function() {
                    console.group(
                        '%c StaticDelivr ',
                        'background: #333; color: #fff; border-radius: 3px 0 0 3px; padding: 2px 5px; font-weight: 600;',
                        'Diagnostic'
                    );
                    console.table(<?php echo wp_json_encode( $settings ); ?>);
                    console.log('To report an issue, take a screenshot of this table.');
                    console.groupEnd();
                },
                reset: function() {
                    document.querySelectorAll('[data-sd-fallback]').forEach(function(el) {
                        el.removeAttribute('data-sd-fallback');
                    });
                    console.log('✅ Fallback states cleared. Network errors will now re-trigger.');
                }
            };
            // Announce availability in verbose mode only
            if (<?php echo $settings['Debug Mode'] === 'ON' ? 'true' : 'false'; ?>) {
                console.log('[StaticDelivr] Debug tools available. Run window.staticDelivr.status()');
            }
        })();
        </script>
        <?php
    }
}