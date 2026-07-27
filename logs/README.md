# logs

This folder sits outside `web_root` and can optionally be used to store local log files.

Keep this directory out of the public web root so generated logs are not web-accessible.

## Migrating from `file_logs`

Before deploying the directory rename:

1. Stop application workers that can run Arelle validation.
2. Move `file_logs/` to `logs/`, preserving the `arelle/` contents and permissions.
3. Change the configured Arelle `logs_path` to the absolute `logs/arelle/` path.
4. Apply the EEL Accounts database migrations to update recorded historical log paths.
5. Confirm the PHP worker can write to `logs/arelle/`, then restart application workers.
