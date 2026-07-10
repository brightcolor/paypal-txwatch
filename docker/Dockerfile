FROM php:8.3-fpm-bookworm

ARG NODE_MAJOR=20

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl \
        libpq-dev libzip-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu-dev libcurl4-openssl-dev libgmp-dev \
        chromium fonts-liberation fonts-noto-color-emoji \
        netcat-openbsd \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip gd intl curl gmp mbstring bcmath pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# puppeteer is installed outside of /var/www/html on purpose: storage/ gets
# bind-mounted over at runtime, and we don't want node_modules to depend on
# that. PUPPETEER_SKIP_DOWNLOAD avoids pulling puppeteer's bundled ~200MB
# Chromium since we already installed the distro's chromium package above
# (see config/pdf.php chrome_path).
ENV PUPPETEER_SKIP_DOWNLOAD=true
RUN mkdir -p /opt/node && cd /opt/node \
    && npm init -y >/dev/null \
    && npm install puppeteer@22

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction

COPY . .

COPY docker/entrypoint.sh /entrypoint.sh

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /entrypoint.sh

# Chromium (via Browsershot/Puppeteer, spawned from PHP as a subprocess of
# this same container) needs a writable HOME for its profile/cache dir;
# www-data has none by default.
ENV HOME=/tmp

USER www-data

EXPOSE 9000
CMD ["/entrypoint.sh"]
