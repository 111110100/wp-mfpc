# Changelog

All notable changes to this project will be documented in this file.

## [1.6.0] TODO //change this to a date when a version is released
### New features //change this to "Added" once new features are added
- **Minify assets**: Minify CSS, JS, and HTML (including 3rd party)

## [1.5.5] TODO //change this to a date when a new version is released
### New features //change this to "Added" once new features are added
- **Warm up**: Update new cli option: warmup <posts|pages> n1,n2... nX which allows multiple posts/pages to be pre-cached. Replace existing cli option in 1.5.4
- **Mutiple flushes**: Add new cli option: flush <posts|pages> n1,n2... nX which allows multiple posts/pages to be flushed.

## [1.5.4] - New warmup cache command
### Added
- **Warm up**: Add new cli option: warmup <all|post|page> [<id>] that pre-cache content either "all" or by post/page ID

## [1.4.0] - 2025-11-21
### Added
- **Environment Configuration**: Support for `WP_MFPC_SERVERS`, `WP_MFPC_DEBUG`, `WP_MFPC_DEFAULT_CACHE_TIME`, `WP_MFPC_RULES`, and `WP_MFPC_BYPASS_COOKIES` constants in `wp-config.php`.
- **WP-CLI Support**: Added `wp mfpc flush`, `wp mfpc status`, and `wp mfpc generate-nginx` commands.
- **Site Health Integration**: Added a check for Memcached server connectivity in Tools > Site Health.
- **Emergency Bypass**: Added support for a `.mfpc-bypass` file in the root directory to instantly disable caching.

### Changed
- **Namespacing**: Plugin code is now namespaced under `MFPC` to prevent conflicts.
- **Refactoring**: Improved `index-cached.php` robustness and error handling.
- **Admin UI**: Updated to reflect environment configuration overrides.

## [1.3.0]
### Added
- Initial release of the configuration plugin.
