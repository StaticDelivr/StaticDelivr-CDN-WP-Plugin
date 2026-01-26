<?php
/**
 * StaticDelivr CDN Google Fonts Handler
 *
 * Handles proxying Google Fonts through StaticDelivr CDN
 * for privacy-first, GDPR-compliant font delivery.
 *
 * @package StaticDelivr
 * @since   1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Google_Fonts
 *
 * Proxies Google Fonts through StaticDelivr for privacy.
 *
 * @since 1.5.0
 */
class StaticDelivr_Google_Fonts {

	/**
	 * Flag to track if output buffering is active.
	 *
	 * @var bool
	 */
	private $output_buffering_started = false;

	/**
	 * Singleton instance.
	 *
	 * @var StaticDelivr_Google_Fonts|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StaticDelivr_Google_Fonts
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
	 * Sets up hooks for Google Fonts proxying.
	 */
	private function __construct() {
		// Google Fonts hooks.
		add_filter( 'style_loader_src', array( $this, 'rewrite_google_fonts_enqueued' ), 1, 2 );
		add_filter( 'wp_resource_hints', array( $this, 'filter_resource_hints' ), 10, 2 );

		// Output buffer for hardcoded Google Fonts in HTML.
		add_action( 'template_redirect', array( $this, 'start_google_fonts_output_buffer' ), -999 );
		add_action( 'shutdown', array( $this, 'end_google_fonts_output_buffer' ), 999 );
	}

	/**
	 * Check if Google Fonts rewriting is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', true );
	}

	/**
	 * Check if a URL is a Google Fonts URL.
	 *
	 * @param string $url The URL to check.
	 * @return bool
	 */
	public function is_google_fonts_url( $url ) {
		if ( empty( $url ) ) {
			return false;
		}
		return ( strpos( $url, 'fonts.googleapis.com' ) !== false || strpos( $url, 'fonts.gstatic.com' ) !== false );
	}

	/**
	 * Rewrite Google Fonts URL to use StaticDelivr proxy.
	 *
	 * @param string $url The original URL.
	 * @return string The rewritten URL or original.
	 */
	public function rewrite_google_fonts_url( $url ) {
		if ( empty( $url ) ) {
			return $url;
		}

		// Don't rewrite if already a StaticDelivr URL.
		if ( strpos( $url, 'cdn.staticdelivr.com' ) !== false ) {
			return $url;
		}

		// Rewrite fonts.googleapis.com to StaticDelivr.
		if ( strpos( $url, 'fonts.googleapis.com' ) !== false ) {
			return str_replace( 'fonts.googleapis.com', 'cdn.staticdelivr.com/gfonts', $url );
		}

		// Rewrite fonts.gstatic.com to StaticDelivr (font files).
		if ( strpos( $url, 'fonts.gstatic.com' ) !== false ) {
			return str_replace( 'fonts.gstatic.com', 'cdn.staticdelivr.com/gstatic-fonts', $url );
		}

		return $url;
	}

	/**
	 * Rewrite enqueued Google Fonts stylesheets.
	 *
	 * @param string $src    The stylesheet source URL.
	 * @param string $handle The stylesheet handle.
	 * @return string
	 */
	public function rewrite_google_fonts_enqueued( $src, $handle ) {
		if ( ! $this->is_enabled() ) {
			return $src;
		}

		if ( $this->is_google_fonts_url( $src ) ) {
			return $this->rewrite_google_fonts_url( $src );
		}

		return $src;
	}

	/**
	 * Filter resource hints to update Google Fonts preconnect/prefetch.
	 *
	 * @param array  $urls          Array of URLs.
	 * @param string $relation_type The relation type.
	 * @return array
	 */
	public function filter_resource_hints( $urls, $relation_type ) {
		if ( ! $this->is_enabled() ) {
			return $urls;
		}

		if ( 'dns-prefetch' !== $relation_type && 'preconnect' !== $relation_type ) {
			return $urls;
		}

		$staticdelivr_added = false;

		foreach ( $urls as $key => $url ) {
			$href = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;

			if ( strpos( $href, 'fonts.googleapis.com' ) !== false ||
				strpos( $href, 'fonts.gstatic.com' ) !== false ) {
				unset( $urls[ $key ] );
				$staticdelivr_added = true;
			}
		}

		// Add StaticDelivr preconnect if we removed Google Fonts hints.
		if ( $staticdelivr_added ) {
			if ( 'preconnect' === $relation_type ) {
				$urls[] = array(
					'href'        => STATICDELIVR_CDN_BASE,
					'crossorigin' => 'anonymous',
				);
			} else {
				$urls[] = STATICDELIVR_CDN_BASE;
			}
		}

		return array_values( $urls );
	}

	/**
	 * Start output buffering to catch Google Fonts in HTML output.
	 *
	 * @return void
	 */
	public function start_google_fonts_output_buffer() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Don't buffer non-HTML requests.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}

		if ( is_feed() ) {
			return;
		}

		$this->output_buffering_started = true;
		ob_start();
	}

	/**
	 * End output buffering and process Google Fonts URLs.
	 *
	 * @return void
	 */
	public function end_google_fonts_output_buffer() {
		if ( ! $this->output_buffering_started ) {
			return;
		}

		$html = ob_get_clean();

		if ( ! empty( $html ) ) {
			echo $this->process_google_fonts_buffer( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Process the output buffer to rewrite Google Fonts URLs.
	 *
	 * @param string $html The HTML output.
	 * @return string
	 */
	public function process_google_fonts_buffer( $html ) {
		if ( empty( $html ) ) {
			return $html;
		}

		$html = str_replace( 'fonts.googleapis.com', 'cdn.staticdelivr.com/gfonts', $html );
		$html = str_replace( 'fonts.gstatic.com', 'cdn.staticdelivr.com/gstatic-fonts', $html );

		return $html;
	}
}
