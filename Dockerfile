# syntax=docker/dockerfile:1

# API fiscal WhatsApp/NFS-e (Laravel 12 / PHP 8.2).
# Roda Apache (mod_php) + worker de fila + cron do scheduler no mesmo container,
# gerenciados pelo supervisord — pronto para ficar atrás do Traefik.

FROM php:8.2-apache

# --- dependências do sistema + extensões PHP ---------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        default-mysql-client \
        supervisor \
        cron \
        ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache curl xml \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- composer ----------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Apache: document root em public/ + AllowOverride (para o .htaccess) -------
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# --- PHP custom --------------------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-custom.ini

# --- dependências (camada cacheável) -----------------------------------------
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# --- aplicação ---------------------------------------------------------------
COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache

# --- supervisor (apache + queue + cron) --------------------------------------
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/crontab /etc/cron.d/laravel
RUN chmod 0644 /etc/cron.d/laravel && crontab /etc/cron.d/laravel

# --- entrypoint (migra + publica assets) -------------------------------------
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
