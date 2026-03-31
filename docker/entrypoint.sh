#!/bin/bash
set -e

# if a .env.dev file is present (used for local development), load its
# variables into the environment so Symfony / Dotenv sees them.  This is
# necessary because the project mounts only .env into the container and
# Dotenv would otherwise overwrite the values with the empty defaults.
if [ -f /var/www/html/.env.dev ]; then
    echo "Loading environment overrides from .env.dev"
    set -a
    # shellcheck disable=SC1090
    source /var/www/html/.env.dev
    set +a
fi

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

# if the project is bind‑mounted we might not have installed dependencies yet
if [ ! -d /var/www/html/vendor ] || [ -z "$(ls -A /var/www/html/vendor)" ]; then
    echo "Installing composer dependencies…"
    cd /var/www/html
    composer install --no-interaction --optimize-autoloader || true
fi

# Symfony Cache warmup, Migrationen und Setup nur ausführen wenn vendor vorhanden ist.
# Bei einem Erstdeploy kann vendor noch leer sein (leeres tls-vendor-Volume),
# dann übernimmt "make install" diese Schritte nach dem composer install.
if [ -d /var/www/html/vendor ] && [ -n "$(ls -A /var/www/html/vendor)" ]; then
    # Symfony Cache warmup (non-fatal: bei Deploy kann vendor veraltet sein,
    # make install führt diese Schritte nach composer install erneut aus)
    php /var/www/html/bin/console cache:warmup --env="$APP_ENV" $DEBUG_FLAG || echo "WARN: cache:warmup fehlgeschlagen – wird durch 'make install' nachgeholt."

    # Datenbank-Migrationen ausführen
    php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --env="$APP_ENV" || echo "WARN: Migrationen fehlgeschlagen – wird durch 'make install' nachgeholt."

    # Standard-Admin-Benutzer anlegen falls noch keiner existiert
    php /var/www/html/bin/console app:setup --env="$APP_ENV" || echo "WARN: Setup fehlgeschlagen – wird durch 'make install' nachgeholt."
else
    echo "vendor/ nicht vorhanden – überspringe Cache/Migrations/Setup (werden durch 'make install' nachgeholt)."
fi

# Berechtigungen setzen (nach Erzeugung von Cache/Logs durch PHP)
chown -R www-data:www-data /var/www/html/data /var/www/html/var

# Cron-Daemon starten
cron

# CMD ausführen (apache2-foreground)
exec "$@"
