#!/bin/sh
set -eu
STAGE=/websites/_staging/unified-platform-20260907/teacher-platform-v1
BACKUP=/var/backups/teacher-platform-upgrade/20260907-133300
TARGET=/websites/_protected/teacher-platform-v1
PUBLIC=/websites/markuspiller.de
test "$(realpath "$STAGE")" = "$STAGE"
test "$(realpath "$TARGET")" = "$TARGET"
test -f "$BACKUP/verified.json"
test -f "$BACKUP/before-files.tar"
test ! -e "$BACKUP/applied"
test "$(curl -fsS https://markuspiller.de/zugang/work.php)" = '{"runtime":"unified-platform-php-probe-v1"}'
chown -R root:www-data "$STAGE"
find "$STAGE" -type d -exec chmod 0750 {} \;
find "$STAGE" -type f -exec chmod 0640 {} \;
# Additive, tested migration while the existing program remains compatible.
runuser -u www-data -- php "$STAGE/bin/migrate.php"
for directory in app registry; do
    find "$STAGE/$directory" -type f | while IFS= read -r source; do
        relative=${source#"$STAGE/"}
        install -d -m 0750 -o root -g www-data "$(dirname "$TARGET/$relative")"
        install -m 0640 -o root -g www-data "$source" "$TARGET/$relative"
        cmp -s "$source" "$TARGET/$relative"
    done
done
install -m 0640 -o root -g www-data "$STAGE/VERSION" "$TARGET/VERSION"
# No deletion or mirroring; only the versioned public package files.
find "$STAGE/public" -type f | while IFS= read -r source; do
    relative=${source#"$STAGE/public/"}
    case "$relative" in lehrer/*|zugriff-anfragen/*|support-anfragen/*|datenschutz-lernplattform/*|api/feedback.php|zugang/*) ;; *) echo 'Unexpected public file';exit 1;; esac
    if [ "$relative" = api/feedback.php ]; then relative=api/teacher-platform/feedback.php; fi
    install -d -m 0755 -o root -g www-data "$(dirname "$PUBLIC/$relative")"
    install -m 0644 -o root -g www-data "$source" "$PUBLIC/$relative"
    cmp -s "$source" "$PUBLIC/$relative"
done
systemctl reload php8.4-fpm
nginx -t
curl -fsS 'https://markuspiller.de/zugang/work.php?action=session'
date -u > "$BACKUP/applied"
echo 'Shared platform 2.0.0 applied; installation linking is still explicit.'
