# FreeBSD Stack

This document records the recommended FreeBSD package stack for running and
maintaining Swallowtail/eelKit on the `swallowtail` server.

The verified reference host is:

```text
FreeBSD swallowtail 15.0-RELEASE-p9 GENERIC amd64
```

## Runtime Stack

These packages are the minimum runtime stack for the web application:

```sh
pkg install apache24 \
  mariadb-connector-odbc \
  php84-ctype \
  php84-curl \
  php84-filter \
  php84-gd \
  php84-mbstring \
  php84-pdo_odbc \
  php84-session
```

The application expects PHP 8.4 with these modules available:

```text
ctype
curl
filter
gd
json
mbstring
PDO
PDO_ODBC
session
```

The expected PDO driver list must include:

```text
odbc
```

## Recommended Stack

The operational server stack also includes build, maintenance, and admin tools:

```sh
pkg install git \
  sudo \
  screen \
  portmaster \
  portconfig \
  sqlite3 \
  php84-tokenizer \
  cmake-core \
  gmake \
  meson \
  pkgconf \
  corepack \
  gettext-tools
```

Together, the recommended explicit package origins are:

```text
www/apache24
databases/mariadb-connector-odbc
textproc/php84-ctype
ftp/php84-curl
security/php84-filter
graphics/php84-gd
converters/php84-mbstring
databases/php84-pdo_odbc
www/php84-session
devel/git
security/sudo
sysutils/screen
ports-mgmt/portmaster
ports-mgmt/portconfig
databases/sqlite3
devel/php84-tokenizer
devel/cmake-core
devel/gmake
devel/meson
devel/pkgconf
www/corepack
devel/gettext-tools
```

## Install From Packages

For a normal binary package install:

```sh
pkg update
pkg install apache24 mariadb-connector-odbc \
  php84-ctype php84-curl php84-filter php84-gd php84-mbstring \
  php84-pdo_odbc php84-session \
  git sudo screen portmaster portconfig sqlite3 php84-tokenizer \
  cmake-core gmake meson pkgconf corepack gettext-tools
```

After installation, verify PHP and PDO ODBC:

```sh
php -m | grep -Ei 'ctype|curl|filter|gd|json|mbstring|PDO|ODBC|session'
php -r 'print_r(PDO::getAvailableDrivers());'
```

The PDO driver output should include `odbc`.

## Build MariaDB Connector/ODBC From Ports

If the binary package is not suitable, build the connector from ports.

Do not install `databases/mariadb-connector-c` when the MariaDB client package
already provides `mariadb_config`.

Install or confirm the MariaDB client library first:

```sh
pkg info | grep -i maria
find /usr/local -name 'libmariadb.so*' -print
ldconfig -r | grep -i mariadb
```

The expected client library path is usually:

```text
/usr/local/lib/mysql/libmariadb.so.3
```

Build the ODBC connector against that client library:

```sh
cd /usr/ports/databases/mariadb-connector-odbc
make clean
make LDFLAGS="-L/usr/local/lib/mysql" CPPFLAGS="-I/usr/local/include/mysql"
make install
```

Verify the installed driver:

```sh
find /usr/local -name 'libmaodbc.so*' -print
ldd /usr/local/lib/mariadb/libmaodbc.so
```

The expected ODBC driver path is:

```text
/usr/local/lib/mariadb/libmaodbc.so
```

## Configure unixODBC

Register the MariaDB ODBC driver:

```sh
cat > /tmp/mariadb-odbc-driver.template <<'EOF'
[MariaDB]
Description=MariaDB ODBC Driver
Driver=/usr/local/lib/mariadb/libmaodbc.so
Setup=/usr/local/lib/mariadb/libmaodbc.so
FileUsage=1
EOF

odbcinst -i -d -f /tmp/mariadb-odbc-driver.template
odbcinst -q -d
odbcinst -q -d -n MariaDB
```

Check the unixODBC configuration paths:

```sh
odbcinst -j
```

Create or edit `/usr/local/etc/odbc.ini`:

```ini
[swallowtail]
Driver=MariaDB
Description=Swallowtail MariaDB
SERVER=127.0.0.1
PORT=3306
DATABASE=swallowtail
USER=local
PASSWORD=replace_with_real_password
CHARSET=utf8mb4
```

Test the DSN:

```sh
odbcinst -q -s
isql -v swallowtail
isql -v swallowtail local 'replace_with_real_password'
```

## Configure Services

Enable Apache:

```sh
sysrc apache24_enable=YES
service apache24 start
```

If PHP-FPM is used on the host, enable and start it:

```sh
sysrc php_fpm_enable=YES
service php_fpm start
```

Restart services after PHP extension or ODBC DSN changes:

```sh
service php_fpm restart
service apache24 restart
```

## Apache Document Root

Configure Apache so only `web_root` is public.

Do not expose these directories through the web server:

```text
secure
db_schema
tools
file_logs
```

The PHP process must be able to write to:

```text
secure
file_logs
```

On FreeBSD, PHP commonly runs as the `www` user. A typical permission setup is:

```sh
chown root:www secure
chmod 775 secure
```

## Application Setup

From the project root, hydrate configuration and set up the database:

```sh
php tools/php/setupDb.php
```

Or configure the database DSN explicitly:

```sh
php tools/php/setupDb.php \
  --driver=odbc \
  --odbc-name=swallowtail \
  --user=local
```

For later schema updates:

```sh
php tools/php/setupDb.php --migrate-only
```

Run the test suite:

```sh
php web_root/tests/index.php
```

## Production Checks

Before treating the host as production-ready:

```sh
php -m | grep -Ei 'PDO|ODBC'
php -r 'print_r(PDO::getAvailableDrivers());'
service apache24 status
```

Confirm:

- `PDO_ODBC` is loaded.
- `PDO::getAvailableDrivers()` includes `odbc`.
- Apache serves `web_root` as the document root.
- `secure/app.php` is not web-accessible.
- `developer_options` is set to `false` in `secure/app.php`.
- SQL logging is disabled unless actively diagnosing an issue.
