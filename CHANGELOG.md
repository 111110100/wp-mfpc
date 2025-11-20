# Changelog

All notable changes to this project will be documented in this file.

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
