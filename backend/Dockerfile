FROM php:8.4-fpm

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        postgresql-client \
    && docker-php-ext-configure \
        gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        gd \
        intl \
        opcache \
        pdo_pgsql \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Число рабочих процессов задаётся при запуске, а не вбито в образ: почему
# двадцать четыре и на чём это замерено — в самом скрипте.
COPY docker/portal-entrypoint.sh /usr/local/bin/portal-entrypoint.sh
RUN chmod +x /usr/local/bin/portal-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/portal-entrypoint.sh"]
CMD ["php-fpm"]
