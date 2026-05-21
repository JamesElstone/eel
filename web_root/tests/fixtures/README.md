<!--
  EEL Accounts
  Copyright (c) 2026 James Elstone
  Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
  See LICENSE file for details.
-->
Test fixtures used by `web_root/tests/test_*.php`.

`navigation_pages/` is a static sample pages directory for `test_PageArchitecture.php`.
It intentionally includes:

- valid page files like `uploads.php` and `trialBalance.php`
- ignored files like `_partial.php` and `settings.nav.php`
- an icon file `trialBalance.svg`
- an ignored subdirectory `expenses/`

Tests should treat this folder as read-only fixture data, not runtime scratch space.
