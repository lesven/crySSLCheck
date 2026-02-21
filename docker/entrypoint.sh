#!/bin/bash
set -e

# Verzeichnisse anlegen
mkdir -p /var/www/html/data /var/www/html/var/cache /var/www/html/var/log

# Bestimme Environment und Debug-Flag aus Umgebungsvariablen
APP_ENV=${APP_ENV:-prod}
APP_DEBUG=${APP_DEBUG:-0}

# Debug-Option für cache:warmup: wenn APP_DEBUG true/1, dann kein --no-debug
if [ "$APP_DEBUG" = "1" ] || [ "${APP_DEBUG,,}" = "true" ]; then
	DEBUG_FLAG=""
else
	DEBUG_FLAG="--no-debug"
fi

# Symfony Cache warmup
php /var/www/html/bin/console cache:warmup --env="$APP_ENV" $DEBUG_FLAG

# Datenbank-Migrationen ausführen
php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --env="$APP_ENV"

# Standard-Admin-Benutzer anlegen falls noch keiner existiert
php /var/www/html/bin/console app:setup --env="$APP_ENV"

# Berechtigungen setzen (nach Erzeugung von Cache/Logs durch PHP)
chown -R www-data:www-data /var/www/html/data /var/www/html/var

# Cron-Daemon starten
cron

# CMD ausführen (apache2-foreground)
exec "$@"
