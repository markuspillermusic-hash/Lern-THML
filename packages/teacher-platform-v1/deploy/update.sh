#!/bin/sh
set -eu

PACKAGE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
TARGET=${TEACHER_PLATFORM_TARGET:-/websites/_protected/teacher-platform-v1}
PUBLIC_ROOT=${TEACHER_PLATFORM_PUBLIC_ROOT:-/websites/markuspiller.de}
CONFIG_FILE=${TEACHER_PLATFORM_CONFIG:-/etc/teacher-platform/config.php}
BACKUP_ROOT=${TEACHER_PLATFORM_UPDATE_BACKUPS:-/var/backups/teacher-platform-updates}
PHP_FPM_SERVICE=${TEACHER_PLATFORM_PHP_FPM_SERVICE:-php8.4-fpm}
STAMP=$(date -u +%Y%m%d-%H%M%S)
BACKUP="$BACKUP_ROOT/$STAMP"

case "$TARGET:$PUBLIC_ROOT:$CONFIG_FILE:$BACKUP_ROOT" in
  /*:/*:/*:/*) ;;
  *) echo 'Nur absolute Deploymentpfade sind erlaubt.' >&2; exit 1 ;;
esac
test "$TARGET" != / && test "$PUBLIC_ROOT" != / && test "$BACKUP_ROOT" != /
test -f "$PACKAGE_DIR/app/bootstrap.php"
test -f "$TARGET/app/bootstrap.php"
test -f "$CONFIG_FILE"

for module in pdo_sqlite sodium openssl zip SimpleXML; do
  php -m | grep -qx "$module" || { echo "Fehlendes PHP-Modul: $module" >&2; exit 1; }
done

find "$PACKAGE_DIR/app" "$PACKAGE_DIR/public" "$PACKAGE_DIR/tests" -type f -name '*.php' -exec php -l {} \; >/dev/null
php "$PACKAGE_DIR/tests/run.php" >/dev/null
php "$PACKAGE_DIR/tests/unified-platform.php" >/dev/null

install -d -m 0700 -o root -g root "$BACKUP"
runuser -u www-data -- env TEACHER_PLATFORM_CONFIG="$CONFIG_FILE" php "$TARGET/bin/backup.php" > "$BACKUP/database-backup.txt"
umask 077
tar -cf "$BACKUP/files-before.tar" -C / "${TARGET#/}" "${PUBLIC_ROOT#/}/zugang" "${PUBLIC_ROOT#/}/lehrer" "${PUBLIC_ROOT#/}/api/teacher-platform"

for directory in app registry bin deploy; do
  find "$PACKAGE_DIR/$directory" -type f | while IFS= read -r source; do
    relative=${source#"$PACKAGE_DIR/"}
    install -d -m 0750 -o root -g www-data "$(dirname "$TARGET/$relative")"
    install -m 0640 -o root -g www-data "$source" "$TARGET/$relative"
  done
done
install -m 0640 -o root -g www-data "$PACKAGE_DIR/VERSION" "$TARGET/VERSION"

runuser -u www-data -- env TEACHER_PLATFORM_CONFIG="$CONFIG_FILE" php "$TARGET/bin/migrate.php" >/dev/null
find "$PACKAGE_DIR/public" -type f | while IFS= read -r source; do
  relative=${source#"$PACKAGE_DIR/public/"}
  case "$relative" in
    lehrer/*|zugriff-anfragen/*|support-anfragen/*|datenschutz-lernplattform/*|zugang/*) target="$PUBLIC_ROOT/$relative" ;;
    api/feedback.php) target="$PUBLIC_ROOT/api/teacher-platform/feedback.php" ;;
    *) echo "Unerwartete öffentliche Datei: $relative" >&2; exit 1 ;;
  esac
  install -d -m 0755 -o root -g www-data "$(dirname "$target")"
  install -m 0644 -o root -g www-data "$source" "$target"
done

nginx -t
systemctl reload "$PHP_FPM_SERVICE"
systemctl reload nginx
curl -fsS 'https://markuspiller.de/zugang/work.php?action=session' > "$BACKUP/health.json"
printf 'Teacher platform %s deployed; backup: %s\n' "$(cat "$TARGET/VERSION")" "$BACKUP"
