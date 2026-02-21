# Build Stage: Composer
FROM composer:2 AS composer

# Application Stage
FROM php:8.2-apache

# System-Abhängigkeiten installieren
RUN apt-get update && apt-get install -y \
    cron \
    libsqlite3-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP-Erweiterungen installieren
RUN docker-php-ext-install pdo pdo_sqlite opcache

# Apache-Module aktivieren
RUN a2enmod rewrite

# Composer installieren
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Arbeitsverzeichnis setzen
WORKDIR /var/www/html

# Composer-Dateien zuerst kopieren (Layer-Caching)
COPY composer.json composer.lock symfony.lock ./

# PHP-Abhängigkeiten installieren (ohne Dev-Dependencies)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Anwendungsdateien kopieren
COPY . .

# Symfony-Scripts ausführen (assets:install etc.)
RUN composer run-script --no-interaction post-install-cmd || true

# Verzeichnisse anlegen und Berechtigungen setzen
RUN mkdir -p /var/www/html/data /var/www/html/var/cache /var/www/html/var/log
RUN chown -R www-data:www-data /var/www/html/data /var/www/html/var

# Apache Virtual Host konfigurieren
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Cron-Konfiguration kopieren
COPY docker/crontab /etc/cron.d/tls-scan
RUN chmod 0644 /etc/cron.d/tls-scan

# Entrypoint kopieren
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
