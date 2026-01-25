<?php
/**
 * StaticDelivr CDN Main Class
 *
 * Main orchestration class that initializes all plugin components.
 * This class ties together all the individual handlers and manages
 * the overall plugin lifecycle.
 *
 * @package StaticDelivr
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Class StaticDelivr
 *
 * Main plugin class that initializes all components.
 *
 * @since 2.0.0
 */
class StaticDelivr {

    /**
     * Failure tracker instance.
     *
     * @var StaticDelivr_Failure_Tracker
     */
    private $failure_tracker;

    /**
     * Verification instance.
     *
     * @var StaticDelivr_Verification
     */
    private $verification;

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
     * Google Fonts handler instance.
     *
     * @var StaticDelivr_Google_Fonts
     */
    private $google_fonts;

    /**
     * Fallback handler instance.
     *
     * @var StaticDelivr_Fallback
     */
    private $fallback;

    /**
     * Admin handler instance.
     *
     * @var StaticDelivr_Admin
     */
    private $admin;

    /**
     * Singleton instance.
     *
     * @var StaticDelivr|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return StaticDelivr
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
     * Initializes all plugin components.
     */
    private function __construct() {
        $this->init_components();
    }

    /**
     * Initialize all plugin components.
     *
     * Components are initialized in dependency order.
     *
     * @return void
     */
    private function init_components() {
        // Initialize core tracking systems first (no dependencies).
        $this->failure_tracker = StaticDelivr_Failure_Tracker::get_instance();
        $this->verification    = StaticDelivr_Verification::get_instance();

        // Initialize handlers that depend on tracking systems.
        $this->assets       = StaticDelivr_Assets::get_instance();
        $this->images       = StaticDelivr_Images::get_instance();
        $this->google_fonts = StaticDelivr_Google_Fonts::get_instance();

        // Initialize fallback system (depends on assets and images).
        $this->fallback = StaticDelivr_Fallback::get_instance();

        // Initialize admin interface (depends on all other components).
        $this->admin = StaticDelivr_Admin::get_instance();
    }

    /**
     * Get the failure tracker instance.
     *
     * @return StaticDelivr_Failure_Tracker
     */
    public function get_failure_tracker() {
        return $this->failure_tracker;
    }

    /**
     * Get the verification instance.
     *
     * @return StaticDelivr_Verification
     */
    public function get_verification() {
        return $this->verification;
    }

    /**
     * Get the assets handler instance.
     *
     * @return StaticDelivr_Assets
     */
    public function get_assets() {
        return $this->assets;
    }

    /**
     * Get the images handler instance.
     *
     * @return StaticDelivr_Images
     */
    public function get_images() {
        return $this->images;
    }

    /**
     * Get the Google Fonts handler instance.
     *
     * @return StaticDelivr_Google_Fonts
     */
    public function get_google_fonts() {
        return $this->google_fonts;
    }

    /**
     * Get the fallback handler instance.
     *
     * @return StaticDelivr_Fallback
     */
    public function get_fallback() {
        return $this->fallback;
    }

    /**
     * Get the admin handler instance.
     *
     * @return StaticDelivr_Admin
     */
    public function get_admin() {
        return $this->admin;
    }
}
