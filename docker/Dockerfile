FROM php:8.3-fpm-bookworm

ARG NODE_MAJOR=20

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl \
        libpq-dev libzip-dev libonig-dev libpng-dev \
        chromium fonts-liberation fonts-noto-color-emoji \
    && curl -fsSL https://deb.nodesource.com/setup_${NODE_MAJOR}.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# puppeteer is installed outside of /var/www/html on purpose: that path
# gets bind-mounted over at runtime (see docker-compose.yml), which would
# otherwise shadow node_modules. PUPPETEER_SKIP_DOWNLOAD avoids pulling
# puppeteer's bundled ~200MB Chromium since we already installed the
# distro's chromium package above (see config/pdf.php chrome_path).
ENV PUPPETEER_SKIP_DOWNLOAD=true
RUN mkdir -p /opt/node && cd /opt/node \
    && npm init -y >/dev/null \
    && npm install puppeteer@22

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
