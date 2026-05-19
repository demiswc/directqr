# DirectQR

> Your URL → QR. Nothing in between.

A free, self-hostable QR code generator that **embeds your URL directly** in the QR code — no redirect service, no tracking, no expiry, no "free for the first 100 scans" trap.

## Why this exists

Most "free" QR generators online don't actually encode your URL in the QR. They encode a *redirect URL* on their service that forwards to your URL. This means:

- They can show ads, paywalls, or "verify you're human" pages to your visitors
- They track every scan with a UUID and log device info
- If they go out of business, change pricing, or rebrand — every printed QR breaks
- They can change where your QR points to, after you've printed it

DirectQR encodes your URL directly. The QR contains `https://yoursite.com` — not `https://someotherservice.com/r/abc123?url=...`. Once generated, it works forever as long as your site does.

## Features

- **Three styles**: Plain (classic), Designer (stylised dots + rounded finders + centre logo), Halftone (rendered from a photograph)
- **Full colour control** — any hex code, plus presets
- **Centre logo support** with shape options (circle, rounded square, or none)
- **Transparent PNG output** for clean placement on coloured backgrounds
- **Built-in rate limiting** to prevent abuse on public deployments
- **Privacy by default** — uploads and URLs are processed in memory and never saved
- **Accessible** — proper labels, keyboard navigation, screen-reader friendly
- **No JavaScript required** for core functionality (JS adds nice UX but isn't critical)

## Requirements

- PHP 8.1+
- `gd` extension (almost always pre-installed)
- Composer (for installing dependencies)
- ~50 MB disk space

Optional but recommended:
- Nginx or Apache as a reverse proxy
- HTTPS (Let's Encrypt is free)

## Installation (VPS with SSH)

### 1. Clone or upload the files

```bash
cd /var/www
git clone https://github.com/demiswc/directqr.git
# or upload the directqr folder via SCP/rsync
cd directqr
```

### 2. Install Composer dependencies

```bash
composer install --no-dev --optimize-autoloader
```

If Composer isn't installed:
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 3. Set folder permissions

```bash
sudo chown -R www-data:www-data /var/www/directqr
sudo chmod -R 755 /var/www/directqr
```

### 4. Configure your web server

#### Nginx (recommended)

Create `/etc/nginx/sites-available/directqr`:

```nginx
server {
    listen 80;
    server_name qr.yourdomain.com;

    root /var/www/directqr/public;
    index index.php;

    client_max_body_size 10M;

    # Generation settings (passed to PHP)
    fastcgi_param DIRECTQR_RATE_LIMIT 10;
    fastcgi_param DIRECTQR_MAX_UPLOAD_BYTES 5242880;
    fastcgi_param DIRECTQR_MAX_DIMENSION 4000;
    fastcgi_param DIRECTQR_DEBUG 0;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
    }

    # Don't serve composer files or vendor directory
    location ~ /(composer\.(json|lock)|vendor) {
        deny all;
        return 404;
    }
}
```

Enable it:
```bash
sudo ln -s /etc/nginx/sites-available/directqr /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### Apache

Create `/etc/apache2/sites-available/directqr.conf`:

```apache
<VirtualHost *:80>
    ServerName qr.yourdomain.com
    DocumentRoot /var/www/directqr/public

    SetEnv DIRECTQR_RATE_LIMIT 10
    SetEnv DIRECTQR_MAX_UPLOAD_BYTES 5242880
    SetEnv DIRECTQR_MAX_DIMENSION 4000
    SetEnv DIRECTQR_DEBUG 0

    <Directory /var/www/directqr/public>
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    <Directory /var/www/directqr/vendor>
        Require all denied
    </Directory>

    LimitRequestBody 10485760
</VirtualHost>
```

Enable:
```bash
sudo a2ensite directqr
sudo systemctl reload apache2
```

### 5. PHP settings

Edit `/etc/php/8.2/fpm/php.ini` (or your version's path) and ensure:

```ini
upload_max_filesize = 5M
post_max_size = 8M
max_execution_time = 60
memory_limit = 256M
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

### 6. Add HTTPS (strongly recommended)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d qr.yourdomain.com
```

### 7. Test

Visit `https://qr.yourdomain.com` and generate a QR. If something fails, set `DIRECTQR_DEBUG=1` temporarily to see errors, then turn it off.

## Configuration

All settings are environment variables. Override in your web server config:

| Variable | Default | Purpose |
|---|---|---|
| `DIRECTQR_RATE_LIMIT` | `10` | Max generations per minute per IP |
| `DIRECTQR_MAX_UPLOAD_BYTES` | `5242880` | Max upload file size (5 MB) |
| `DIRECTQR_MAX_DIMENSION` | `4000` | Max image dimension (px) |
| `DIRECTQR_DEBUG` | `0` | Show PHP errors. **Only enable while debugging.** |

## Security notes

- **No persistent storage.** Uploads land in PHP's temp directory and are auto-deleted by PHP at the end of each request.
- **Rate limiting** uses IP hash files in `sys_get_temp_dir()` — they're auto-cleaned periodically.
- **Input validation**: URLs must start with a valid scheme; image uploads are dimension-checked, size-checked, and MIME-checked.
- **No external HTTP requests** — the script only reads local files and user uploads.
- **Disable directory listings** in your web server. The sample configs above already do this.

## Performance

- Plain QR: ~50ms per generation
- Designer QR: ~200-400ms (depends on size and logo)
- Halftone QR: ~2-5 seconds (per-pixel Floyd-Steinberg dithering in pure PHP)

For high-traffic deployments, consider:
- Adding a CDN/proxy cache for static assets
- Rewriting the halftone loops in a C extension (or porting to Imagick)
- Switching rate limiting from filesystem to Redis

## Privacy / GDPR

DirectQR does not:
- Use cookies
- Set tracking pixels
- Log user activity beyond standard web server access logs (which you control)
- Send any data to third parties
- Retain uploaded images after a request completes

If you're hosting publicly, you may still want to:
- Add a brief privacy notice page
- Configure your web server's access log retention
- If using HTTPS via Cloudflare/etc, review their privacy policy

## Customisation

The entire app is a single PHP file at `public/index.php`. To brand it:

- Change `APP_NAME` and `APP_TAGLINE` constants at the top
- Edit the CSS variables in the `<style>` block (`--pink`, `--navy`, etc.)
- Edit the "What makes DirectQR different" promise section
- Update the footer

## License

MIT — do whatever you want with it. Forking, modifying, hosting commercially, all fine. Attribution appreciated but not required.

## Issues / contributions

This is intentionally a single-file application. Pull requests welcome for:
- Bug fixes
- New QR styles
- Accessibility improvements
- Translations of the UI

Please **don't** PR features that introduce tracking, analytics, or third-party services. That defeats the point.

---

*Built with care. If this saved you from a paywalled QR service, consider self-hosting and sharing it with a non-technical friend.*
