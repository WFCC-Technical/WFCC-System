# PHP + Apache, with the Postgres driver installed
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

# Render assigns a random $PORT at runtime; Apache must listen on it.
CMD sh -c "sed -i \"s/80/\${PORT:-10000}/g\" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf && apache2-foreground"
