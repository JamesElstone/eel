# FreeBSD Stack

Instructions for running eelKit on FreeBSD.

Verified platform:

```text
FreeBSD 15.0-RELEASE-p9 GENERIC amd64
```

## 1. Install Packages

```sh
pkg update
pkg install -y apache24 \
  php84 \
  php84-ctype php84-curl php84-filter php84-gd php84-mbstring \
  php84-pdo php84-pdo_odbc php84-session php84-tokenizer \
  unixODBC git sudo screen portmaster portconfig sqlite3 \
  cmake-core gmake meson pkgconf corepack gettext-tools
```

Confirm the MariaDB client library is already present:

```sh
pkg info | grep -i maria
find /usr/local -name 'libmariadb.so*' -print
ldconfig -r | grep -i mariadb
```

Do not install `databases/mariadb-connector-c` if the installed MariaDB client
package already provides `mariadb_config`.

## 2. Build MariaDB Connector/ODBC

The current FreeBSD ports tree has a known iconv-related bug in
`databases/mariadb-connector-odbc`. Until the ports tree is updated, use the
Makefile included in this repository.

From the eelKit project root:

```sh
cp usr/ports/databases/mariadb-connector-odbc/Makefile \
  /usr/ports/databases/mariadb-connector-odbc/Makefile
```

Build and install the port:

```sh
cd /usr/ports/databases/mariadb-connector-odbc
make clean
make LDFLAGS="-L/usr/local/lib/mysql" CPPFLAGS="-I/usr/local/include/mysql"
make install
```

Verify the driver:

```sh
find /usr/local -name 'libmaodbc.so*' -print
ldd /usr/local/lib/mariadb/libmaodbc.so
```

Expected driver path:

```text
/usr/local/lib/mariadb/libmaodbc.so
```

## 3. Configure PHP PDO ODBC

From the eelKit project root, install the bundled PDO_ODBC ini file:

```sh
cp usr/local/etc/php/ext-30-pdo_odbc.ini \
  /usr/local/etc/php/ext-30-pdo_odbc.ini
```

It must contain:

```ini
extension=pdo_odbc.so
pdo_odbc.connection_pooling=off
```

Verify PHP:

```sh
php -m | grep -Ei 'ctype|curl|filter|gd|json|mbstring|PDO|ODBC|session'
php -r 'print_r(PDO::getAvailableDrivers());'
```

`PDO_ODBC` must be loaded and the PDO driver list must include `odbc`.

Keep ODBC connection pooling disabled. With pooling set to `strict` or
`relaxed`, repeated `new PDO('odbc:eelKit', null, null, ...)` connections
segfaulted in `libodbc.so.2` on FreeBSD/PHP 8.4/unixODBC/MariaDB ODBC.

## 4. Configure unixODBC

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

Create or edit `/usr/local/etc/odbc.ini`:

```ini
[eelKit]
Driver=MariaDB
Description=eelKit MariaDB
SERVER=127.0.0.1
PORT=3306
DATABASE=eelKit
USER=local
PASSWORD=replace_with_real_password
CHARSET=utf8mb4
```

Check the unixODBC configuration:

```sh
odbcinst -j
odbcinst -q -s
```

## 5. Create Database

Create the empty eelKit database with the expected charset and collation:

```sh
printf 'CREATE DATABASE IF NOT EXISTS `eelKit` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n' | \
  isql -v -k 'DRIVER=MariaDB;SERVER=127.0.0.1;PORT=3306;UID=local;PWD=replace_with_real_password;CHARSET=utf8mb4' -b
```

Test the application DSN:

```sh
isql -v eelKit
isql -v eelKit local 'replace_with_real_password'
```

`setupDb.php` creates and migrates the eelKit table schema later.

## 6. Configure PHP-FPM

Enable PHP-FPM:

```sh
sysrc php_fpm_enable=YES
```

Check the PHP-FPM listener:

```sh
grep -n '^listen' /usr/local/etc/php-fpm.d/www.conf
```

The default listener is:

```ini
user = www
group = www
listen = 127.0.0.1:9000
```

## 7. Configure Apache Document Root

Create an Apache include for eelKit:

```sh
cat > /usr/local/etc/apache24/Includes/eelKit.conf <<'EOF'
LoadModule proxy_module libexec/apache24/mod_proxy.so
LoadModule proxy_fcgi_module libexec/apache24/mod_proxy_fcgi.so
LoadModule rewrite_module libexec/apache24/mod_rewrite.so

ServerName eelKit.int.elstone.net:80

<VirtualHost *:80>
    ServerName eelKit.int.elstone.net
    DocumentRoot "/usr/local/eelKit/web_root"

    DirectoryIndex index.php index.html

    <Directory "/usr/local/eelKit/web_root">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000"
    </FilesMatch>

    ErrorLog "/var/log/httpd-eelKit-error.log"
    CustomLog "/var/log/httpd-eelKit-access.log" combined
</VirtualHost>
EOF
```

Only `/usr/local/eelKit/web_root` should be public.

Do not expose:

```text
secure
db_schema
tools
file_logs
```

Allow the PHP user to write to `secure` and `file_logs`:

```sh
chown -R james.elstone:www /usr/local/eelKit
chmod 775 /usr/local/eelKit/secure /usr/local/eelKit/file_logs
find /usr/local/eelKit/secure /usr/local/eelKit/file_logs -type f \
  -exec chmod 664 {} +
```

## 8. Start Services

```sh
sysrc apache24_enable=YES
service php_fpm start
service apache24 start
```

After PHP, ODBC, or Apache changes:

```sh
service php_fpm restart
service apache24 restart
```

## 9. Configure eelKit

From the eelKit project root, hydrate configuration and create or migrate the
table schema:

```sh
php tools/php/setupDb.php
```

To set the ODBC DSN explicitly:

```sh
php tools/php/setupDb.php \
  --driver=odbc \
  --odbc-name=eelKit \
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

## 10. Production Checks

```sh
php -m | grep -Ei 'PDO|PDO_ODBC|mbstring|session|curl|gd'
php -r 'print_r(PDO::getAvailableDrivers());'
php -r 'echo function_exists("imagecreatetruecolor") ? "gd ok\n" : "gd missing\n";'
php -i | grep 'ODBC Connection Pooling'
service apache24 status
```

Confirm:

- `PDO_ODBC` is loaded.
- `PDO::getAvailableDrivers()` includes `odbc`.
- `ODBC Connection Pooling => Disabled`.
- `gd ok`.
- Apache serves `web_root` as the document root.
- `secure/app.php` is not web-accessible.
- `developer_options` is set to `false` in `secure/app.php`.
- SQL logging is disabled unless actively diagnosing an issue.
