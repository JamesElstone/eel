# secure

This folder sits outside `web_root` and is used for local security files that must not be web-accessible, such as generated keys and first-user bootstrap codes.

Do not serve this directory publicly. Keep secret files in this folder out of version control and restrict filesystem permissions in production.

The PHP process must be able to create and remove local setup files in this
directory. On FreeBSD, PHP commonly runs as the `www` user, so a typical
installation can use:

```bash
chown root:www secure
chmod 775 secure
```

If first-user setup reports that `bootstrap_code.txt` could not be created,
check that the PHP user has write permission to this directory.
