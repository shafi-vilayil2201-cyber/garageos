# Container image for hosted demo environments (e.g. Render). Real client
# installs still use scripts/install-garageos.sh on a plain VM — this is
# the same app, just packaged so a platform without native PHP can run it.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/* \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/apache-site.conf /etc/apache2/sites-available/000-default.conf
COPY docker/start.sh /usr/local/bin/garageos-start

WORKDIR /var/www/garageos
COPY . .

RUN mkdir -p public/uploads/logos public/uploads/damage \
    && chown -R www-data:www-data public/uploads \
    && chmod +x /usr/local/bin/garageos-start

# Render (and most PaaS hosts) tell the app which port to listen on via $PORT.
ENV PORT=8080
EXPOSE 8080

CMD ["garageos-start"]
