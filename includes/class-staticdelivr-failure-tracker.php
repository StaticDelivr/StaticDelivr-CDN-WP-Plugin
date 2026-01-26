<?php
/**
 * StaticDelivr CDN Failure Tracker
 *
 * Handles tracking and managing CDN resource failures.
 * When resources fail to load from CDN, they are tracked and
 * automatically served locally after reaching a failure threshold.
 *
 * @package StaticDelivr
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Failure_Tracker
 *
 * Tracks CDN failures and manages the failure cache.
 *
 * @since 1.7.0
 */
class StaticDelivr_Failure_Tracker {

	/**
	 * In-memory cache for failed resources.
	 *
	 * @var array|null
	 */
	private $failure_cache = null;

	/**
	 * Flag to track if failure cache was modified.
	 *
	 * @var bool
	 */
	private $failure_cache_dirty = false;

	/**
	 * Singleton instance.
	 *
	 * @var StaticDelivr_Failure_Tracker|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StaticDelivr_Failure_Tracker
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
	 * Sets up hooks for failure tracking.
	 */
	private function __construct() {
		// Save cache on shutdown if modified.
		add_action( 'shutdown', array( $this, 'maybe_save_failure_cache' ), 0 );

		// AJAX endpoint for failure reporting.
		add_action( 'wp_ajax_staticdelivr_report_failure', array( $this, 'ajax_report_failure' ) );
		add_action( 'wp_ajax_nopriv_staticdelivr_report_failure', array( $this, 'ajax_report_failure' ) );
	}

	/**
	 * Load failure cache from database.
	 *
	 * @return void
	 */
	private function load_failure_cache() {
		if ( null !== $this->failure_cache ) {
			return;
		}

		$cache = get_transient( STATICDELIVR_PREFIX . 'failed_resources' );

		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$this->failure_cache = wp_parse_args(
			$cache,
			array(
				'images' => array(),
				'assets' => array(),
			)
		);
	}

	/**
	 * Save failure cache if modified.
	 *
	 * @return void
	 */
	public function maybe_save_failure_cache() {
		if ( $this->failure_cache_dirty && null !== $this->failure_cache ) {
			set_transient(
				STATICDELIVR_PREFIX . 'failed_resources',
				$this->failure_cache,
				STATICDELIVR_FAILURE_CACHE_DURATION
			);
			$this->failure_cache_dirty = false;
		}
	}

	/**
	 * Generate a short hash for a URL.
	 *
	 * @param string $url The URL to hash.
	 * @return string 16-character hash.
	 */
	public function hash_url( $url ) {
		return substr( md5( $url ), 0, 16 );
	}

	/**
	 * Check if a resource has exceeded the failure threshold.
	 *
	 * @param string $type Resource type: 'image' or 'asset'.
	 * @param string $key  Resource identifier (URL hash or slug).
	 * @return bool True if should be blocked.
	 */
	public function is_resource_blocked( $type, $key ) {
		$this->load_failure_cache();

		$cache_key = ( 'image' === $type ) ? 'images' : 'assets';

		if ( ! isset( $this->failure_cache[ $cache_key ][ $key ] ) ) {
			return false;
		}

		$entry = $this->failure_cache[ $cache_key ][ $key ];

		// Check if entry has expired (shouldn't happen with transient, but safety check).
		if ( isset( $entry['last'] ) ) {
			$age = time() - (int) $entry['last'];
			if ( $age > STATICDELIVR_FAILURE_CACHE_DURATION ) {
				unset( $this->failure_cache[ $cache_key ][ $key ] );
				$this->failure_cache_dirty = true;
				return false;
			}
		}

		// Check threshold.
		$count = isset( $entry['count'] ) ? (int) $entry['count'] : 0;
		return $count >= STATICDELIVR_FAILURE_THRESHOLD;
	}

	/**
	 * Record a resource failure.
	 *
	 * @param string $type     Resource type: 'image' or 'asset'.
	 * @param string $key      Resource identifier.
	 * @param string $original Original URL for reference.
	 * @return void
	 */
	public function record_failure( $type, $key, $original = '' ) {
		$this->load_failure_cache();

		$cache_key = ( 'image' === $type ) ? 'images' : 'assets';
		$now       = time();

		if ( isset( $this->failure_cache[ $cache_key ][ $key ] ) ) {
			++$this->failure_cache[ $cache_key ][ $key ]['count'];
			$this->failure_cache[ $cache_key ][ $key ]['last'] = $now;
		} else {
			$this->failure_cache[ $cache_key ][ $key ] = array(
				'count'    => 1,
				'first'    => $now,
				'last'     => $now,
				'original' => $original,
			);
		}

		$this->failure_cache_dirty = true;
	}

	/**
	 * AJAX handler for failure reporting from client.
	 *
	 * @return void
	 */
	public function ajax_report_failure() {
		// Verify nonce.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'staticdelivr_failure_report' ) ) {
			wp_send_json_error( 'Invalid nonce', 403 );
		}

		$type     = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$original = isset( $_POST['original'] ) ? esc_url_raw( wp_unslash( $_POST['original'] ) ) : '';

		if ( empty( $type ) || empty( $url ) ) {
			wp_send_json_error( 'Missing parameters', 400 );
		}

		// Validate type.
		if ( ! in_array( $type, array( 'image', 'asset' ), true ) ) {
			wp_send_json_error( 'Invalid type', 400 );
		}

		// Generate key based on type.
		if ( 'image' === $type ) {
			$key = $this->hash_url( $original ? $original : $url );
		} else {
			// For assets, try to extract theme/plugin slug.
			$key = $this->extract_asset_key_from_url( $url );
			if ( empty( $key ) ) {
				$key = $this->hash_url( $url );
			}
		}

		$this->record_failure( $type, $key, $original ? $original : $url );
		$this->maybe_save_failure_cache();

		wp_send_json_success( array( 'recorded' => true ) );
	}

	/**
	 * Extract asset key (theme/plugin slug) from CDN URL.
	 *
	 * @param string $url CDN URL.
	 * @return string|null Asset key or null.
	 */
	public function extract_asset_key_from_url( $url ) {
		// Pattern: /wp/themes/{slug}/ or /wp/plugins/{slug}/.
		if ( preg_match( '#/wp/(themes|plugins)/([^/]+)/#', $url, $matches ) ) {
			return $matches[1] . ':' . $matches[2];
		}
		return null;
	}

	/**
	 * Check if an image URL is blocked due to previous failures.
	 *
	 * @param string $url Original image URL.
	 * @return bool True if blocked.
	 */
	public function is_image_blocked( $url ) {
		$key = $this->hash_url( $url );
		return $this->is_resource_blocked( 'image', $key );
	}

	/**
	 * Get failure statistics for admin display.
	 *
	 * @return array Failure statistics.
	 */
	public function get_failure_stats() {
		$this->load_failure_cache();

		$stats = array(
			'images' => array(
				'total'   => 0,
				'blocked' => 0,
				'items'   => array(),
			),
			'assets' => array(
				'total'   => 0,
				'blocked' => 0,
				'items'   => array(),
			),
		);

		foreach ( array( 'images', 'assets' ) as $type ) {
			if ( ! empty( $this->failure_cache[ $type ] ) ) {
				foreach ( $this->failure_cache[ $type ] as $key => $entry ) {
					++$stats[ $type ]['total'];
					$count = isset( $entry['count'] ) ? (int) $entry['count'] : 0;

					if ( $count >= STATICDELIVR_FAILURE_THRESHOLD ) {
						++$stats[ $type ]['blocked'];
					}

					$stats[ $type ]['items'][ $key ] = array(
						'count'    => $count,
						'blocked'  => $count >= STATICDELIVR_FAILURE_THRESHOLD,
						'original' => isset( $entry['original'] ) ? $entry['original'] : '',
						'last'     => isset( $entry['last'] ) ? $entry['last'] : 0,
					);
				}
			}
		}

		return $stats;
	}

	/**
	 * Clear the failure cache.
	 *
	 * @return void
	 */
	public function clear_failure_cache() {
		delete_transient( STATICDELIVR_PREFIX . 'failed_resources' );
		$this->failure_cache       = null;
		$this->failure_cache_dirty = false;
	}

	/**
	 * Clean up old failure cache entries.
	 *
	 * @return void
	 */
	public function cleanup_failure_cache() {
		$this->load_failure_cache();

		$now     = time();
		$changed = false;

		foreach ( array( 'images', 'assets' ) as $type ) {
			if ( ! empty( $this->failure_cache[ $type ] ) ) {
				foreach ( $this->failure_cache[ $type ] as $key => $entry ) {
					if ( isset( $entry['last'] ) ) {
						$age = $now - (int) $entry['last'];
						if ( $age > STATICDELIVR_FAILURE_CACHE_DURATION ) {
							unset( $this->failure_cache[ $type ][ $key ] );
							$changed = true;
						}
					}
				}
			}
		}

		if ( $changed ) {
			$this->failure_cache_dirty = true;
			$this->maybe_save_failure_cache();
		}
	}
}
