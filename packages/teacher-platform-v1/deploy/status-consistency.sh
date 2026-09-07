#!/usr/bin/env bash
# One-off completion patch, no account data is changed by deployment.
set -euo pipefail
stage=/websites/_staging/unified-platform-20260907/status-consistency
private=/websites/_protected/teacher-platform-v1
public=/websites/markuspiller.de
backup=/var/backups/teacher-platform-upgrade/status-consistency-20260907
test "$(id -u)" = 0
test ! -e "$backup"
for file in Identity.php Portal.php index.php; do php -l "$stage/$file"; done
test -f "$private/app/Identity.php"
test -f "$private/app/Portal.php"
test -f "$public/lehrer/index.php"
install -d -m 0700 "$backup"
tar -cf "$backup/before-files.tar" -C / \
  websites/_protected/teacher-platform-v1/app/Identity.php \
  websites/_protected/teacher-platform-v1/app/Portal.php \
  websites/markuspiller.de/lehrer/index.php
install -o root -g www-data -m 0640 "$stage/Identity.php" "$private/app/Identity.php"
install -o root -g www-data -m 0640 "$stage/Portal.php" "$private/app/Portal.php"
install -o root -g www-data -m 0644 "$stage/index.php" "$public/lehrer/index.php"
cmp "$stage/Identity.php" "$private/app/Identity.php"
cmp "$stage/Portal.php" "$private/app/Portal.php"
cmp "$stage/index.php" "$public/lehrer/index.php"
systemctl reload php8.4-fpm
printf '%s\n' STATUS_CONSISTENCY_DEPLOYED
