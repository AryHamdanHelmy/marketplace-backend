#!/bin/sh
set -e

# Railway memilih PORT saat container dijalankan, jadi nilainya baru diketahui
# di sini — bukan saat image dibangun.
export PORT="${PORT:-8000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

# Cache dibangun saat start, bukan saat build: config:cache membekukan nilai
# environment, dan saat build variabel produksi belum ada. Kalau ada yang
# salah konfigurasi, lebih baik ketahuan di sini daripada diam-diam memakai
# nilai build.
php artisan config:cache
php artisan route:cache
php artisan event:cache

# storage/ ditulis oleh php-fpm, scheduler, dan worker yang semuanya www-data.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
