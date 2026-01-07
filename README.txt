=== StaticDelivr CDN ===
Contributors: Coozywana
Donate link: https://staticdelivr.com/become-a-sponsor
Tags: CDN, performance, image optimization, webp, free
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhance your WordPress site's performance by rewriting URLs to use the StaticDelivr CDN. Includes automatic image optimization.

== Description ==

**StaticDelivr CDN** is a lightweight and powerful plugin designed to improve your WordPress site's performance. By rewriting theme, plugin, core file resource URLs, and optimizing images to use the [StaticDelivr CDN](https://staticdelivr.com), the plugin ensures faster loading times, reduced server load, and a better user experience.

StaticDelivr is a global content delivery network (CDN) that supports delivering assets from various platforms like npm, GitHub, and WordPress. By leveraging geographically distributed servers, StaticDelivr optimizes the delivery of your static assets such as CSS, JavaScript, images, and fonts.

### Key Features

- **Automatic URL Rewriting**: Automatically rewrites URLs of enqueued styles, scripts, and core files for themes, plugins, and WordPress itself to use the StaticDelivr CDN.
- **Image Optimization**: Automatically optimizes images with compression and modern format conversion (WebP, AVIF). Turn 2MB images into 20KB without quality loss!
- **Automatic Fallback**: If a CDN asset fails to load, the plugin automatically falls back to your origin server, ensuring your site never breaks.
- **Separate Controls**: Enable or disable assets (CSS/JS) and image optimization independently.
- **Quality & Format Settings**: Customize image compression quality and output format.
- **Compatibility**: Works seamlessly with all WordPress themes and plugins that correctly enqueue their assets.
- **Improved Performance**: Delivers assets from the StaticDelivr CDN for lightning-fast loading and enhanced user experience.
- **Multi-CDN Support**: Leverages multiple CDNs to ensure optimal availability and performance.
- **Free and Open Source**: Supports the open-source community by offering free access to a high-performance CDN.

### Use of Third-Party Service

This plugin relies on the [StaticDelivr CDN](https://staticdelivr.com) to deliver static assets, including WordPress themes, plugins, core files, and optimized images. The CDN uses the public WordPress SVN repository to fetch theme/plugin files and serves them through a globally distributed network for faster performance and reduced bandwidth costs.

- **Service Terms of Use**: [StaticDelivr Terms](https://staticdelivr.com/legal/terms-of-service)
- **Privacy Policy**: [StaticDelivr Privacy Policy](https://staticdelivr.com/legal/privacy-policy)

### How It Works

**StaticDelivr CDN** rewrites your WordPress asset URLs to deliver them through its high-performance network:

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
  CDN: \`https://cdn.staticdelivr.com/wp/core/trunk/wp-includes/js/jquery/jquery.min.js\`

#### Images

- **Original**: \`https://example.com/wp-content/uploads/2024/01/photo.jpg\` (2MB)
- **Optimized CDN**: \`https://cdn.staticdelivr.com/img/images?url=https://example.com/wp-content/uploads/2024/01/photo.jpg&q=80&format=webp\` (~20KB)

This ensures faster delivery through StaticDelivr's globally distributed network.

### Why Use StaticDelivr?

- **Global Distribution**: StaticDelivr serves your assets from a globally distributed network, reducing latency and improving load times.
- **Massive Bandwidth Savings**: Offload heavy image delivery to StaticDelivr. Optimized images can be 10-100x smaller!
- **Browser Caching Benefits**: As an open-source CDN used by many sites, assets served by StaticDelivr are likely already cached in users' browsers. This enables faster load times when visiting multiple sites using StaticDelivr.
- **Significant Bandwidth Savings**: Reduces your site's bandwidth usage and number of requests significantly by offloading asset delivery to StaticDelivr.
- **Optimized Performance**: Ensures assets are delivered quickly, no matter where your users are located.
- **Comprehensive WordPress Support**: Includes support for delivering core WordPress files (e.g., those in the \`wp-includes\` directory) to enhance site speed and reliability.
- **Support for Popular Platforms**: Easily integrates with npm, GitHub, WordPress, and Google Fonts.
- **Minimal Configuration**: Just enable the features you want and the plugin handles the rest.

== Installation ==

1. Upload the plugin files to the \`/wp-content/plugins/staticdelivr\` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to \`Settings > StaticDelivr CDN\` to enable the CDN functionality and configure image optimization.

== Frequently Asked Questions ==

= What does this plugin do? =
This plugin rewrites the URLs of your WordPress themes, plugins, core files, and images to use the StaticDelivr CDN for serving static assets. It also optimizes images by compressing them and converting to modern formats like WebP.

= How do I enable or disable the CDN rewriting? =
Go to \`Settings > StaticDelivr CDN\` in your WordPress admin dashboard. You can independently enable/disable:
- Assets CDN (CSS & JavaScript)
- Image Optimization

= How much can image optimization reduce file sizes? =
Typically, unoptimized images can be reduced by 80-95%. A 2MB JPEG can become a 20-50KB WebP while maintaining visual quality.

= What image formats are supported? =
The plugin supports JPG, JPEG, PNG, GIF, WebP, AVIF, BMP, and TIFF. Images can be converted to WebP, AVIF, JPEG, or PNG.

= Does this plugin support all themes and plugins? =
Yes, the plugin works with all WordPress themes and plugins that enqueue their assets correctly using WordPress functions.

= Will this plugin affect my site's functionality? =
No, the plugin only changes the source URLs of static assets. It does not affect any functionality of your site. Additionally, the plugin includes an automatic fallback mechanism that loads assets from your origin server if the CDN fails, ensuring your site always works.

= Is StaticDelivr free to use? =
Yes, StaticDelivr is a free, open-source CDN designed to support the open-source community.

== Screenshots ==

1. **Settings Page**: Configure assets CDN and image optimization with quality and format settings.

== Changelog ==

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
