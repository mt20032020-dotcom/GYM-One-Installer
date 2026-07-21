#!/bin/bash
# Arranque GYM One: repara permisos ANTES de levantar PHP y nginx
chown -R nobody:nogroup /app/iclock 2>/dev/null || true
find /app/iclock -type d -exec chmod 777 {} \; 2>/dev/null || true
find /app/iclock -type f -exec chmod 666 {} \; 2>/dev/null || true
chown -R nobody:nogroup /app/assets/img/profiles /app/uploads 2>/dev/null || true

node /assets/scripts/prestart.mjs /app/nginx.template.conf /nginx.conf
php-fpm -y /assets/php-fpm.conf &
exec nginx -c /nginx.conf
