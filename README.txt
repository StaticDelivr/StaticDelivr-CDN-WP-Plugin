=== StaticDelivr CDN ===
Contributors: Coozywana
Donate link: https://staticdelivr.com/become-a-sponsor
Tags: CDN, performance, optimization, Free CDN, WordPress CDN
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhance your WordPress site’s performance by rewriting URLs to use the StaticDelivr CDN.

== Description ==

**StaticDelivr CDN** is a lightweight and powerful plugin designed to improve your WordPress site’s performance. By rewriting theme, plugin, and core file resource URLs to use the [StaticDelivr CDN](https://staticdelivr.com), the plugin ensures faster loading times, reduced server load, and a better user experience.

StaticDelivr is a global content delivery network (CDN) that supports delivering assets from various platforms like npm, GitHub, and WordPress. By leveraging geographically distributed servers, StaticDelivr optimizes the delivery of your static assets such as CSS, JavaScript, images, and fonts.

### Key Features

- **Automatic URL Rewriting**: Automatically rewrites URLs of enqueued styles, scripts, and core files for themes, plugins, and WordPress itself to use the StaticDelivr CDN.
- **Automatic Fallback**: If a CDN asset fails to load, the plugin automatically falls back to your origin server, ensuring your site never breaks.
- **Simple Settings**: Enable or disable the functionality with a simple toggle in the plugin settings page.
- **Compatibility**: Works seamlessly with all WordPress themes and plugins that correctly enqueue their assets.
- **Improved Performance**: Delivers assets from the StaticDelivr CDN for lightning-fast loading and enhanced user experience.
- **Multi-CDN Support**: Leverages multiple CDNs to ensure optimal availability and performance.
- **Free and Open Source**: Supports the open-source community by offering free access to a high-performance CDN.

### Use of Third-Party Service

This plugin relies on the [StaticDelivr CDN](https://staticdelivr.com) to deliver static assets, including WordPress themes, plugins, and core files. The CDN uses the public WordPress SVN repository to fetch these files and serves them through a globally distributed network for faster performance and reduced bandwidth costs.

- **Service Terms of Use**: [StaticDelivr Terms](https://staticdelivr.com/legal/terms-of-service)
- **Privacy Policy**: [StaticDelivr Privacy Policy](https://staticdelivr.com/legal/privacy-policy)

### How It Works

**StaticDelivr CDN** rewrites your WordPress asset URLs to deliver them through its high-performance network:

- **Original URL**: `https://example.com/wp-content/themes/theme-name/version/style.css`
- **Rewritten CDN URL**: `https://cdn.staticdelivr.com/wp/themes/theme-name/version/style.css`

This process applies to themes, plugins, and core files:

- **Themes**:
  Original: `https://example.com/wp-content/themes/twentytwentythree/1.0/style.css`
  CDN: `https://cdn.staticdelivr.com/wp/themes/twentytwentythree/1.0/style.css`

- **Plugins**:
  Original: `https://example.com/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js`
  CDN: `https://cdn.staticdelivr.com/wp/plugins/woocommerce/tags/9.3.3/assets/js/frontend/woocommerce.min.js`

- **Core Files**:
  Original: `https://example.com/wp-includes/js/jquery/jquery.min.js`
  CDN: `https://cdn.staticdelivr.com/wp/core/trunk/wp-includes/js/jquery/jquery.min.js`

This ensures faster delivery through StaticDelivr’s globally distributed network.

### Why Use StaticDelivr?

- **Global Distribution**: StaticDelivr serves your assets from a globally distributed network, reducing latency and improving load times.
- **Browser Caching Benefits**: As an open-source CDN used by many sites, assets served by StaticDelivr are likely already cached in users’ browsers. This enables faster load times when visiting multiple sites using StaticDelivr.
- **Significant Bandwidth Savings**: Reduces your site’s bandwidth usage and number of requests significantly by offloading asset delivery to StaticDelivr.
- **Optimized Performance**: Ensures assets are delivered quickly, no matter where your users are located.
- **Comprehensive WordPress Support**: Includes support for delivering core WordPress files (e.g., those in the `wp-includes` directory) to enhance site speed and reliability.
- **Support for Popular Platforms**: Easily integrates with npm, GitHub, WordPress, and Google Fonts.
- **No Configuration Required**: Automatically enhances your site’s performance with minimal setup.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/staticdelivr` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to `Settings > StaticDelivr CDN` to enable the CDN functionality.

== Frequently Asked Questions ==

= What does this plugin do? =
This plugin rewrites the URLs of your WordPress themes, plugins, and core files to use the StaticDelivr CDN for serving static assets like CSS, JavaScript, images, and fonts.

= How do I enable or disable the CDN rewriting? =
Go to `Settings > StaticDelivr CDN` in your WordPress admin dashboard and toggle the setting to enable or disable the functionality.

= Does this plugin support all themes and plugins? =
Yes, the plugin works with all WordPress themes and plugins that enqueue their assets correctly using WordPress functions.

= Will this plugin affect my site's functionality? =
No, the plugin only changes the source URLs of static assets. It does not affect any functionality of your site. Additionally, the plugin includes an automatic fallback mechanism that loads assets from your origin server if the CDN fails, ensuring your site always works.

= Is StaticDelivr free to use? =
Yes, StaticDelivr is a free, open-source CDN designed to support the open-source community.

== Screenshots ==

1. **Settings Page**: Easily enable or disable the CDN functionality from the settings page.

== Changelog ==

= 1.1.0 =
* Added automatic fallback mechanism - if a CDN asset fails to load, the plugin automatically retries from your origin server
* Improved reliability by injecting fallback script early in page head
* Added console logging for debugging fallback events

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Added automatic fallback to origin server if CDN assets fail to load, ensuring your site never breaks.

= 1.0.0 =
Initial release.

== License ==
This plugin is licensed under the GPLv2 or later. See [License URI](https://www.gnu.org/licenses/gpl-2.0.html) for details.
