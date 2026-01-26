=== StaticDelivr: Free CDN, Image Optimization & Speed ===
Contributors: Coozywana
Donate link: https://staticdelivr.com/become-a-sponsor
Tags: CDN, image optimization, speed, cache, gdpr
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Speed up WordPress with free CDN delivery, image optimization, smart asset detection, failure recovery, and privacy-first Google Fonts proxy.

== Description ==

**StaticDelivr CDN** is a lightweight and powerful plugin designed to improve your WordPress site's performance. By rewriting theme, plugin, core file resource URLs, optimizing images, and proxying Google Fonts through the [StaticDelivr CDN](https://staticdelivr.com), the plugin ensures faster loading times, reduced server load, better privacy, and an improved user experience.

StaticDelivr is a global content delivery network (CDN) that supports delivering assets from various platforms like npm, GitHub, and WordPress. By leveraging geographically distributed servers, StaticDelivr optimizes the delivery of your static assets such as CSS, JavaScript, images, and fonts.

### Key Features

- **Smart Asset Detection**: Automatically detects which themes and plugins are from wordpress.org and only serves those via CDN. Custom themes and plugins are served locally. No configuration needed!
- **Failure Memory System**: If a CDN resource fails to load, the plugin remembers and automatically serves it locally for 24 hours. No more repeated failures!
- **Automatic URL Rewriting**: Automatically rewrites URLs of enqueued styles, scripts, and core files for themes, plugins, and WordPress itself to use the StaticDelivr CDN.
- **Image Optimization**: Automatically optimizes images with compression and modern format conversion (WebP, AVIF). Turn 2MB images into 20KB without quality loss!
- **Google Fonts Privacy Proxy**: Serve Google Fonts without tracking (GDPR compliant). A drop-in replacement that strips all user-identifying data and tracking cookies.
- **Automatic Fallback**: If a CDN asset fails to load, the plugin automatically falls back to your origin server, ensuring your site never breaks.
- **Localhost Detection**: Automatically detects development environments and serves images locally when CDN cannot reach them.
- **Child Theme Support**: Intelligently handles child themes by checking parent theme availability on wordpress.org.
- **Separate Controls**: Enable or disable assets (CSS/JS), image optimization, and Google Fonts proxy independently.
- **Quality & Format Settings**: Customize image compression quality and output format.
- **Verification Dashboard**: See exactly which assets are served via CDN vs locally in the admin panel.
- **Failure Statistics**: View which resources have failed and are being served locally, with option to clear cache.
- **Compatibility**: Works seamlessly with all WordPress themes and plugins — both from wordpress.org and custom/premium sources.
- **Improved Performance**: Delivers assets from the StaticDelivr CDN for lightning-fast loading and enhanced user experience.
- **Multi-CDN Support**: Leverages multiple CDNs to ensure optimal availability and performance.
- **Free and Open Source**: Supports the open-source community by offering free access to a high-performance CDN.

### Use of Third-Party Service

This plugin relies on the [StaticDelivr CDN](https://staticdelivr.com) to deliver static assets, including WordPress themes, plugins, core files, optimized images, and Google Fonts. The CDN uses the public WordPress SVN repository to fetch theme/plugin files and serves them through a globally distributed network for faster performance and reduced bandwidth costs.

- **Service Terms of Use**: [StaticDelivr Terms](https://staticdelivr.com/legal/terms-of-service)
- **Privacy Policy**: [StaticDelivr Privacy Policy](https://staticdelivr.com/legal/privacy-policy)

### How It Works

**StaticDelivr CDN** rewrites your WordPress asset URLs to deliver them through its high-performance network:

#### Smart Asset Detection

The plugin automatically verifies which themes and plugins exist on wordpress.org:

- **WordPress.org Assets**: Served via StaticDelivr CDN for maximum performance
- **Custom/Premium Assets**: Automatically detected and served from your server
- **Child Themes**: Parent theme is checked. If the parent is on wordpress.org, assets load via CDN

This means the plugin "just works" with any combination of wordpress.org and custom themes/plugins!

#### Failure Memory System (New in 1.7.0!)

The plugin learns from CDN failures and automatically adapts:

- **First failure**: Fallback fires, failure is recorded
- **Second failure**: Resource is blocked from CDN for 24 hours
- **Auto-recovery**: After 24 hours, resources are retried automatically
- **Manual reset**: Clear the failure cache anytime from settings

This ensures that problematic resources (Cloudflare-protected images, auth-gated content, etc.) are handled gracefully without repeated failures.

#### Assets (CSS & JavaScript)

- **Original URL**: \`https://example.com/wp-content/themes/theme-name/version/style.css\`
- **Rewritten CDN URL**: \`https://cdn.staticdelivr.com/wp/themes/theme-name/version/style.css\`

This process applies to themes, plugins, and core files:

- **Themes**:
  Original: \`https://example.com/wp-content/themes/twentytwentythree/1.0/style.css\`
  CDN: \`https://cdn.staticdelivr.com/wp/themes/twentytwentythree/1.0/style.css\`

- **Plugins**:
  Original: \`https://example.com/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js\`
  CDN: \`https://cdn.staticdelivr.com/wp/plugins/woocommerce/tags/9.3.3/assets/js/frontend/woocommerce.min.js\`

- **Core Files**:
  Original: \`https://example.com/wp-includes/js/jquery/jquery.min.js\`
  CDN: \`https://cdn.staticdelivr.com/wp/core/tags/6.9/wp-includes/js/jquery/jquery.min.js\`

#### Images

- **Original**: \`https://example.com/wp-content/uploads/2024/01/photo.jpg\` (2MB)
- **Optimized CDN**: \`https://cdn.staticdelivr.com/img/images?url=https://example.com/wp-content/uploads/2024/01/photo.jpg&q=80&format=webp\` (~20KB)

#### Google Fonts

- **Original CSS**: \`https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap\`
- **Privacy Proxy**: \`https://cdn.staticdelivr.com/gfonts/css2?family=Inter:wght@400;500;600&display=swap\`

- **Original Font Files**: \`https://fonts.gstatic.com/s/inter/v20/example.woff2\`
- **Privacy Proxy**: \`https://cdn.staticdelivr.com/gstatic-fonts/s/inter/v20/example.woff2\`

This ensures faster delivery through StaticDelivr's globally distributed network while protecting user privacy.

### Why Use StaticDelivr?

- **Zero Configuration**: Smart detection means it works out of the box with any theme/plugin combination.
- **Self-Healing**: Failure memory system automatically adapts to problematic resources.
- **Global Distribution**: StaticDelivr serves your assets from a globally distributed network, reducing latency and improving load times.
- **Massive Bandwidth Savings**: Offload heavy image delivery to StaticDelivr. Optimized images can be 10-100x smaller!
- **Privacy-First Google Fonts**: Serve Google Fonts without tracking cookies — GDPR compliant without additional cookie banners.
- **Works with Custom Themes**: Unlike other CDN plugins, StaticDelivr automatically detects custom themes/plugins and serves them locally.
- **Browser Caching Benefits**: As an open-source CDN used by many sites, assets served by StaticDelivr are likely already cached in users' browsers. This enables faster load times when visiting multiple sites using StaticDelivr.
- **Significant Bandwidth Savings**: Reduces your site's bandwidth usage and number of requests significantly by offloading asset delivery to StaticDelivr.
- **Optimized Performance**: Ensures assets are delivered quickly, no matter where your users are located.
- **Comprehensive WordPress Support**: Includes support for delivering core WordPress files (e.g., those in the \`wp-includes\` directory) to enhance site speed and reliability.
- **Support for Popular Platforms**: Easily integrates with npm, GitHub, WordPress, and Google Fonts.
- **Minimal Configuration**: Just enable the features you want and the plugin handles the rest.
- **Development Friendly**: Automatically detects localhost and development environments.

== Installation ==

1. Upload the plugin files to the \`/wp-content/plugins/staticdelivr\` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to \`Settings > StaticDelivr CDN\` to view status and configure options.

That's it! The plugin automatically detects which assets can be served via CDN and handles everything else.

== Frequently Asked Questions ==

= What does this plugin do? =
This plugin rewrites the URLs of your WordPress themes, plugins, core files, images, and Google Fonts to use the StaticDelivr CDN for serving static assets. It also optimizes images by compressing them and converting to modern formats like WebP.

= How do I enable or disable the CDN rewriting? =
Go to \`Settings > StaticDelivr CDN\` in your WordPress admin dashboard. You can independently enable/disable:
- Assets CDN (CSS & JavaScript)
- Image Optimization
- Google Fonts Privacy Proxy

= I have a custom theme — will this break my site? =
No! The plugin uses Smart Detection to automatically identify custom themes and plugins. Assets from custom/premium sources are served from your server, while wordpress.org assets are served via CDN. No configuration needed.

= How does Smart Detection work? =
The plugin checks WordPress's update system to determine if each theme/plugin exists on wordpress.org. Results are cached for 7 days. If a theme/plugin isn't found, it's served locally. This happens automatically.

= What is the Failure Memory System? =
New in version 1.7.0, the plugin tracks when CDN resources fail to load. After 2 failures, the resource is automatically served locally for 24 hours. This prevents repeated failures for resources that can't be served via CDN (like Cloudflare-protected images or authenticated content).

= Can I see which resources have failed? =
Yes! Go to `Settings > StaticDelivr CDN` and scroll down. If any resources have failed, you'll see a "CDN Failure Statistics" section showing the count of failed images and assets. You can also clear this cache to retry all resources.

= What about child themes? =
Child themes are handled intelligently. The plugin checks if the parent theme exists on wordpress.org. If it does, parent theme assets are served via CDN. Child theme files are always served locally since they don't exist on wordpress.org.

= Will this work on localhost? =
Yes! The plugin automatically detects localhost, private IPs, and development domains (.local, .test, .dev). Images from non-routable URLs are served locally since the CDN cannot fetch them. Assets CDN still works for themes/plugins since those are fetched from wordpress.org, not your server.

= How much can image optimization reduce file sizes? =
Typically, unoptimized images can be reduced by 80-95%. A 2MB JPEG can become a 20-50KB WebP while maintaining visual quality.

= What image formats are supported? =
The plugin supports JPG, JPEG, PNG, GIF, WebP, AVIF, BMP, and TIFF. Images can be converted to WebP, AVIF, JPEG, or PNG.

= How does the Google Fonts privacy proxy work? =
The plugin automatically rewrites all Google Fonts URLs (from \`fonts.googleapis.com\` and \`fonts.gstatic.com\`) to use StaticDelivr's privacy-respecting proxy. StaticDelivr strips all user-identifying data and tracking cookies before fetching fonts from Google, making it GDPR compliant.

= Does the Google Fonts proxy work with my theme/plugin? =
Yes! The plugin uses multiple methods to catch and rewrite Google Fonts URLs:
- Properly enqueued stylesheets via WordPress
- Inline \`<link>\` tags added by themes and page builders
- DNS prefetch and preconnect hints

= Is the Google Fonts proxy GDPR compliant? =
Yes. Because StaticDelivr acts as a privacy shield and strips all tracking data, you don't need to declare Google Fonts usage in your cookie banner or privacy policy as a third-party data processor.

= Does this plugin support all themes and plugins? =
Yes! The plugin works with all WordPress themes and plugins:
- **WordPress.org themes/plugins**: Served via CDN
- **Custom/premium themes/plugins**: Served locally from your server
- **Child themes**: Parent theme assets via CDN if available

= Will this plugin affect my site's functionality? =
No, the plugin only changes the source URLs of static assets. It does not affect any functionality of your site. Additionally, the plugin includes an automatic fallback mechanism that loads assets from your origin server if the CDN fails, ensuring your site always works.

= How can I see which assets are served via CDN? =
Go to \`Settings > StaticDelivr CDN\`. When Assets CDN is enabled, you'll see a complete list of all themes and plugins showing whether each is served via CDN or locally.

= Is StaticDelivr free to use? =
Yes, StaticDelivr is a free, open-source CDN designed to support the open-source community.

= How long are verification results cached? =
Verification results are cached for 7 days. The cache is automatically cleaned up daily to remove entries for uninstalled themes/plugins.

= How long are failure results cached? =
Failure results are cached for 24 hours. After this period, the plugin will retry serving the resource from CDN. You can also manually clear the failure cache from the settings page.

== Screenshots ==

1. **Settings Page**: Configure assets CDN, image optimization, and Google Fonts privacy proxy.
2. **Asset Verification**: See which themes and plugins are served via CDN vs locally.
3. **Smart Detection**: Automatic detection of wordpress.org vs custom assets.
4. **Failure Statistics**: View and manage CDN failures with one-click cache clearing.

== Translations ==

StaticDelivr CDN is available in the following languages:

* English (default)
* Spanish (Español) - es_ES, es_MX
* French (Français) - fr_FR
* German (Deutsch) - de_DE
* Italian (Italiano) - it_IT
* Portuguese Brazil (Português) - pt_BR
* Dutch (Nederlands) - nl_NL
* Japanese (日本語) - ja
* Chinese Simplified (简体中文) - zh_CN
* Chinese Traditional (繁體中文) - zh_TW
* Russian (Русский) - ru_RU
* Arabic (العربية) - ar
* Korean (한국어) - ko_KR
* Turkish (Türkçe) - tr_TR
* Polish (Polski) - pl_PL
* Ukrainian (Українська) - uk
* Czech (Čeština) - cs_CZ
* Hungarian (Magyar) - hu_HU
* Romanian (Română) - ro_RO
* Swedish (Svenska) - sv_SE
* Danish (Dansk) - da_DK
* Finnish (Suomi) - fi
* Hebrew (עברית) - he_IL
* Persian (فارسی) - fa_IR
* Hindi (हिन्दी) - hi
* Vietnamese (Tiếng Việt) - vi
* Thai (ไทย) - th
* Indonesian (Bahasa Indonesia) - id_ID
* Urdu (اردو) - ur

Want to help translate StaticDelivr CDN into your language? Visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/staticdelivr/) to contribute!

== Changelog ==

= 2.4.1 =
* Fixed: Resolved an issue where lazy-loaded images could fail silently without triggering the fallback mechanism (Browser Intervention).
* Improved: Fallback script now aggressively removes `srcset` and `loading` attributes to force browsers to retry failed images immediately.
* New: Added a "Sweeper" function to automatically detect and repair broken images that were missed by standard error listeners.
* Fixed: Improved error detection logic to prioritize `currentSrc`, ensuring failures in responsive thumbnails are caught even if the main src is valid.

= 2.4.0 =
* New: Smart Dimension Detection. The plugin now automatically identifies missing width and height attributes for WordPress images and restores them using attachment metadata.
* Improved: Resolves Google PageSpeed Insights warnings regarding "Explicit width and height" for image elements.
* Improved: Enhances Cumulative Layout Shift (CLS) scores by ensuring browsers reserve the correct aspect ratio during image loading.
* Improved: Synchronized CDN URL optimization parameters with detected database dimensions for more accurate image scaling.

= 2.3.0 =
* Major Improvement: Significant performance boost by removing blocking DNS lookups during image processing.
* Fixed: Resolved "Path Math" issues where thumbnail URLs could become mangled by WordPress core.
* Fixed: Robust HTML parsing for images now handles special characters (like >) in alt text without breaking layout.
* Improved: Optimized thumbnail delivery by removing redundant regex parsing passes.
* Hardened: Improved path parsing safety to ensure full compatibility with modern PHP 8.x environments.
* Refined: Cleaned up internal logging and removed legacy recovery logic in favor of a more stable architecture.

= 2.2.2 =
* Fixed infinite recursion in image URL filters by removing database lookups for malformed CDN URLs
* Improved image handling by simplifying thumbnail HTML rewriting to avoid redundant processing
* Removed unnecessary parent theme slug handling in verification for better performance

= 2.2.1 =
* Fixed an issue with infinite recursion in the `rewrite_attachment_image_src` and `rewrite_attachment_url` filters.
* Improved handling of image URLs to prevent errors when retrieving attachment URLs.

= 2.2.0 =
* **Fixed: Critical Bug** - Improved recovery for malformed CDN URLs by looking up original attachment paths in the database instead of guessing dates
* **Improved** - Better handling of older content with incorrect CDN URLs

= 2.1.0 =
* **New: Multi-language Support** - Added full localization for over 30 languages to support a global user base.
* Added translations for Spanish, French, German, Italian, Portuguese, Arabic, Chinese, Japanese, and many others
* Full RTL (Right-to-Left) support for languages like Arabic and Persian
* Integrated with wordpress.org translation system for future community contributions
* **New: Dev Tools & Debugging** - Enhanced controls for developers to troubleshoot in local environments.
* Added Debug Mode for detailed logging of image rewrite operations to the error log
* New "Bypass Localhost" option to test CDN rewrites on .local, .test, and .dev domains
* Improved dynamic debug mode support in the fallback failure script
* **Improved: Reliability & Assets** - Refined asset handling and refreshed visuals.
* Hardened image processing to detect and recover from malformed or legacy StaticDelivr URLs
* Updated plugin branding with refreshed icons and new dashboard screenshots
* Better handling of protocol-relative and site-relative URLs during optimization

= 2.0.0 =
* **Major Refactor: Modular Architecture** - Complete code reorganization for better maintainability
* Split monolithic 2900+ line file into 9 modular, single-responsibility class files
* New organized directory structure with dedicated includes/ folder
* Implemented singleton pattern across all component classes
* Main orchestration class (StaticDelivr) now manages all plugin components
* Separate classes for each feature: Assets, Images, Google Fonts, Verification, Failure Tracker, Fallback, Admin
* Improved code organization following WordPress plugin development best practices
* Enhanced dependency management with clear component initialization order
* Better code maintainability with focused, testable classes
* Streamlined main plugin file as lightweight bootstrap
* All functionality preserved - no breaking changes to features or settings
* Improved inline documentation and PHPDoc comments throughout
* Better separation of concerns for future feature development
* Foundation for easier testing and extension of plugin features

= 1.7.1 =
* Fixed heredoc syntax to comply with WordPress coding standards
* Fixed output escaping for failure statistics
* Shortened plugin description to meet 150 character limit
* Code quality improvements for WordPress.org submission

= 1.7.0 =
* **New: Failure Memory System** - Plugin now remembers CDN failures and serves resources locally
* Client-side failure detection using non-blocking beacon API
* Server-side failure tracking with threshold-based blocking
* After 2 failures, resources are served locally for 24 hours
* Automatic cache expiry - resources retry after 24 hours
* Failure statistics visible in admin settings when failures exist
* One-click "Clear Failure Cache" button to retry all resources
* Images blocked based on URL hash for precise tracking
* Assets blocked based on theme/plugin slug for efficient grouping
* Improved fallback script with failure reporting
* Added AJAX endpoint for secure failure reporting with nonce verification
* Daily cleanup of expired failure entries via cron
* Updated admin UI to show failure counts and blocked resources
* Added info box explaining failure memory feature

= 1.6.0 =
* **New: Smart Asset Detection** - Automatically detects if themes/plugins exist on wordpress.org
* Only wordpress.org assets are served via CDN - custom/premium assets served locally
* Zero configuration needed - works with any theme/plugin combination
* Added verification dashboard showing CDN vs local status for all assets
* Child theme support - checks parent theme availability on wordpress.org
* Multi-layer caching: in-memory, database, and WordPress transients
* Verification results cached for 7 days with automatic cleanup
* Added localhost/development environment detection for images
* Private IP ranges and .local/.test/.dev domains automatically detected
* Images from non-routable URLs served locally (CDN can't fetch localhost)
* Added daily cron job for cache cleanup
* Theme/plugin activation hooks for immediate verification
* Cache invalidation on theme switch and plugin deletion
* Improved fallback script with better error handling
* Admin UI shows complete asset breakdown with visual indicators
* Added "Smart Detection" badge and info box explaining the system
* Performance optimized: lazy loading and batched database writes

= 1.5.0 =
* Added Google Fonts privacy proxy - automatically rewrites Google Fonts URLs to use StaticDelivr
* Google Fonts proxy strips all tracking cookies and user-identifying data - GDPR compliant
* Works with fonts loaded by themes, plugins, page builders, and inline styles
* Rewrites both fonts.googleapis.com and fonts.gstatic.com URLs
* Font files proxied via cdn.staticdelivr.com/gstatic-fonts
* Updates DNS prefetch and preconnect hints for Google Fonts
* Added new setting to enable/disable Google Fonts proxy independently
* Google Fonts proxy enabled by default for new installations
* Updated status bar to show Google Fonts status
* Added privacy and GDPR badges to settings page
* Improved output buffering for catching hardcoded Google Fonts URLs

= 1.4.0 =
* Fixed WordPress core files to use proper version instead of "trunk"
* Core files CDN URLs now include WordPress version (e.g., /wp/core/tags/6.9/ instead of /wp/core/trunk/)
* Added WordPress version detection with support for development/RC/beta versions
* Cached WordPress version to avoid repeated calls
* Updated settings page to display detected WordPress version
* Prevents cache mismatches when WordPress is updated

= 1.3.1 =
* Fixed plugin/theme version detection to ensure CDN rewriting works correctly for all plugins
* Introduced cached helper methods for theme/plugin versions to avoid repeated filesystem work per request
* Corrected plugin version detection for various plugin structures and removed incorrect path assumptions
* Updated CDN rewriting to use the new version detection
* Added rel="noopener noreferrer" to external links

= 1.3.0 =
* Redesigned settings page with modern card-based UI
* Added "Settings" link on plugins list page for quick access
* Both features (Assets CDN & Image Optimization) now enabled by default on fresh install
* Added activation notice to guide new users
* Interactive image quality slider with live preview
* Status overview dashboard showing current configuration
* Improved toggle UX - image options dim when disabled
* Added plugin row meta links (Website, Support)
* Better visual hierarchy and clearer explanations
* Overall polish for a better user experience

= 1.2.1 =
* Improved image fallback - now automatically extracts original URL from CDN URL when images fail to load
* Fixed fallback for images blocked by Cloudflare or other security services
* Enhanced srcset fallback support
* Better error handling for edge cases

= 1.2.0 =
* Added automatic image optimization - images are now compressed and converted to modern formats (WebP, AVIF)
* Separate settings for Assets CDN (CSS/JS) and Image Optimization
* Configurable image quality (1-100)
* Selectable image output format (Auto, WebP, AVIF, JPEG, PNG)
* Images include automatic fallback to origin server
* Enhanced settings page with detailed explanations
* Improved inline documentation

= 1.1.0 =
* Added automatic fallback mechanism - if a CDN asset fails to load, the plugin automatically retries from your origin server
* Improved reliability by injecting fallback script early in page head
* Added console logging for debugging fallback events

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.4.1 =
Critical fix for images failing to load on modern browsers. This update handles "Lazy Load Interventions" and ensures the fallback mechanism works 100% of the time. Recommended for all users.

= 2.4.0 =
This update introduces Smart Dimension Detection to automatically fix PageSpeed Insights warnings and improve your site's SEO and CLS scores. Highly recommended for all users.

= 2.3.0 =
This major update introduces significant performance optimizations and critical stability fixes for thumbnail generation and HTML parsing. Upgrading is highly recommended for a faster and more stable site experience.

= 2.2.2 =
Performance improvements and bug fixes for image handling and verification.

= 2.2.1 =
Fixes infinite recursion in image URL filters and improves handling of attachment URLs.

= 2.2.0 =
Critical fix: Solves broken images issues by correctly recovering original file paths from the database for older content.

= 2.1.0 =
Massive update! StaticDelivr is now available in over 30 languages. Includes new debug tools and improved stability.

= 2.0.0 =
Major architectural improvement! Complete code refactor into modular structure. All features preserved with no breaking changes. Better maintainability and foundation for future enhancements. Simply update and continue using as before.

= 1.7.0 =
New Failure Memory System! The plugin now remembers when CDN resources fail and automatically serves them locally for 24 hours. No more repeated failures for problematic resources. Includes admin UI for viewing and clearing failure cache.

= 1.6.0 =
Major update! Smart Asset Detection automatically identifies custom themes/plugins and serves them locally while wordpress.org assets go through CDN. No more broken CSS from custom themes! Also includes localhost detection for images and a new verification dashboard.

= 1.5.0 =
New feature! Google Fonts privacy proxy - serve Google Fonts without tracking, GDPR compliant out of the box. Works automatically with all themes and plugins.

= 1.4.0 =
Important update! Core files now use versioned CDN URLs instead of "trunk" to prevent cache mismatches when WordPress is updated.

= 1.3.1 =
Fixes for version detection and CDN rewriting. Added security attributes to external links.

= 1.3.0 =
Major UX improvements! Redesigned settings page, quick-access Settings link on plugins page, and both features enabled by default on fresh installs.

= 1.2.1 =
Improved image fallback reliability - images now automatically fall back to origin when CDN can't fetch them (e.g., Cloudflare protection).

= 1.2.0 =
Major update! Now includes automatic image optimization. Your images will be compressed and converted to modern formats, potentially reducing bandwidth by 80-95%. Configure in Settings > StaticDelivr CDN.

= 1.1.0 =
Added automatic fallback to origin server if CDN assets fail to load, ensuring your site never breaks.

= 1.0.0 =
Initial release.

== License ==
This plugin is licensed under the GPLv2 or later. See [License URI](https://www.gnu.org/licenses/gpl-2.0.html) for details.
