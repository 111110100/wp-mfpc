=== MemBlaze Full Page Cache ===
Contributors: erwinlomibao
Tags: cache, memcached, performance, full page cache, optimization
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Robust full-page caching mechanism for WordPress sites using Memcached, significantly improving performance and reducing server load.

== Description ==

This system provides a robust full-page caching mechanism for WordPress sites using Memcached, significantly improving performance and reducing server load. It consists of two main components:

1. **Plugin Config**: A WordPress plugin that provides an admin interface to configure the caching behavior, Memcached server(s), cache expiration rules, and generates necessary configuration files.
2. **index-cached.php**: A PHP front-controller script that sits in your WordPress root directory. It intercepts requests, attempts to serve pages from Memcached, or, if a page isn't cached or the cache is bypassed, it loads WordPress to generate the page and then stores it in Memcached for subsequent requests.

= Features =

* **Memcached Integration**: Leverages the speed and efficiency of Memcached for storing full HTML pages.
* **Configurable Cache Times**: Set a default cache expiration time and define specific cache durations for different URL paths.
* **Multiple Server Support**: Configure one or more Memcached servers (TCP/IP or Unix sockets).
* **Automatic Cache Purging**: Purges cache for individual posts/pages and the homepage when content is saved, updated, or deleted.
* **Asset Minification**: Automatically minifies HTML, inline CSS, and inline JS to reduce page size and improve Core Web Vitals.
* **Lazy Load (Experimental)**: Automatically adds `loading="lazy"` attributes to images and iframes.
* **Cookie-Based Cache Bypass**: Define a list of cookie name prefixes (e.g., for logged-in users).
* **WP-CLI Support**: Manage cache and check status via command line.
* **Site Health Integration**: Built-in checks for Memcached connectivity.

== Installation ==

1. Upload the `memblaze-fpc` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Copy `index-cached.php` from the plugin directory to your WordPress root directory (where `wp-config.php` is located).
4. Configure your Memcached servers and settings in the 'MemBlaze Cache' menu.
5. (Recommended) Configure your Nginx server to use the generated configuration.

== Frequently Asked Questions ==

= Does this require Nginx? =
It is highly recommended and optimized for Nginx, but it can work on Apache if you set `index-cached.php` as the default index.

= Do I need the Memcached PECL extension? =
Yes, this plugin requires the `memcached` extension to be installed on your server.

== Screenshots ==

1. Main configuration dashboard showing settings and stats.

== Changelog ==

= 1.6.0 =
* Added Asset Minification (HTML, CSS, JS).
* Updated UI and documentation.
* Improved security and compliance with WordPress standards.

= 1.5.5 =
* Updated CLI commands for multiple ID purging and warming.

= 1.4.0 =
* Added WP-CLI support and Site Health integration.
* Added emergency bypass file support.

= 1.3.0 =
* Initial release.
