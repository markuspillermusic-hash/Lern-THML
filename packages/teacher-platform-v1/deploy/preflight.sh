#!/bin/sh
set -eu

APP_SOURCE=${APP_SOURCE:-/srv/teacher-platform-v1}
PUBLIC_ROOT=${PUBLIC_ROOT:-/var/www/learning-html}

php -v
php -m | grep -qx 'pdo_sqlite'
php -m | grep -qx 'sodium'
php -m | grep -qx 'openssl'
test -f "$APP_SOURCE/app/bootstrap.php"
test -f "$PUBLIC_ROOT/lehrer/index.php"
test -f "$PUBLIC_ROOT/zugriff-anfragen/index.php"
test -f "$PUBLIC_ROOT/api/teacher-platform/feedback.php"

find "$APP_SOURCE/app" "$APP_SOURCE/registry" "$APP_SOURCE/bin" -type f -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
php -l "$PUBLIC_ROOT/lehrer/index.php"
php -l "$PUBLIC_ROOT/zugriff-anfragen/index.php"
php -l "$PUBLIC_ROOT/api/teacher-platform/feedback.php"

printf '%s\n' 'teacher-platform preflight: ok; Webservertest anschließend ausführen.'
