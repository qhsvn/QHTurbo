# QHturbo Compatible

**English** · [Tiếng Việt](compatible.vi.md)

**Goal:** run standard PHP, PHP-FPM, Composer and legacy applications **without code changes**. Everything works like an ordinary PHP-FPM install, just with a better configuration and bundled diagnostic tools.

| | |
|---|---|
| PHP versions | 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 |
| Execution model | nginx/Apache → PHP-FPM, the application boots again on every request (like traditional PHP) |
| Requirements for the application | None |
| When to use | Default for every site: WordPress, Laravel, legacy code, sites on aaPanel |

## Verified (2026-10-22, `full` build)

| Check | PHP 8.5.12 | PHP 7.4.33 |
|---|---|---|
| `full` build (FPM + 51 modules: openssl, curl, intl, gd, mysqli, pdo_mysql, zip, sodium, soap...) | OK | OK (separate OpenSSL 1.1.1w) |
| `scripts/install.sh` into `/opt/qhturbo/<branch>`, `php-fpm -t` | OK | OK |
| nginx → FPM over a dedicated socket, both versions running side by side | `php85-fpm.sock` | `php74-fpm.sock` |
| **The same legacy-style application**: `session_start`, `$_GET`, `header()`, `setcookie`, `$_FILES`, PDO SQLite, `include` | OK | OK, identical output |
| intl (`1.234.567 ₫`), gd, openssl, sodium, mbstring | OK | OK |
| **Composer 2.8** running on QHturbo PHP | `create-project laravel/laravel` (Laravel 13.38, more than 100 packages) | `require monolog guzzle`: automatically picks 7.4-compatible versions |
| HTTPS with certificate verification | OK | OK (Guzzle → packagist, 200) |
| Laravel via nginx → FPM / `php artisan test` | HTTP 200 / 2/2 pass | — (Laravel 13 requires PHP 8.3+) |
| Official PHPT suite (minimal build) | 13,568 pass / 0 fail | 8,997 pass, 46 fail due to the environment |

## What differs from stock PHP-FPM

Configuration only, no change to PHP semantics (`config/modes/compatible/`):

- **OPcache enabled by default** with sizes suited to Composer/frameworks: 256M memory, 50,000 files, 32M interned strings.
- **`validate_timestamps=1` is kept** (checked every 2 seconds): edits via FTP, the aaPanel file manager or WordPress auto-updates take effect as before. This is the "compatibility first, speed second" choice.
- **JIT off** (only enable it after measuring with a real application).
- `realpath_cache` 4M/600s reduces `stat()` calls for Composer autoloading.
- FPM pool: `pm.max_requests=1000` (periodically recycles child processes to contain leaks from legacy code/extensions), with slowlog and status/ping pages.
- **Installed side by side, never overwriting:** prefix `/opt/qhturbo/<branch>`, dedicated socket and service. `install.sh` refuses to install into `/usr`, `/etc`, `/www/server/php`.

## Installation

```bash
export QHT_LAB=~/QHTurbo-lab QHT_PHP=8.5      # or 7.4 ... 8.4
sudo apt install -y build-essential autoconf bison re2c pkg-config \
  libxml2-dev libssl-dev libsqlite3-dev zlib1g-dev libcurl4-openssl-dev libzip-dev \
  libonig-dev libicu-dev libsodium-dev libpng-dev libjpeg-dev libfreetype-dev libgmp-dev libreadline-dev
scripts/bootstrap.sh
[ "$QHT_PHP" = 7.4 ] || [ "$QHT_PHP" = 8.0 ] && scripts/deps-openssl11.sh   # 7.4/8.0 only
QHT_PROFILE=full scripts/build.sh baseline
sudo -E scripts/install.sh                     # -> /opt/qhturbo/8.5
sudo cp /opt/qhturbo/8.5/share/qhturbo/systemd/qhturbo-php85-fpm.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php85-fpm
/opt/qhturbo/8.5/bin/php /opt/qhturbo/8.5/share/qhturbo/qht-doctor.php
```

Packaging to move to another server: `scripts/package.sh` (same distro release as the build machine, since the binary links against system libraries such as libcurl and ICU).

### Moving an aaPanel site to QHturbo (with a way back)

The fastest and safest way: use `scripts/aapanel.sh` (automatic backup, automatic rollback). See [docs/aapanel.md](../aapanel.md). The manual steps below are for reference:

1. Back up the vhost: `cp /www/server/panel/vhost/nginx/site.conf{,.bak}`.
2. In the vhost, replace `include enable-php-85.conf;` with:
   ```nginx
   location ~ [^/]\.php(/|$) {
       try_files $uri =404;
       fastcgi_pass unix:/run/qhturbo/php85-fpm.sock;
       fastcgi_index index.php;
       include fastcgi.conf;
   }
   ```
   aaPanel runs nginx as the `www` user, so install with `--user=www --group=www`.
3. `nginx -t && nginx -s reload`, then watch the logs and the 5xx rate.
4. **Rollback:** restore the `.bak` file, then `nginx -s reload`. aaPanel's own PHP keeps running untouched.

## PHP 7.4 / 8.0 and OpenSSL

PHP < 8.1 does not compile against OpenSSL 3 (Ubuntu 22.04+). `scripts/deps-openssl11.sh` builds OpenSSL 1.1.1w into `$QHT_LAB/deps/openssl-1.1`, and PHP links to it via rpath. The system OpenSSL is not touched. **OpenSSL 1.1.1 is also end-of-life**: use it only for legacy applications while preparing to upgrade.

## Tuning

- `scripts/fpm-tune.py`: computes `pm.max_children` from RAM and the actual RSS of FPM workers.
- `qht-doctor.php`: checks OPcache, Xdebug, realpath cache, extensions, EOL versions.
- For atomic deploys (symlink + FPM reload): you can set `opcache.validate_timestamps=0` to gain a few more percent. Measurements show this gain is small (`results/web/`).
