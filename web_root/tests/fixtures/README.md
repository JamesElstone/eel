Test fixtures used by `web_root/tests/test_*.php`.

`navigation_pages/` is a static sample pages directory for `test_PageArchitecture.php`.
It intentionally includes:

- valid page files like `uploads.php` and `trialBalance.php`
- ignored files like `_partial.php` and `settings.nav.php`
- an icon file `trialBalance.svg`
- an ignored subdirectory `expenses/`

Tests should treat this folder as read-only fixture data, not runtime scratch space.
