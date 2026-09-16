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

# Migrasi dijalankan sebelum proses apa pun melayani request.
#
# Sebelum baris ini ada, deploy hanya mengirim kode baru sementara skema
# database tertinggal, dan itu sudah sekali menjatuhkan produksi: kode dengan
# cast 'encrypted' berjalan atas kolom yang belum dimigrasi, sehingga
# /api/seller/balance dan /api/seller/store melempar DecryptException pada
# setiap request sampai migrasinya dijalankan manual.
#
# --isolated mengambil kunci atomik lewat cache, jadi kalau service ini suatu
# saat dijalankan lebih dari satu replica, hanya satu container yang benar-
# benar bermigrasi; sisanya menunggu lalu lanjut. Butuh tabel cache_locks,
# yang sudah dibuat migrasi cache bawaan.
#
# set -e di atas berlaku: migrasi yang gagal menghentikan container, bukan
# membiarkannya melayani request dengan skema yang salah. Container yang
# crash-loop lebih mudah dilihat daripada API yang balas 500 diam-diam.
php artisan migrate --force --isolated

# storage/ ditulis oleh php-fpm, scheduler, dan worker yang semuanya www-data.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
