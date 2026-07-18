# PHP runtime requirements

## Required extensions

The application and its eelKit runtime use the following PHP extensions:

- `ext-pdo` — database abstraction used throughout the application.
- `ext-pdo_odbc` — database driver required by this installation, whose active
  database connection uses ODBC. A suitable Windows ODBC driver and DSN must
  also be installed and configured.
- `ext-curl` — outbound HTTP requests to HMRC, Companies House, and government
  tax-rate sources.
- `ext-mbstring` — multibyte-safe string validation, conversion, and
  truncation.
- `ext-openssl` — encryption and decryption of OTP secrets.
- `ext-gd` — generation of QR-code PNG images.
- `ext-fileinfo` — MIME-type validation for receipt, evidence, and statement
  uploads.
- `ext-zlib` — spreadsheet/export compression and HMRC ZIP processing.
- `ext-dom` — XML and HTML document parsing and generation.
- `ext-libxml` — underlying XML support used by DOM and XSL.
- `ext-xsl` — provides `XSLTProcessor` and XSLT transformations.

The application also uses `json`, `session`, `filter`, `hash`, `random`, and
`pcre`. These are standard PHP modules and are normally built into or enabled
by supported PHP distributions, but deployments should still include them in
platform checks.

`dom` and `libxml` are normally included and enabled in standard PHP builds. On
Windows, several extensions are commonly shipped as DLLs but may need to be
enabled explicitly in `php.ini`. The relevant entries for this installation
are:

```ini
extension=curl
extension=fileinfo
extension=gd
extension=mbstring
extension=openssl
extension=pdo_odbc
extension=xsl
```

`pdo`, `dom`, `libxml`, `zlib`, and several standard modules may be compiled
into the PHP build and therefore may not need separate `extension=` entries.

After changing `php.ini`, restart the web server or PHP service that hosts the
application. The command-line and web-server runtimes can use different INI
files, so check each relevant runtime:

```powershell
php --ini
php -m
php --ri xsl
php -r "foreach (['PDO', 'pdo_odbc', 'curl', 'mbstring', 'openssl', 'gd', 'fileinfo', 'zlib', 'dom', 'libxml', 'xsl'] as $extension) { printf('%s: %s%s', $extension, extension_loaded($extension) ? 'loaded' : 'MISSING', PHP_EOL); }"
```

## Machine-readable dependency declaration

The normal way for a Composer-managed PHP project to declare extension
requirements is in the root `composer.json` file:

```json
{
  "require": {
    "php": "^8.4",
    "ext-curl": "*",
    "ext-dom": "*",
    "ext-fileinfo": "*",
    "ext-filter": "*",
    "ext-gd": "*",
    "ext-hash": "*",
    "ext-json": "*",
    "ext-libxml": "*",
    "ext-mbstring": "*",
    "ext-openssl": "*",
    "ext-pcre": "*",
    "ext-pdo": "*",
    "ext-pdo_odbc": "*",
    "ext-random": "*",
    "ext-session": "*",
    "ext-zlib": "*",
    "ext-xsl": "*"
  }
}
```

Composer then checks the runtime during `composer install` and
`composer check-platform-reqs`. The `*` value means that the extension must be
installed; PHP extensions generally do not expose useful independent versions
for application dependency constraints.

This repository does not currently have a `composer.json`, so this document is
the human-readable source of the requirement. If Composer is introduced, add
the extension declarations above so setup failures are detected automatically.

If another deployment uses a different database backend, replace
`ext-pdo_odbc` with the matching PDO driver, such as `ext-pdo_mysql`,
`ext-pdo_pgsql`, or `ext-pdo_sqlite`.
