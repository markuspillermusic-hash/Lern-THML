#!/bin/sh
set -eu
STAGE=/websites/_staging/unified-platform-20260907/teacher-platform-v1
BACKUP=/var/backups/teacher-platform-upgrade/20260907-133300
NGINX=/etc/nginx/lernpfad-locations/teacher-platform-v1.conf
PUBLIC=/websites/markuspiller.de
test "$(realpath "$STAGE")" = "$STAGE"
test -f "$BACKUP/verified.json"
test ! -e "$BACKUP/before-files.tar"
test ! -e "$PUBLIC/zugang"
umask 077
tar -cf "$BACKUP/before-files.tar" -C / websites/_protected/teacher-platform-v1/app websites/_protected/teacher-platform-v1/registry websites/_protected/teacher-platform-v1/bin websites/_protected/teacher-platform-v1/VERSION websites/markuspiller.de/lehrer websites/markuspiller.de/zugriff-anfragen websites/markuspiller.de/support-anfragen websites/markuspiller.de/datenschutz-lernplattform websites/markuspiller.de/api/teacher-platform etc/teacher-platform etc/nginx/lernpfad-locations/teacher-platform-v1.conf
cp -a "$NGINX" "$BACKUP/nginx-before.conf"
find "$STAGE/app" "$STAGE/public" -name '*.php' -type f -exec php -l {} \; > "$BACKUP/php-lint.txt"
if grep -q 'Errors parsing' "$BACKUP/php-lint.txt"; then exit 1; fi
install -m 0644 "$STAGE/deploy/nginx/teacher-platform-v1.conf" "$NGINX"
if ! nginx -t; then cp -a "$BACKUP/nginx-before.conf" "$NGINX"; exit 1; fi
systemctl reload nginx
install -d -m 0755 "$PUBLIC/zugang"
install -m 0644 "$STAGE/deploy/runtime-probe.php" "$PUBLIC/zugang/work.php"
echo 'Backup, limited routes and harmless PHP probe ready; verify public response before continuing.'
