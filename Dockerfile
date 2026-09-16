FROM php:8.3-fpm

# Library sistem yang dibutuhkan gd dan zip untuk dikompilasi.
# gd tidak bisa dipasang tanpa libpng/libjpeg, zip butuh libzip.
# nginx, supervisor, dan gettext-base (envsubst) dipakai saat runtime.
RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    nginx \
    supervisor \
    gettext-base \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
       pdo_mysql \
       mbstring \
       exif \
       bcmath \
       gd \
       zip \
       opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# opcache mati secara default di image resmi. Tanpa ini setiap request
# mengompilasi ulang seluruh file PHP yang disentuhnya.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
      echo 'memory_limit=256M'; \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=12M'; \
      echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Salin file composer dulu supaya layer ini bisa di-cache.
# Kalau kode berubah tapi dependensi tidak, build berikutnya lebih cepat.
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-interaction --no-scripts

COPY . .

COPY docker/nginx.conf /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN composer dump-autoload --optimize \
    && chmod +x /usr/local/bin/entrypoint \
    && rm -f /etc/nginx/sites-enabled/default \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
