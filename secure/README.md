# secure

This folder sits outside `web_root` and is used for local security files that must not be web-accessible, such as generated keys and first-user bootstrap codes.

Do not serve this directory publicly. Keep secret files in this folder out of version control and restrict filesystem permissions in production.
