# Companies House filing schemas

This directory is the runtime cache for XML schemas downloaded from the
Companies House XML Gateway.

An administrator populates and refreshes the cache from the **Tax Artifacts**
page. The application downloads the pinned filing schemas and their recursive
dependencies, verifies them, and records their inventory and hashes in the
database. Filing validation then uses only these installed local files.

Downloaded schemas, staging files, and refresh locks are environment-specific
runtime artifacts and must not be committed. This README and the accompanying
`.gitignore` are the only files in this directory intended for version control.

On a new environment, use **Refresh Companies House Filing Schema** before
attempting a Companies House filing.
