#!/bin/bash
set -e

# Verzeichnisse anlegen und Berechtigungen setzen
mkdir -p /var/www/html/data /var/www/html/var/cache /var/www/html/var/log
chown -R www-data:www-data /var/www/html/data /var/www/html/var

# Symfony Cache warmup
php /var/www/html/bin/console cache:warmup --env=prod --no-debug

# Datenbank-Migrationen ausführen
php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction --env=prod

# Standard-Admin-Benutzer anlegen falls noch keiner existiert
php /var/www/html/bin/console app:setup --env=prod

# Cron-Daemon starten
cron

# CMD ausführen (apache2-foreground)
exec "$@"
