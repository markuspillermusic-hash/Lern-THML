#!/bin/sh
set -eu

APP_SOURCE=/websites/_protected/teacher-platform-v1
CONFIG_DIR=/etc/teacher-platform
DATA_DIR=/var/lib/teacher-platform
NGINX_TARGET=/etc/nginx/lernpfad-locations/teacher-platform-v1.conf
CRON_TARGET=/etc/cron.d/teacher-platform

test -f "$APP_SOURCE/app/bootstrap.php"
test -f "$APP_SOURCE/deploy/nginx/teacher-platform-v1.conf"

install -d -m 0750 -o root -g www-data "$CONFIG_DIR"
install -d -m 0750 -o www-data -g www-data "$DATA_DIR"
install -d -m 0750 -o www-data -g www-data "$DATA_DIR/rooms" "$DATA_DIR/ratelimits" "$DATA_DIR/logs"
install -d -m 0750 -o www-data -g www-data "$DATA_DIR/modules" "$DATA_DIR/modules/kr12-12.1.1-wer-bin-ich" "$DATA_DIR/modules/kr12-12.1.1-wer-bin-ich/rooms"
install -d -m 0700 -o root -g root /var/backups/teacher-platform

if [ ! -f "$CONFIG_DIR/master.key" ]; then
    umask 027
    php -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;' > "$CONFIG_DIR/master.key"
fi
chown root:www-data "$CONFIG_DIR/master.key"
chmod 0640 "$CONFIG_DIR/master.key"

if [ ! -f "$CONFIG_DIR/config.php" ]; then
    install -m 0640 -o root -g www-data "$APP_SOURCE/config/config.example.php" "$CONFIG_DIR/config.php"
fi

install -m 0644 -o root -g root "$APP_SOURCE/deploy/nginx/teacher-platform-v1.conf" "$NGINX_TARGET"
install -m 0644 -o root -g root "$APP_SOURCE/deploy/cron/teacher-platform" "$CRON_TARGET"

chown -R root:www-data "$APP_SOURCE"
find "$APP_SOURCE" -type d -exec chmod 0750 {} \;
find "$APP_SOURCE" -type f -exec chmod 0640 {} \;

runuser -u www-data -- php "$APP_SOURCE/bin/migrate.php"
nginx -t
systemctl reload nginx

printf '%s\n' 'teacher-platform install: ok'
