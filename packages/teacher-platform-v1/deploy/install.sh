#!/bin/sh
set -eu

APP_SOURCE=${APP_SOURCE:-/srv/teacher-platform-v1}
CONFIG_DIR=${CONFIG_DIR:-/etc/teacher-platform}
DATA_DIR=${DATA_DIR:-/var/lib/teacher-platform}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/teacher-platform}

test -f "$APP_SOURCE/app/bootstrap.php"
install -d -m 0750 -o root -g www-data "$CONFIG_DIR"
install -d -m 0750 -o www-data -g www-data "$DATA_DIR" "$DATA_DIR/rooms" "$DATA_DIR/ratelimits" "$DATA_DIR/logs" "$DATA_DIR/modules"
install -d -m 0700 -o root -g root "$BACKUP_DIR"

if [ ! -f "$CONFIG_DIR/master.key" ]; then
    umask 027
    php -r 'echo base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), PHP_EOL;' > "$CONFIG_DIR/master.key"
fi
chown root:www-data "$CONFIG_DIR/master.key"
chmod 0640 "$CONFIG_DIR/master.key"

if [ ! -f "$CONFIG_DIR/config.php" ]; then
    install -m 0640 -o root -g www-data "$APP_SOURCE/config/config.example.php" "$CONFIG_DIR/config.php"
    printf '%s\n' 'Konfiguration angelegt. Vor Veröffentlichung Domain, Mail und Pfade prüfen.'
fi

chown -R root:www-data "$APP_SOURCE"
find "$APP_SOURCE" -type d -exec chmod 0750 {} \;
find "$APP_SOURCE" -type f -exec chmod 0640 {} \;
runuser -u www-data -- php "$APP_SOURCE/bin/migrate.php"

printf '%s\n' 'teacher-platform files: ok; Webserverkonfiguration separat prüfen und aktivieren.'
