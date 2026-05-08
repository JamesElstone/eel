#!/usr/bin/env sh
# EEL Accounts
# Copyright (c) 2026 James Elstone
# Licensed under the BSD 3-Clause License
# See LICENSE file for details.
echo "EEL Accounts"
echo " Copyright (c) 2026 James Elstone"
echo " Licensed under the BSD 3-Clause License"
echo " See LICENSE file for details."
echo
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
php "$script_dir/migrateDb.php"
