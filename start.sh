#!/bin/bash
# Arranque GYM One: repara permisos ANTES de levantar PHP y nginx
chown -R nobody:nogroup /app/iclock 2>/dev/null || true
find /app/iclock -type d -exec chmod 777 {} \; 2>/dev/null || true
find /app/iclock -type f -exec chmod 666 {} \; 2>/dev/null || true
chown -R nobody:nogroup /app/assets/img/profiles /app/uploads 2>/dev/null || true
# GYMONE_PERMISOS_EXTRA
# .env es File Mount de Easypanel y vuelve a root en cada deploy:
# sin esto, Configuracion general no puede guardar.
chown nobody:nogroup /app/.env 2>/dev/null || true
chmod 664 /app/.env 2>/dev/null || true
# Volumenes de facturas y logos
mkdir -p /app/assets/docs/invoices /app/assets/img/brand 2>/dev/null || true
chown -R nobody:nogroup /app/assets/docs/invoices /app/assets/img/brand 2>/dev/null || true
chmod 777 /app/assets/docs/invoices 2>/dev/null || true
# vendor lo regenera composer en cada build: mPDF necesita su tmp escribible
mkdir -p /app/vendor/mpdf/mpdf/tmp 2>/dev/null || true
chmod -R 777 /app/vendor/mpdf/mpdf/tmp 2>/dev/null || true
chown -R nobody:nogroup /app/includes 2>/dev/null || true

# Ajuste de PHP-FPM: el default de Nixpacks (50 max / 18 en reposo) es excesivo
# para el trafico real de este gym. Se reduce para no desperdiciar memoria.
sed -i 's/^pm\.max_children = .*/pm.max_children = 12/' /assets/php-fpm.conf 2>/dev/null || true
sed -i 's/^pm\.start_servers = .*/pm.start_servers = 3/' /assets/php-fpm.conf 2>/dev/null || true
sed -i 's/^pm\.min_spare_servers = .*/pm.min_spare_servers = 2/' /assets/php-fpm.conf 2>/dev/null || true
sed -i 's/^pm\.max_spare_servers = .*/pm.max_spare_servers = 5/' /assets/php-fpm.conf 2>/dev/null || true

node /assets/scripts/prestart.mjs /app/nginx.template.conf /nginx.conf
php-fpm -y /assets/php-fpm.conf &
exec nginx -c /nginx.conf
