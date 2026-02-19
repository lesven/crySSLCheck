#!/bin/bash
set -e

# Ensure data and logs directories exist with correct permissions
mkdir -p /var/www/html/data /var/www/html/logs
chown -R www-data:www-data /var/www/html/data /var/www/html/logs

# Start cron daemon in background
cron

# Execute the CMD (apache2-foreground)
exec "$@"
