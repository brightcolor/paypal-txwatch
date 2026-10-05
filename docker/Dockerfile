FROM php:8.3-fpm-bookworm

# Major version of Node.js from NodeSource. Node.js runs Puppeteer for the PDF
# export; Puppeteer 25 (PUPPETEER_MAJOR below) needs Node.js 22.12 or later.
ARG NODE_MAJOR=24

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

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

# puppeteer is installed outside of /var/www/html on purpose: storage/ gets
# bind-mounted over at runtime, and we don't want node_modules to depend on
# that. PUPPETEER_SKIP_DOWNLOAD avoids pulling puppeteer's bundled ~200MB
# Chromium since we already installed the distro's chromium package above
# (see config/pdf.php chrome_path).
#
# PUPPETEER_DIR is the folder of the module and PUPPETEER_MAJOR its major
# version. NODE_MODULE_PATH follows the folder, so config/pdf.php finds the
# module there; an entry in .env still takes precedence.
#
# Each Puppeteer release is built for one Chrome version (25.12: Chrome 154),
# and the chromium package comes with Debian's security updates. CI renders
# sample PDFs in the built image (`php artisan pdf:check`) before pushing it.
# --engine-strict stops the build when NODE_MAJOR is too old for Puppeteer.
ARG PUPPETEER_DIR=/opt/node
ARG PUPPETEER_MAJOR=25
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    NODE_MODULE_PATH=${PUPPETEER_DIR}/node_modules
WORKDIR ${PUPPETEER_DIR}
RUN npm init -y >/dev/null \
    && npm install --engine-strict "puppeteer@${PUPPETEER_MAJOR}"

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction

COPY . .

COPY docker/entrypoint.sh /entrypoint.sh

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && chmod +x /entrypoint.sh

# Chromium (via Browsershot/Puppeteer, spawned from PHP as a subprocess of
# this same container) needs a writable HOME for its profile/cache dir;
# www-data has none by default.
ENV HOME=/tmp

USER www-data

EXPOSE 9000
CMD ["/entrypoint.sh"]
