#!/bin/sh
set -eu

# O volume dedicado às imagens precisa ser gravável pelo processo PHP do Apache.
UPLOAD_DIR="/var/www/html/uploads/products"
HTACCESS_SOURCE="/usr/local/share/oportuniza/products.htaccess"
mkdir -p "$UPLOAD_DIR"
if [ ! -f "$UPLOAD_DIR/.htaccess" ] && [ -f "$HTACCESS_SOURCE" ]; then
  cp "$HTACCESS_SOURCE" "$UPLOAD_DIR/.htaccess"
fi
chown -R www-data:www-data "$UPLOAD_DIR"
chmod 0750 "$UPLOAD_DIR"
if [ -f "$UPLOAD_DIR/.htaccess" ]; then chmod 0640 "$UPLOAD_DIR/.htaccess"; fi

exec docker-php-entrypoint "$@"
