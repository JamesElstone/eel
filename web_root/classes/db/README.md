# Database Classes

This folder contains the small database layer used by eelKit.

- `InterfaceDB.php` is the public static interface used by application code.
- `PdoDB.php` owns PDO connection setup, SQL logging, SQLite schema bootstrapping, and ODBC compatibility helpers.
- `PdoStatementDB.php` wraps `PDOStatement` so named parameters can be rewritten for ODBC drivers that expect positional placeholders.

Database settings are read from `config/app.php` under the `db` key. For MariaDB via ODBC, use an ODBC DSN such as `odbc:wccg` and keep real credentials out of version control.

## FreeBSD MariaDB ODBC Setup

Tested with MariaDB 11.8, MariaDB Connector/ODBC 3.2.8, PHP 8.4, unixODBC, and `PDO_ODBC`.

### 1. MariaDB client/server

Install MariaDB from packages or ports. Do not install `databases/mariadb-connector-c` when `mariadb118-client` is already installed, because both provide `mariadb_config`.

Useful checks:

```sh
pkg info | grep maria
find /usr/local -name 'libmariadb.so*' -print
ldconfig -r | grep -i mariadb
```

Expected client library:

```text
/usr/local/lib/mysql/libmariadb.so.3
```

### 2. MariaDB Connector/ODBC

Build the ODBC connector against the MariaDB client library path:

```sh
cd /usr/ports/databases/mariadb-connector-odbc
make clean
make LDFLAGS="-L/usr/local/lib/mysql" CPPFLAGS="-I/usr/local/include/mysql"
make install
```

Verify the driver and linkage:

```sh
find /usr/local -name 'libmaodbc.so*' -print
ldd /usr/local/lib/mariadb/libmaodbc.so
```

Expected driver path:

```text
/usr/local/lib/mariadb/libmaodbc.so
```

### 3. Register the unixODBC Driver

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

### 4. Create a DSN

Check unixODBC paths:

```sh
odbcinst -j
```

Create or edit `/usr/local/etc/odbc.ini`:

```ini
[wccg]
Driver=MariaDB
Description=Welcome Church Community Grocery
SERVER=127.0.0.1
PORT=3306
DATABASE=CommunityGroceryWarehouse
USER=local
PASSWORD=replace_with_real_password
CHARSET=utf8mb4
```

Use `SERVER=localhost` for local socket style access, or `SERVER=127.0.0.1` to force TCP loopback.

Test the DSN:

```sh
odbcinst -q -s
isql -v wccg
isql -v wccg local 'replace_with_real_password'
```

### 5. MariaDB Grants

MariaDB matches both user and host, so `local` at `localhost` and `local` at `127.0.0.1` are separate accounts.

```sql
CREATE USER IF NOT EXISTS 'local'@'localhost'
IDENTIFIED BY 'replace_with_real_password';

GRANT SELECT, INSERT, UPDATE, DELETE
ON CommunityGroceryWarehouse.*
TO 'local'@'localhost';

CREATE USER IF NOT EXISTS 'local'@'127.0.0.1'
IDENTIFIED BY 'replace_with_real_password';

GRANT SELECT, INSERT, UPDATE, DELETE
ON CommunityGroceryWarehouse.*
TO 'local'@'127.0.0.1';

FLUSH PRIVILEGES;
```

For development or migrations, add schema permissions:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
ON CommunityGroceryWarehouse.*
TO 'local'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
ON CommunityGroceryWarehouse.*
TO 'local'@'127.0.0.1';

FLUSH PRIVILEGES;
```

Check grants:

```sql
SHOW GRANTS FOR 'local'@'localhost';
SHOW GRANTS FOR 'local'@'127.0.0.1';
```

### 6. PHP PDO ODBC

```sh
pkg install php84-pdo_odbc
php -m | grep -Ei 'PDO|ODBC'
php -r 'print_r(PDO::getAvailableDrivers());'
```

Expected PDO driver list should include `odbc`.

Restart services after extension or DSN changes:

```sh
service php_fpm restart
service apache24 restart
```

### 7. PHP Connection Test

```sh
php -r '$pdo = new PDO("odbc:wccg", "local", "replace_with_real_password"); echo "Connected\n";'
```

Example config value:

```php
'db' => [
    'dsn' => 'odbc:wccg',
    'user' => 'local',
    'pass' => 'replace_with_real_password',
],
```

## Troubleshooting

- If `isql` works but PHP says `could not find driver`, install or enable `php84-pdo_odbc`.
- If PHP CLI works but the web app fails, restart `php_fpm` and `apache24`.
- If ODBC reports access denied for `local` at `localhost`, create or fix that exact MariaDB user.
- If using `SERVER=127.0.0.1`, ensure `local` at `127.0.0.1` exists.
- Do not rely on `PDO::lastInsertId()` with MariaDB through PDO ODBC in this project.
- Do not commit real DSN passwords or production credentials.
