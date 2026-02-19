# Composer Stage
FROM composer:2 AS composer

# Application Stage
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    cron \
    libsqlite3-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy Composer manifest files first to leverage Docker layer caching
COPY composer.json composer.lock ./

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy application files
COPY . .
# Create data and logs directories
RUN mkdir -p /var/www/html/data /var/www/html/logs

# Set permissions
RUN chown -R www-data:www-data /var/www/html/data /var/www/html/logs

# Copy Apache virtual host configuration
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Copy cron configuration
COPY docker/crontab /etc/cron.d/tls-scan
RUN chmod 0644 /etc/cron.d/tls-scan

# Copy entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
