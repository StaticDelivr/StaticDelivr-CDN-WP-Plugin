<?php
/**
 * StaticDelivr CDN Admin Handler
 *
 * Handles the admin settings page, styles, and user interface
 * for configuring the StaticDelivr CDN plugin.
 *
 * @package StaticDelivr
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr_Admin
 *
 * Manages the admin interface and settings.
 *
 * @since 1.0.0
 */
class StaticDelivr_Admin {

	/**
	 * Verification instance.
	 *
	 * @var StaticDelivr_Verification
	 */
	private $verification;

	/**
	 * Failure tracker instance.
	 *
	 * @var StaticDelivr_Failure_Tracker
	 */
	private $failure_tracker;

	/**
	 * Assets handler instance.
	 *
	 * @var StaticDelivr_Assets
	 */
	private $assets;

	/**
	 * Singleton instance.
	 *
	 * @var StaticDelivr_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return StaticDelivr_Admin
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
	 * Sets up admin hooks.
	 */
	private function __construct() {
		$this->verification    = StaticDelivr_Verification::get_instance();
		$this->failure_tracker = StaticDelivr_Failure_Tracker::get_instance();
		$this->assets          = StaticDelivr_Assets::get_instance();

		// Admin hooks.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'show_activation_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Enqueue admin styles for settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_styles( $hook ) {
		if ( 'settings_page_' . STATICDELIVR_PREFIX . 'cdn-settings' !== $hook ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', $this->get_admin_styles() );
	}

	/**
	 * Get admin CSS styles.
	 *
	 * @return string CSS styles.
	 */
	private function get_admin_styles() {
		return '
            .staticdelivr-wrap {
                max-width: 900px;
            }
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
            .staticdelivr-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                margin-left: 8px;
            }
            .staticdelivr-badge-privacy {
                background: #d4edda;
                color: #155724;
            }
            .staticdelivr-badge-gdpr {
                background: #cce5ff;
                color: #004085;
            }
            .staticdelivr-badge-new {
                background: #fff3cd;
                color: #856404;
            }
            .staticdelivr-info-box {
                background: #f6f7f7;
                padding: 15px;
                margin: 15px 0;
                border-left: 4px solid #2271b1;
            }
            .staticdelivr-info-box h4 {
                margin-top: 0;
                color: #1d2327;
            }
            .staticdelivr-info-box ul {
                margin-bottom: 0;
            }
            .staticdelivr-assets-list {
                margin: 15px 0;
            }
            .staticdelivr-assets-list h4 {
                margin: 15px 0 10px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .staticdelivr-assets-list h4 .count {
                background: #dcdcde;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
                font-weight: normal;
            }
            .staticdelivr-assets-list ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            .staticdelivr-assets-list li {
                padding: 8px 12px;
                background: #fff;
                border: 1px solid #dcdcde;
                margin-bottom: -1px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .staticdelivr-assets-list li:first-child {
                border-radius: 4px 4px 0 0;
            }
            .staticdelivr-assets-list li:last-child {
                border-radius: 0 0 4px 4px;
            }
            .staticdelivr-assets-list li:only-child {
                border-radius: 4px;
            }
            .staticdelivr-assets-list .asset-name {
                font-weight: 500;
            }
            .staticdelivr-assets-list .asset-meta {
                font-size: 12px;
                color: #646970;
            }
            .staticdelivr-assets-list .asset-badge {
                font-size: 11px;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .staticdelivr-assets-list .asset-badge.cdn {
                background: #d4edda;
                color: #155724;
            }
            .staticdelivr-assets-list .asset-badge.local {
                background: #f8d7da;
                color: #721c24;
            }
            .staticdelivr-assets-list .asset-badge.child {
                background: #e2e3e5;
                color: #383d41;
            }
            .staticdelivr-empty-state {
                padding: 20px;
                text-align: center;
                color: #646970;
                font-style: italic;
            }
            .staticdelivr-failure-stats {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 15px;
                margin: 15px 0;
                border-radius: 4px;
            }
            .staticdelivr-failure-stats h4 {
                margin-top: 0;
            }
            .staticdelivr-failure-stats .stat-row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
                border-bottom: 1px solid #f0f0f1;
            }
            .staticdelivr-failure-stats .stat-row:last-child {
                border-bottom: none;
            }
            .staticdelivr-clear-cache-btn {
                margin-top: 10px;
            }
        ';
	}

	/**
	 * Show activation notice.
	 *
	 * @return void
	 */
	public function show_activation_notice() {
		if ( ! get_transient( STATICDELIVR_PREFIX . 'activation_notice' ) ) {
			return;
		}

		delete_transient( STATICDELIVR_PREFIX . 'activation_notice' );

		$settings_url = admin_url( 'options-general.php?page=' . STATICDELIVR_PREFIX . 'cdn-settings' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<strong><?php esc_html_e( 'StaticDelivr CDN is now active!', 'staticdelivr' ); ?></strong>
				<?php esc_html_e( 'Your site is already optimized with CDN delivery, image optimization, and privacy-first Google Fonts enabled by default.', 'staticdelivr' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'View Settings', 'staticdelivr' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Add settings page to WordPress admin.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'StaticDelivr CDN Settings', 'staticdelivr' ),
			__( 'StaticDelivr CDN', 'staticdelivr' ),
			'manage_options',
			STATICDELIVR_PREFIX . 'cdn-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'assets_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => true,
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'images_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => true,
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'image_quality',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_image_quality' ),
				'default'           => 80,
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'image_format',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_image_format' ),
				'default'           => 'webp',
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'google_fonts_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => true,
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'debug_mode',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => false,
			)
		);

		register_setting(
			STATICDELIVR_PREFIX . 'cdn_settings',
			STATICDELIVR_PREFIX . 'bypass_localhost',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => false,
			)
		);
	}

	/**
	 * Sanitize image quality value.
	 *
	 * @param mixed $value The input value.
	 * @return int
	 */
	public function sanitize_image_quality( $value ) {
		$quality = absint( $value );
		return max( 1, min( 100, $quality ) );
	}

	/**
	 * Sanitize image format value.
	 *
	 * @param mixed $value The input value.
	 * @return string
	 */
	public function sanitize_image_format( $value ) {
		$allowed_formats = array( 'auto', 'webp', 'avif', 'jpeg', 'png' );
		return in_array( $value, $allowed_formats, true ) ? $value : 'webp';
	}

	/**
	 * Handle clear failure cache action.
	 *
	 * @return void
	 */
	private function handle_clear_failure_cache() {
		if ( isset( $_POST['staticdelivr_clear_failure_cache'] ) &&
			isset( $_POST['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'staticdelivr_clear_failure_cache' ) ) {
			$this->failure_tracker->clear_failure_cache();
			add_settings_error(
				STATICDELIVR_PREFIX . 'cdn_settings',
				'cache_cleared',
				__( 'Failure cache cleared successfully.', 'staticdelivr' ),
				'success'
			);
		}
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// Handle cache clear action.
		$this->handle_clear_failure_cache();

		$assets_enabled       = get_option( STATICDELIVR_PREFIX . 'assets_enabled', true );
		$images_enabled       = get_option( STATICDELIVR_PREFIX . 'images_enabled', true );
		$image_quality        = get_option( STATICDELIVR_PREFIX . 'image_quality', 80 );
		$image_format         = get_option( STATICDELIVR_PREFIX . 'image_format', 'webp' );
		$google_fonts_enabled = get_option( STATICDELIVR_PREFIX . 'google_fonts_enabled', true );
		$debug_mode           = get_option( STATICDELIVR_PREFIX . 'debug_mode', false );
		$bypass_localhost     = get_option( STATICDELIVR_PREFIX . 'bypass_localhost', false );
		$site_url             = home_url();
		$wp_version           = $this->assets->get_wp_version();
		$verification_summary = $this->verification->get_verification_summary();
		$failure_stats        = $this->failure_tracker->get_failure_stats();
		?>
		<div class="wrap staticdelivr-wrap">
			<h1><?php esc_html_e( 'StaticDelivr CDN', 'staticdelivr' ); ?></h1>
			<p><?php esc_html_e( 'Optimize your WordPress site by delivering assets through the', 'staticdelivr' ); ?>
				<a href="https://staticdelivr.com" target="_blank" rel="noopener noreferrer">StaticDelivr CDN</a>.
			</p>

			<?php settings_errors(); ?>

			<!-- Status Bar -->
			<div class="staticdelivr-status-bar">
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'WordPress:', 'staticdelivr' ); ?></span>
					<span class="value"><?php echo esc_html( $wp_version ); ?></span>
				</div>
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'Assets CDN:', 'staticdelivr' ); ?></span>
					<span class="value <?php echo $assets_enabled ? 'active' : 'inactive'; ?>">
						<?php echo $assets_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
					</span>
				</div>
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'Images:', 'staticdelivr' ); ?></span>
					<span class="value <?php echo $images_enabled ? 'active' : 'inactive'; ?>">
						<?php echo $images_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
					</span>
				</div>
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'Google Fonts:', 'staticdelivr' ); ?></span>
					<span class="value <?php echo $google_fonts_enabled ? 'active' : 'inactive'; ?>">
						<?php echo $google_fonts_enabled ? '● ' . esc_html__( 'Enabled', 'staticdelivr' ) : '○ ' . esc_html__( 'Disabled', 'staticdelivr' ); ?>
					</span>
				</div>
				<?php if ( $images_enabled ) : ?>
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'Quality:', 'staticdelivr' ); ?></span>
					<span class="value"><?php echo esc_html( $image_quality ); ?>%</span>
				</div>
				<div class="staticdelivr-status-item">
					<span class="label"><?php esc_html_e( 'Format:', 'staticdelivr' ); ?></span>
					<span class="value"><?php echo esc_html( strtoupper( $image_format ) ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( STATICDELIVR_PREFIX . 'cdn_settings' ); ?>

				<h2 class="title">
					<?php esc_html_e( 'Assets Optimization (CSS & JavaScript)', 'staticdelivr' ); ?>
					<span class="staticdelivr-badge staticdelivr-badge-new"><?php esc_html_e( 'Smart Detection', 'staticdelivr' ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Rewrite URLs of WordPress core files, themes, and plugins to use StaticDelivr CDN. Only assets from wordpress.org are served via CDN - custom themes and plugins are automatically detected and served locally.', 'staticdelivr' ); ?></p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable Assets CDN', 'staticdelivr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'assets_enabled' ); ?>" value="1" <?php checked( 1, $assets_enabled ); ?> />
								<?php esc_html_e( 'Enable CDN for CSS & JavaScript files', 'staticdelivr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Serves WordPress core, theme, and plugin assets from StaticDelivr CDN for faster loading.', 'staticdelivr' ); ?></p>
							<div class="staticdelivr-example">
								<code><?php echo esc_html( $site_url ); ?>/wp-includes/js/jquery/jquery.min.js</code>
								<span class="becomes">→</span>
								<code><?php echo esc_html( STATICDELIVR_CDN_BASE ); ?>/wp/core/tags/<?php echo esc_html( $wp_version ); ?>/wp-includes/js/jquery/jquery.min.js</code>
							</div>
						</td>
					</tr>
				</table>

				<!-- Asset Verification Summary -->
				<?php if ( $assets_enabled ) : ?>
				<div class="staticdelivr-assets-list">
					<h4>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Themes via CDN', 'staticdelivr' ); ?>
						<span class="count"><?php echo count( $verification_summary['themes']['cdn'] ); ?></span>
					</h4>
					<?php if ( ! empty( $verification_summary['themes']['cdn'] ) ) : ?>
					<ul>
						<?php foreach ( $verification_summary['themes']['cdn'] as $slug => $info ) : ?>
						<li>
							<div>
								<span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
								<span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
								<?php if ( $info['is_child'] ) : ?>
									<span class="asset-badge child"><?php esc_html_e( 'Child of', 'staticdelivr' ); ?> <?php echo esc_html( $info['parent'] ); ?></span>
								<?php endif; ?>
							</div>
							<span class="asset-badge cdn"><?php esc_html_e( 'CDN', 'staticdelivr' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="staticdelivr-empty-state"><?php esc_html_e( 'No themes from wordpress.org detected.', 'staticdelivr' ); ?></p>
					<?php endif; ?>

					<h4>
						<span class="dashicons dashicons-admin-home" style="color: #646970;"></span>
						<?php esc_html_e( 'Themes Served Locally', 'staticdelivr' ); ?>
						<span class="count"><?php echo count( $verification_summary['themes']['local'] ); ?></span>
					</h4>
					<?php if ( ! empty( $verification_summary['themes']['local'] ) ) : ?>
					<ul>
						<?php foreach ( $verification_summary['themes']['local'] as $slug => $info ) : ?>
						<li>
							<div>
								<span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
								<span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
								<?php if ( $info['is_child'] ) : ?>
									<span class="asset-badge child"><?php esc_html_e( 'Child Theme', 'staticdelivr' ); ?></span>
								<?php endif; ?>
							</div>
							<span class="asset-badge local"><?php esc_html_e( 'Local', 'staticdelivr' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="staticdelivr-empty-state"><?php esc_html_e( 'All themes are served via CDN.', 'staticdelivr' ); ?></p>
					<?php endif; ?>

					<h4>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
						<?php esc_html_e( 'Plugins via CDN', 'staticdelivr' ); ?>
						<span class="count"><?php echo count( $verification_summary['plugins']['cdn'] ); ?></span>
					</h4>
					<?php if ( ! empty( $verification_summary['plugins']['cdn'] ) ) : ?>
					<ul>
						<?php foreach ( $verification_summary['plugins']['cdn'] as $slug => $info ) : ?>
						<li>
							<div>
								<span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
								<span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
							</div>
							<span class="asset-badge cdn"><?php esc_html_e( 'CDN', 'staticdelivr' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="staticdelivr-empty-state"><?php esc_html_e( 'No plugins from wordpress.org detected.', 'staticdelivr' ); ?></p>
					<?php endif; ?>

					<h4>
						<span class="dashicons dashicons-admin-home" style="color: #646970;"></span>
						<?php esc_html_e( 'Plugins Served Locally', 'staticdelivr' ); ?>
						<span class="count"><?php echo count( $verification_summary['plugins']['local'] ); ?></span>
					</h4>
					<?php if ( ! empty( $verification_summary['plugins']['local'] ) ) : ?>
					<ul>
						<?php foreach ( $verification_summary['plugins']['local'] as $slug => $info ) : ?>
						<li>
							<div>
								<span class="asset-name"><?php echo esc_html( $info['name'] ); ?></span>
								<span class="asset-meta">v<?php echo esc_html( $info['version'] ); ?></span>
							</div>
							<span class="asset-badge local"><?php esc_html_e( 'Local', 'staticdelivr' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php else : ?>
					<p class="staticdelivr-empty-state"><?php esc_html_e( 'All plugins are served via CDN.', 'staticdelivr' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="staticdelivr-info-box">
					<h4><?php esc_html_e( 'How Smart Detection Works', 'staticdelivr' ); ?></h4>
					<ul>
						<li><strong><?php esc_html_e( 'WordPress.org Verification', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'The plugin checks if each theme/plugin exists on wordpress.org before attempting to serve it via CDN.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'Custom Themes/Plugins', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Assets from custom or premium themes/plugins are automatically served from your server.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'Child Themes', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Child themes use the parent theme verification - if the parent is on wordpress.org, assets load via CDN.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'Cached Results', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Verification results are cached for 7 days to ensure fast page loads.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'Failure Memory', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'If a CDN resource fails to load, the plugin remembers and serves locally for 24 hours.', 'staticdelivr' ); ?></li>
					</ul>
				</div>
				<?php endif; ?>

				<h2 class="title"><?php esc_html_e( 'Image Optimization', 'staticdelivr' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Automatically optimize and deliver images through StaticDelivr CDN. This can dramatically reduce image file sizes (e.g., 2MB → 20KB) and improve loading times.', 'staticdelivr' ); ?></p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable Image Optimization', 'staticdelivr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'images_enabled' ); ?>" value="1" <?php checked( 1, $images_enabled ); ?> id="staticdelivr-images-toggle" />
								<?php esc_html_e( 'Enable CDN for images', 'staticdelivr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Optimizes and delivers all images through StaticDelivr CDN with automatic format conversion and compression.', 'staticdelivr' ); ?></p>
							<div class="staticdelivr-example">
								<code><?php echo esc_html( $site_url ); ?>/wp-content/uploads/photo.jpg (2MB)</code>
								<span class="becomes">→</span>
								<code><?php echo esc_html( STATICDELIVR_IMG_CDN_BASE ); ?>?url=...&amp;q=80&amp;format=webp (~20KB)</code>
							</div>
						</td>
					</tr>
					<tr valign="top" id="staticdelivr-quality-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
						<th scope="row"><?php esc_html_e( 'Image Quality', 'staticdelivr' ); ?></th>
						<td>
							<input type="number" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'image_quality' ); ?>" value="<?php echo esc_attr( $image_quality ); ?>" min="1" max="100" step="1" class="small-text" <?php echo $images_enabled ? '' : 'disabled'; ?> />
							<p class="description"><?php esc_html_e( 'Quality level for optimized images (1-100). Lower values = smaller files. Recommended: 75-85.', 'staticdelivr' ); ?></p>
						</td>
					</tr>
					<tr valign="top" id="staticdelivr-format-row" style="<?php echo $images_enabled ? '' : 'opacity: 0.5;'; ?>">
						<th scope="row"><?php esc_html_e( 'Image Format', 'staticdelivr' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'image_format' ); ?>" <?php echo $images_enabled ? '' : 'disabled'; ?>>
								<option value="auto" <?php selected( $image_format, 'auto' ); ?>><?php esc_html_e( 'Auto (Best for browser)', 'staticdelivr' ); ?></option>
								<option value="webp" <?php selected( $image_format, 'webp' ); ?>><?php esc_html_e( 'WebP (Recommended)', 'staticdelivr' ); ?></option>
								<option value="avif" <?php selected( $image_format, 'avif' ); ?>><?php esc_html_e( 'AVIF (Best compression)', 'staticdelivr' ); ?></option>
								<option value="jpeg" <?php selected( $image_format, 'jpeg' ); ?>><?php esc_html_e( 'JPEG', 'staticdelivr' ); ?></option>
								<option value="png" <?php selected( $image_format, 'png' ); ?>><?php esc_html_e( 'PNG', 'staticdelivr' ); ?></option>
							</select>
							<p class="description">
								<strong>WebP</strong>: <?php esc_html_e( 'Great compression, widely supported.', 'staticdelivr' ); ?><br>
								<strong>AVIF</strong>: <?php esc_html_e( 'Best compression, newer format.', 'staticdelivr' ); ?><br>
								<strong>Auto</strong>: <?php esc_html_e( 'Automatically selects best format based on browser support.', 'staticdelivr' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title">
					<?php esc_html_e( 'Google Fonts (Privacy-First)', 'staticdelivr' ); ?>
					<span class="staticdelivr-badge staticdelivr-badge-privacy"><?php esc_html_e( 'Privacy', 'staticdelivr' ); ?></span>
					<span class="staticdelivr-badge staticdelivr-badge-gdpr"><?php esc_html_e( 'GDPR Compliant', 'staticdelivr' ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Proxy Google Fonts through StaticDelivr CDN to strip tracking cookies and improve privacy.', 'staticdelivr' ); ?></p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Enable Google Fonts Proxy', 'staticdelivr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'google_fonts_enabled' ); ?>" value="1" <?php checked( 1, $google_fonts_enabled ); ?> />
								<?php esc_html_e( 'Proxy Google Fonts through StaticDelivr', 'staticdelivr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Automatically rewrites all Google Fonts URLs to use StaticDelivr\'s privacy-respecting proxy.', 'staticdelivr' ); ?></p>
							<div class="staticdelivr-example">
								<code>https://fonts.googleapis.com/css2?family=Inter&amp;display=swap</code>
								<span class="becomes">→</span>
								<code><?php echo esc_html( STATICDELIVR_CDN_BASE ); ?>/gfonts/css2?family=Inter&amp;display=swap</code>
							</div>
						</td>
					</tr>
				</table>

				<div class="staticdelivr-info-box">
					<h4><?php esc_html_e( 'Why Proxy Google Fonts?', 'staticdelivr' ); ?></h4>
					<ul>
						<li><strong><?php esc_html_e( 'Privacy First', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Strips all user-identifying data and tracking cookies.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'GDPR Compliant', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'No need to declare Google Fonts in your cookie banner.', 'staticdelivr' ); ?></li>
						<li><strong><?php esc_html_e( 'HTTP/3 & Brotli', 'staticdelivr' ); ?>:</strong> <?php esc_html_e( 'Files served over HTTP/3 with Brotli compression.', 'staticdelivr' ); ?></li>
					</ul>
				</div>

				<h2 class="title">
					<?php esc_html_e( 'Development & Debugging', 'staticdelivr' ); ?>
					<span class="staticdelivr-badge" style="background: #fff3cd; color: #856404;"><?php esc_html_e( 'Dev Tools', 'staticdelivr' ); ?></span>
				</h2>
				<p class="description"><?php esc_html_e( 'Tools for debugging and testing in development environments.', 'staticdelivr' ); ?></p>

				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Debug Mode', 'staticdelivr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'debug_mode' ); ?>" value="1" <?php checked( 1, $debug_mode ); ?> />
								<?php esc_html_e( 'Enable detailed logging of image rewrites', 'staticdelivr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Logs all image URL processing details to the error log. Warning: Can generate large log files in production.', 'staticdelivr' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Bypass Localhost Detection', 'staticdelivr' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( STATICDELIVR_PREFIX . 'bypass_localhost' ); ?>" value="1" <?php checked( 1, $bypass_localhost ); ?> />
								<?php esc_html_e( 'Allow CDN rewrites for localhost/development URLs', 'staticdelivr' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Enables image CDN rewrites even on localhost, .test, .local domains for testing. The CDN will not be able to fetch images from these URLs, so you\'ll see which images fail to convert.', 'staticdelivr' ); ?></p>
							<div class="staticdelivr-info-box" style="background: #fff3cd; border-color: #856404; margin-top: 10px;">
								<strong><?php esc_html_e( 'Testing Note:', 'staticdelivr' ); ?></strong> <?php esc_html_e( 'When enabled, image URLs will be rewritten to CDN format, but the CDN cannot actually fetch images from localhost. You\'ll see errors which helps identify which images are being processed and which might have issues on production.', 'staticdelivr' ); ?>
							</div>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<!-- Failure Statistics -->
			<?php if ( $failure_stats['images']['total'] > 0 || $failure_stats['assets']['total'] > 0 ) : ?>
			<h2 class="title"><?php esc_html_e( 'CDN Failure Statistics', 'staticdelivr' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Resources that failed to load from CDN are automatically served locally. This cache expires after 24 hours.', 'staticdelivr' ); ?></p>

			<div class="staticdelivr-failure-stats">
				<h4><?php esc_html_e( 'Failed Resources', 'staticdelivr' ); ?></h4>
				<div class="stat-row">
					<span><?php esc_html_e( 'Images:', 'staticdelivr' ); ?></span>
					<span>
						<?php
						printf(
							/* translators: 1: total failures, 2: blocked count */
							esc_html__( '%1$d failures (%2$d blocked)', 'staticdelivr' ),
							intval( $failure_stats['images']['total'] ),
							intval( $failure_stats['images']['blocked'] )
						);
						?>
					</span>
				</div>
				<div class="stat-row">
					<span><?php esc_html_e( 'Assets:', 'staticdelivr' ); ?></span>
					<span>
						<?php
						printf(
							/* translators: 1: total failures, 2: blocked count */
							esc_html__( '%1$d failures (%2$d blocked)', 'staticdelivr' ),
							intval( $failure_stats['assets']['total'] ),
							intval( $failure_stats['assets']['blocked'] )
						);
						?>
					</span>
				</div>

				<form method="post" class="staticdelivr-clear-cache-btn">
					<?php wp_nonce_field( 'staticdelivr_clear_failure_cache' ); ?>
					<button type="submit" name="staticdelivr_clear_failure_cache" class="button button-secondary">
						<?php esc_html_e( 'Clear Failure Cache', 'staticdelivr' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'This will retry all previously failed resources on next page load.', 'staticdelivr' ); ?></p>
				</form>
			</div>
			<?php endif; ?>

			<script>
			(function() {
				var toggle = document.getElementById('staticdelivr-images-toggle');
				if (!toggle) return;

				toggle.addEventListener('change', function() {
					var qualityRow = document.getElementById('staticdelivr-quality-row');
					var formatRow = document.getElementById('staticdelivr-format-row');
					var qualityInput = qualityRow ? qualityRow.querySelector('input') : null;
					var formatInput = formatRow ? formatRow.querySelector('select') : null;

					var enabled = this.checked;
					if (qualityRow) qualityRow.style.opacity = enabled ? '1' : '0.5';
					if (formatRow) formatRow.style.opacity = enabled ? '1' : '0.5';
					if (qualityInput) qualityInput.disabled = !enabled;
					if (formatInput) formatInput.disabled = !enabled;
				});
			})();
			</script>
		</div>
		<?php
	}
}
