#!/bin/sh
set -eu

APP_SOURCE=/websites/_protected/teacher-platform-v1
PUBLIC_ROOT=/websites/markuspiller.de

php -v
php -m | grep -qx 'pdo_sqlite'
php -m | grep -qx 'sodium'
php -m | grep -qx 'openssl'
test -x /usr/sbin/sendmail
test -f "$APP_SOURCE/app/bootstrap.php"
test -f "$PUBLIC_ROOT/lehrer/index.php"
test -f "$PUBLIC_ROOT/zugriff-anfragen/index.php"
test -f "$PUBLIC_ROOT/support-anfragen/index.php"
test -f "$PUBLIC_ROOT/api/teacher-platform/feedback.php"

find "$APP_SOURCE/app" "$APP_SOURCE/registry" "$APP_SOURCE/bin" -type f -name '*.php' -print | while IFS= read -r file; do
    php -l "$file"
done
php -l "$PUBLIC_ROOT/lehrer/index.php"
php -l "$PUBLIC_ROOT/zugriff-anfragen/index.php"
php -l "$PUBLIC_ROOT/support-anfragen/index.php"
php -l "$PUBLIC_ROOT/api/teacher-platform/feedback.php"
for endpoint in index.php oidc.php directory.php work.php arbeiten/index.php; do
    test -f "$PUBLIC_ROOT/zugang/$endpoint"
    php -l "$PUBLIC_ROOT/zugang/$endpoint"
done
nginx -t

printf '%s\n' 'teacher-platform preflight: ok'
