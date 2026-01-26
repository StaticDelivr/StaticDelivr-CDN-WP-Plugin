<?php
/**
 * StaticDelivr CDN Assets Handler
 *
 * Handles URL rewriting for CSS and JavaScript assets
 * (WordPress core, themes, and plugins) to serve via CDN.
 *
 * @package StaticDelivr
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Assets
 *
 * Rewrites CSS and JavaScript asset URLs to use StaticDelivr CDN.
 *
 * @since 1.0.0
 */
class StaticDelivr_Assets {

	/**
	 * Stores original asset URLs by handle for fallback usage.
	 *
	 * @var array<string, string>
	 */
	private $original_sources = array();

	/**
	 * Cache for plugin/theme versions to avoid repeated filesystem work per request.
	 *
	 * @var array<string, string>
	 */
	private $version_cache = array();

	/**
	 * Cached WordPress version.
	 *
	 * @var string|null
	 */
	private $wp_version_cache = null;

	/**
	 * Verification instance.
	 *
	 * @var StaticDelivr_Verification
	 */
	private $verification;

	/**
	 * Singleton instance.
	 *
	 * @var StaticDelivr_Assets|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StaticDelivr_Assets
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
	 * Sets up hooks for asset rewriting.
	 */
	private function __construct() {
		$this->verification = StaticDelivr_Verification::get_instance();

		// CSS/JS rewriting hooks.
		add_filter( 'style_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
		add_filter( 'script_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
		add_filter( 'script_loader_tag', array( $this, 'inject_script_original_attribute' ), 10, 3 );
		add_filter( 'style_loader_tag', array( $this, 'inject_style_original_attribute' ), 10, 4 );
	}

	/**
	 * Check if assets optimization is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( STATICDELIVR_PREFIX . 'assets_enabled', true );
	}

	/**
	 * Get the current WordPress version (cached).
	 *
	 * Extracts clean version number from development/RC versions.
	 *
	 * @return string The WordPress version (e.g., "6.9" or "6.9.1").
	 */
	public function get_wp_version() {
		if ( null !== $this->wp_version_cache ) {
			return $this->wp_version_cache;
		}

		$raw_version = get_bloginfo( 'version' );

		// Extract just the version number from development versions.
		if ( preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $raw_version, $matches ) ) {
			$this->wp_version_cache = $matches[1];
		} else {
			$this->wp_version_cache = $raw_version;
		}

		return $this->wp_version_cache;
	}

	/**
	 * Extract the clean WordPress path from a given URL path.
	 *
	 * @param string $path The original path.
	 * @return string The extracted WordPress path or the original path.
	 */
	private function extract_wp_path( $path ) {
		$wp_patterns = array( 'wp-includes/', 'wp-content/' );
		foreach ( $wp_patterns as $pattern ) {
			$index = strpos( $path, $pattern );
			if ( false !== $index ) {
				return substr( $path, $index );
			}
		}
		return $path;
	}

	/**
	 * Get theme version by stylesheet (folder name), cached.
	 *
	 * @param string $theme_slug Theme folder name.
	 * @return string Theme version or empty string.
	 */
	public function get_theme_version( $theme_slug ) {
		$key = 'theme:' . $theme_slug;
		if ( isset( $this->version_cache[ $key ] ) ) {
			return $this->version_cache[ $key ];
		}
		$theme                       = wp_get_theme( $theme_slug );
		$version                     = (string) $theme->get( 'Version' );
		$this->version_cache[ $key ] = $version;
		return $version;
	}

	/**
	 * Get plugin version by slug (folder name), cached.
	 *
	 * @param string $plugin_slug Plugin folder name.
	 * @return string Plugin version or empty string.
	 */
	public function get_plugin_version( $plugin_slug ) {
		$key = 'plugin:' . $plugin_slug;
		if ( isset( $this->version_cache[ $key ] ) ) {
			return $this->version_cache[ $key ];
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( strpos( $plugin_file, $plugin_slug . '/' ) === 0 || $plugin_file === $plugin_slug . '.php' ) {
				$version                     = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
				$this->version_cache[ $key ] = $version;
				return $version;
			}
		}

		$this->version_cache[ $key ] = '';
		return '';
	}

	/**
	 * Rewrite asset URL to use StaticDelivr CDN.
	 *
	 * Only rewrites URLs for assets that exist on wordpress.org.
	 *
	 * @param string $src    The original source URL.
	 * @param string $handle The resource handle.
	 * @return string The modified URL or original if not rewritable.
	 */
	public function rewrite_url( $src, $handle ) {
		// Check if assets optimization is enabled.
		if ( ! $this->is_enabled() ) {
			return $src;
		}

		$parsed_url = wp_parse_url( $src );

		// Extract the clean WordPress path.
		if ( ! isset( $parsed_url['path'] ) ) {
			return $src;
		}

		$clean_path = $this->extract_wp_path( $parsed_url['path'] );

		// Rewrite WordPress core files - always available on CDN.
		if ( strpos( $clean_path, 'wp-includes/' ) === 0 ) {
			$wp_version = $this->get_wp_version();
			$rewritten  = sprintf(
				'%s/wp/core/tags/%s/%s',
				STATICDELIVR_CDN_BASE,
				$wp_version,
				ltrim( $clean_path, '/' )
			);
			$this->remember_original_source( $handle, $src );
			return $rewritten;
		}

		// Rewrite theme and plugin URLs.
		if ( strpos( $clean_path, 'wp-content/' ) === 0 ) {
			$path_parts = explode( '/', $clean_path );

			if ( in_array( 'themes', $path_parts, true ) ) {
				return $this->maybe_rewrite_theme_url( $src, $handle, $path_parts );
			}

			if ( in_array( 'plugins', $path_parts, true ) ) {
				return $this->maybe_rewrite_plugin_url( $src, $handle, $path_parts );
			}
		}

		return $src;
	}

	/**
	 * Attempt to rewrite a theme asset URL.
	 *
	 * Only rewrites if theme exists on wordpress.org.
	 *
	 * @param string $src        Original source URL.
	 * @param string $handle     Resource handle.
	 * @param array  $path_parts URL path parts.
	 * @return string Rewritten URL or original.
	 */
	private function maybe_rewrite_theme_url( $src, $handle, $path_parts ) {
		$themes_index = array_search( 'themes', $path_parts, true );
		$theme_slug   = isset( $path_parts[ $themes_index + 1 ] ) ? $path_parts[ $themes_index + 1 ] : '';

		if ( empty( $theme_slug ) ) {
			return $src;
		}

		// Check if theme is on wordpress.org.
		if ( ! $this->verification->is_asset_on_wporg( 'theme', $theme_slug ) ) {
			return $src; // Not on wordpress.org - serve locally.
		}

		$version = $this->get_theme_version( $theme_slug );
		if ( empty( $version ) ) {
			return $src;
		}

		// For child themes, the URL already points to correct theme folder.
		// The is_asset_on_wporg check handles parent theme verification.
		$file_path = implode( '/', array_slice( $path_parts, $themes_index + 2 ) );

		$rewritten = sprintf(
			'%s/wp/themes/%s/%s/%s',
			STATICDELIVR_CDN_BASE,
			$theme_slug,
			$version,
			$file_path
		);

		$this->remember_original_source( $handle, $src );
		return $rewritten;
	}

	/**
	 * Attempt to rewrite a plugin asset URL.
	 *
	 * Only rewrites if plugin exists on wordpress.org.
	 *
	 * @param string $src        Original source URL.
	 * @param string $handle     Resource handle.
	 * @param array  $path_parts URL path parts.
	 * @return string Rewritten URL or original.
	 */
	private function maybe_rewrite_plugin_url( $src, $handle, $path_parts ) {
		$plugins_index = array_search( 'plugins', $path_parts, true );
		$plugin_slug   = isset( $path_parts[ $plugins_index + 1 ] ) ? $path_parts[ $plugins_index + 1 ] : '';

		if ( empty( $plugin_slug ) ) {
			return $src;
		}

		// Check if plugin is on wordpress.org.
		if ( ! $this->verification->is_asset_on_wporg( 'plugin', $plugin_slug ) ) {
			return $src; // Not on wordpress.org - serve locally.
		}

		$version = $this->get_plugin_version( $plugin_slug );
		if ( empty( $version ) ) {
			return $src;
		}

		$file_path = implode( '/', array_slice( $path_parts, $plugins_index + 2 ) );

		$rewritten = sprintf(
			'%s/wp/plugins/%s/tags/%s/%s',
			STATICDELIVR_CDN_BASE,
			$plugin_slug,
			$version,
			$file_path
		);

		$this->remember_original_source( $handle, $src );
		return $rewritten;
	}

	/**
	 * Track the original asset URL for fallback purposes.
	 *
	 * @param string $handle Asset handle.
	 * @param string $src    Original URL.
	 * @return void
	 */
	private function remember_original_source( $handle, $src ) {
		if ( empty( $handle ) || empty( $src ) ) {
			return;
		}
		if ( ! isset( $this->original_sources[ $handle ] ) ) {
			$this->original_sources[ $handle ] = $src;
		}
	}

	/**
	 * Get original source URL by handle.
	 *
	 * @param string $handle Asset handle.
	 * @return string|null Original URL or null.
	 */
	public function get_original_source( $handle ) {
		return isset( $this->original_sources[ $handle ] ) ? $this->original_sources[ $handle ] : null;
	}

	/**
	 * Inject data-original-src attribute into rewritten script tags.
	 *
	 * @param string $tag    Complete script tag HTML.
	 * @param string $handle Asset handle.
	 * @param string $src    Final script src.
	 * @return string Modified script tag.
	 */
	public function inject_script_original_attribute( $tag, $handle, $src ) {
		if ( empty( $this->original_sources[ $handle ] ) || strpos( $tag, 'data-original-src=' ) !== false ) {
			return $tag;
		}

		$original = esc_attr( $this->original_sources[ $handle ] );
		return preg_replace( '/(<script\b)/i', '$1 data-original-src="' . $original . '"', $tag, 1 );
	}

	/**
	 * Inject data-original-href attribute into rewritten stylesheet link tags.
	 *
	 * @param string $html   Complete link tag HTML.
	 * @param string $handle Asset handle.
	 * @param string $href   Final stylesheet href.
	 * @param string $media  Media attribute.
	 * @return string Modified link tag.
	 */
	public function inject_style_original_attribute( $html, $handle, $href, $media ) {
		if ( empty( $this->original_sources[ $handle ] ) || strpos( $html, 'data-original-href=' ) !== false ) {
			return $html;
		}

		$original = esc_attr( $this->original_sources[ $handle ] );
		return str_replace( '<link', '<link data-original-href="' . $original . '"', $html );
	}
}
