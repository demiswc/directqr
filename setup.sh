#!/bin/bash
#
# DirectQR — one-command setup on a fresh Ubuntu/Debian VPS
#
# Usage:  sudo bash setup.sh yourdomain.com
#
# This installs PHP, composer, nginx, and DirectQR itself.
# Re-run with the same domain to update.

set -e

DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
  echo "Usage: sudo bash setup.sh <yourdomain.com>"
  exit 1
fi

INSTALL_DIR="/var/www/directqr"

echo "==> Installing system packages..."
apt update
apt install -y nginx php-fpm php-cli php-gd php-mbstring php-xml php-zip curl unzip

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "==> Detected PHP $PHP_VERSION"

if ! command -v composer &> /dev/null; then
  echo "==> Installing Composer..."
  curl -sS https://getcomposer.org/installer | php
  mv composer.phar /usr/local/bin/composer
fi

echo "==> Setting up $INSTALL_DIR ..."
mkdir -p "$INSTALL_DIR"
cp -r ./public ./composer.json ./LICENSE ./README.md "$INSTALL_DIR/"

cd "$INSTALL_DIR"
echo "==> Installing PHP dependencies..."
sudo -u www-data composer install --no-dev --optimize-autoloader

chown -R www-data:www-data "$INSTALL_DIR"

echo "==> Writing nginx config..."
cat > /etc/nginx/sites-available/directqr <<NGINX
server {
    listen 80;
    server_name $DOMAIN;

    root $INSTALL_DIR/public;
    index index.php;
    client_max_body_size 10M;

    fastcgi_param DIRECTQR_RATE_LIMIT 10;
    fastcgi_param DIRECTQR_MAX_UPLOAD_BYTES 5242880;
    fastcgi_param DIRECTQR_MAX_DIMENSION 4000;
    fastcgi_param DIRECTQR_DEBUG 0;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    location ~ /(composer\.(json|lock)|vendor) {
        deny all;
        return 404;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/directqr /etc/nginx/sites-enabled/directqr

echo "==> Testing nginx config..."
nginx -t

echo "==> Reloading nginx..."
systemctl reload nginx

echo
echo "==> Done! Visit http://$DOMAIN"
echo
echo "Next steps:"
echo "  1. Point an A record for $DOMAIN at this server's IP"
echo "  2. Install HTTPS:  sudo apt install certbot python3-certbot-nginx"
echo "                     sudo certbot --nginx -d $DOMAIN"
echo "  3. Set strong PHP settings in /etc/php/$PHP_VERSION/fpm/php.ini :"
echo "       upload_max_filesize = 5M"
echo "       post_max_size = 8M"
echo "       max_execution_time = 60"
