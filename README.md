# WordPress Memcached Full Page Cache

This system provides a robust full-page caching mechanism for WordPress sites using Memcached, significantly improving performance and reducing server load. It consists of two main components:

1.  **`memcached-full-page-cache-config.php`**: A WordPress plugin that provides an admin interface to configure the caching behavior, Memcached server(s), cache expiration rules, and generates necessary configuration files.
2.  **`index-cached.php`**: A PHP front-controller script that sits in your WordPress root directory. It intercepts requests, attempts to serve pages from Memcached, or, if a page isn't cached or the cache is bypassed, it loads WordPress to generate the page and then stores it in Memcached for subsequent requests.

## Features

*   **Memcached Integration**: Leverages the speed and efficiency of Memcached for storing full HTML pages.
*   **Configurable Cache Times**: Set a default cache expiration time and define specific cache durations for different URL paths (e.g., `/blog/`, `/category/`).
*   **Multiple Server Support**: Configure one or more Memcached servers (TCP/IP or Unix sockets).
*   **Automatic Cache Purging**:
    *   Purges cache for individual posts/pages and the homepage when content is saved, updated, or deleted.
    *   Configurable to only purge for specific post types.
*   **Cookie-Based Cache Bypass**: Define a list of cookie name prefixes. If a visitor has any of these cookies, the cache will be bypassed for them, ensuring dynamic content for logged-in users or users with specific session cookies (e.g., e-commerce carts).
*   **Debug Mode**: Optional debug comments in the HTML output showing cache status and generation time.
*   **Nginx Configuration Generation**: The plugin generates a sample Nginx configuration snippet to help direct requests to `index-cached.php`.
*   **Server Status Check**: Test connectivity to your Memcached servers directly from the plugin settings page.

## How it Works

1.  **Nginx Request Handling**: The web server (Nginx) is configured to first check for static files. If a static file is not found and the request is for a PHP page, Nginx is configured to pass the request to `index-cached.php` instead of the standard `index.php`.
2.  **`index-cached.php` Interception**:
    *   Reads its configuration from `wp-content/memcached-fp-config.php` (generated by the plugin).
    *   Checks if the current visitor has any cookies that match the "bypass cookies" list. If so, it proceeds to generate a fresh page without checking or storing to cache.
    *   Determines the appropriate cache key based on the host and request URI.
    *   Determines the cache expiration time based on configured rules or the default.
    *   Attempts to fetch the page from Memcached using the cache key.
3.  **Cache Hit**: If the page is found in Memcached and is not expired, `index-cached.php` serves the cached HTML directly to the visitor and exits.
4.  **Cache Miss/Bypass**: If the page is not in Memcached, is expired, or the cache is bypassed:
    *   `index-cached.php` captures the output of WordPress generating the page.
    *   If caching is enabled for this request and not bypassed by a cookie, the generated HTML is stored in Memcached with the determined cache key and expiration time.
    *   The freshly generated HTML is served to the visitor.

## Setup and Installation

### Prerequisites

*   A WordPress installation.
*   Memcached server(s) installed and running.
*   The Memcached PECL extension for PHP installed and enabled.
*   Nginx web server (recommended, as the system is primarily designed for it).

### Step 1: Install Files

1.  **Plugin (`memcached-full-page-cache-config.php`)**:
    *   Place the `memcached-full-page-cache-config.php` file (and any associated files if it's part of a larger plugin structure, e.g., in a directory like `wp-content/plugins/memcached-fullpage-cache-config/`) into your WordPress `wp-content/plugins/` directory.
    *   Activate the "Memcached Full Page Cache Config" plugin from the WordPress admin area.

2.  **Front Controller (`index-cached.php`)**:
    *   Place the `index-cached.php` file into the root directory of your WordPress installation (the same directory where `wp-config.php` and the main `index.php` are located).

### Step 2: Configure Nginx

You need to modify your Nginx server block configuration for your WordPress site to direct appropriate requests to `index-cached.php`.

1.  **Generate Nginx Config Snippet**:
    *   Go to "Settings" -> "Memcached Cache Config" in your WordPress admin.
    *   Configure your Memcached server(s) under the "Memcached Servers" section.
    *   Save the settings.
    *   The plugin will generate a file named `memcached_nginx.conf` in your `wp-content/` directory. This file contains a suggested Nginx `upstream` block and location rules.

2.  **Apply Nginx Configuration**:
    *   **Copy the `upstream` block** from `wp-content/memcached_nginx.conf` (e.g., `upstream memcached_servers { ... }`) and place it in your main `nginx.conf` file within the `http { ... }` block, or in a dedicated conf file included by `nginx.conf`.
    *   **Modify your site's server block**:
        The key is to change your `try_files` directive and the PHP processing block.
        A typical WordPress Nginx configuration might look like this:

        ```nginx
        server {
            listen 80;
            server_name example.com;
            root /var/www/html;

            index index.php index.html index.htm;

            location / {
                try_files $uri $uri/ /index.php?$args;
            }

            location ~ \.php$ {
                try_files $uri =404;
                fastcgi_split_path_info ^(.+\.php)(/.+)$;
                fastcgi_pass unix:/var/run/php/phpX.Y-fpm.sock; # Adjust to your PHP-FPM socket/address
                fastcgi_index index.php;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                # ... other params
            }

            # ... other rules (static assets, security)
        }
        ```

        You need to change it to prioritize `index-cached.php` for non-static file requests:

        ```nginx
        # Place this within your http { ... } block, or include the generated memcached_nginx.conf
        # upstream memcached_servers {
        #     server 127.0.0.1:11211;
        #     # server /var/run/memcached/memcached.sock; # Example for Unix socket
        # }

        server {
            listen 80;
            server_name example.com;
            root /var/www/html; # Your WordPress root

            # Use index-cached.php as the primary handler
            index index-cached.php index.php index.html index.htm;

            location / {
                # Try static files first, then pass to index-cached.php
                try_files $uri $uri/ /index-cached.php?$args;
            }

            # Rule for favicon.ico and robots.txt (optional, but good practice)
            location = /favicon.ico { log_not_found off; access_log off; }
            location = /robots.txt  { log_not_found off; access_log off; allow all; }

            # Deny access to sensitive files
            location ~* \.(engine|inc|info|install|make|module|profile|test|po|sh|sql|theme|tpl(\.php)?|xtmpl)$|~$ {
                deny all;
            }
            location ~ /\. {
                deny all;
            }
            location ~ wp-config.php { # Deny access to wp-config.php
                deny all;
            }

            # Handle PHP requests via index-cached.php or index.php
            # This setup ensures that if index-cached.php is directly requested, it's processed.
            # And if a permalink resolves to a PHP file (which it shouldn't with pretty permalinks),
            # it still goes through the caching layer.
            location ~ \.php$ {
                try_files $uri /index-cached.php?$args; # Try direct PHP file, then fall back to index-cached.php

                fastcgi_split_path_info ^(.+\.php)(/.+)$;
                fastcgi_pass unix:/var/run/php/phpX.Y-fpm.sock; # Adjust to your PHP-FPM socket/address
                fastcgi_index index-cached.php; # Important: Nginx will look for index-cached.php
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                # If SCRIPT_FILENAME is $document_root/index-cached.php, that's what PHP executes.
                # If $fastcgi_script_name is something else like /some/other.php,
                # index-cached.php needs to be smart enough to load that, or this rule needs adjustment.
                # However, with WordPress permalinks, $fastcgi_script_name will typically be /index-cached.php.
            }

            # Add browser caching for static assets
            location ~* \.(css|js|jpg|jpeg|gif|png|ico|woff|woff2|ttf|svg|otf)$ {
                expires 1M;
                access_log off;
                add_header Cache-Control "public";
            }
        }
        ```
        **Note**: The Nginx configuration can be complex and site-specific. The example above is a guideline. The crucial part is that requests that would normally go to `index.php` should now go to `index-cached.php`. The `memcached_nginx.conf` generated by the plugin provides a more specific `location / { ... }` block that you might adapt.

3.  **Test Nginx Configuration**:
    `sudo nginx -t`

4.  **Reload Nginx**:
    `sudo systemctl reload nginx` or `sudo service nginx reload`

### Step 3: Configure the Plugin

1.  Navigate to "Settings" -> "Memcached Cache Config" in your WordPress admin dashboard.
2.  **General Settings**:
    *   **Enable Debug**: Check this to add HTML comments at the end of your pages showing cache status (hit/miss, bypass reason) and generation time. Useful for testing.
    *   **Default Cache Time**: Set the default expiration time in seconds for cached pages (e.g., `3600` for 1 hour).
    *   **Purge Cache on Actions**: Enable to automatically clear relevant caches when posts/pages are saved, updated, or deleted.
    *   **Bypass Cache for Cookies**: Add cookie name prefixes (one per line) that should cause the cache to be bypassed. Defaults include common WordPress, WooCommerce, and other plugin cookies.
3.  **Memcached Servers**:
    *   Add your Memcached server(s) by specifying the Host (IP address, hostname, or path to Unix socket) and Port (e.g., `11211`, or `0` for Unix sockets).
    *   The "Status" column will attempt to connect and show if the server is reachable.
4.  **Cache Time Rules**:
    *   Define specific cache times for different URL paths.
    *   **Path**: The URI path prefix (e.g., `/blog/`, `/products/category/`).
    *   **Time in Seconds**: Cache duration for matching paths. `0` means do not cache.
    *   Rules are matched in the order they appear. The first matching rule applies.
5.  **Save Settings**: Click "Save Settings". This will:
    *   Save your settings to the WordPress database.
    *   Generate/update `wp-content/memcached-fp-config.php` (read by `index-cached.php`).
    *   Generate/update `wp-content/memcached_nginx.conf` (for your reference).

### Step 4: Test

1.  Open your website in a browser where you are not logged in (e.g., incognito mode).
2.  View the page source. If debug is enabled, you should see a comment like:
    `<!-- Page generated (cache miss) in X.XXXXXX seconds. | Cache TTL: YYYY seconds -->`
3.  Refresh the page. You should now see:
    `<!-- Page retrieved from cache in X.XXXXXX seconds. | Cache TTL: YYYY seconds -->`
4.  Log in to WordPress. Visit a page. You should see a bypass message if your login cookies are in the bypass list:
    `<!-- Page generated (cache bypassed by cookie) in X.XXXXXX seconds. | Cache Bypassed by Cookie -->`
5.  Test cache purging by editing and saving a post. The cache for that post and the homepage should be cleared.

## Troubleshooting

*   **Permissions**: Ensure your web server has write permissions to the `wp-content/` directory to create `memcached-fp-config.php` and `memcached_nginx.conf`. If not, the plugin will display the content for you to create these files manually.
*   **Memcached PECL Extension**: Verify the Memcached PHP extension is installed and enabled (`php -m | grep memcached`).
*   **Memcached Server Running**: Ensure your Memcached service is running and accessible from your web server.
*   **Nginx Configuration**: Double-check your Nginx configuration. Incorrect `try_files` or `fastcgi_index` directives are common issues.
*   **Plugin Conflicts**: Other caching plugins or security plugins might interfere. Test with a minimal set of plugins if you encounter issues.
*   **Debug Output**: Use the plugin's debug mode and check your PHP error logs and Nginx error logs for clues.

---

This README provides a comprehensive guide to setting up and using your Memcached full-page caching system.
