# Trying QHturbo on aaPanel

**English** · [Tiếng Việt](aapanel.vi.md)

A guide to trying **QHturbo Compatible** on an aaPanel server, **one site at a time** and always with a way back. The PHP installed by aaPanel (`/www/server/php/...`) **is not touched**.

**Summary (5 commands):**

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src && cd /opt/qhturbo-src
sudo scripts/aapanel.sh check   --php=8.5                 # check the server, changes nothing
sudo scripts/aapanel.sh install --php=8.5                 # build + install + run a separate PHP-FPM (10-40 minutes)
sudo scripts/aapanel.sh enable  test.example.com --php=8.5   # switch 1 site; rolls back automatically on error
sudo scripts/aapanel.sh disable test.example.com          # go back to aaPanel's PHP
```

---

## 0. Preparation

| Requirement | Notes |
|---|---|
| aaPanel using **Nginx** | The script does not support Apache/OpenLiteSpeed yet |
| Ubuntu 20.04 / 22.04 / 24.04, Debian 11 / 12 | AlmaLinux/Rocky: the script has a `dnf` branch but it is **not tested yet** |
| Root access over SSH | |
| RAM ≥ 2 GB, or add swap | Compiling PHP (intl, Zend VM) uses a lot of RAM |
| ≥ 4 GB free disk space | Source code and builds live in `/opt/qhturbo-lab` |

**Do this first:**

1. **Snapshot/back up the VPS** (at your VPS provider or with aaPanel Backup).
2. Try it on a **secondary site** first: create a new site in aaPanel (for example `test.example.com`), or clone the real site to a subdomain.
3. If the VPS is low on RAM, add 2 GB of swap:
   ```bash
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
   ```

**Choosing a version:** use the **same version** as the site currently running in aaPanel (if the site uses PHP 8.2, use `--php=8.2`) for a fair comparison and the lowest risk. Supported versions: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5. The project has fully tested the `full` build for **7.4 and 8.5**; the other versions use the same script but have not been tried.

## 1. Download QHturbo and check the server

```bash
apt install -y git          # aaPanel usually has it already
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src
cd /opt/qhturbo-src
sudo scripts/aapanel.sh check --php=8.5
```

`check` **changes nothing**. It prints:
- Whether aaPanel and nginx were found, plus RAM and disk space (with a warning if they are not enough to build).
- aaPanel's PHP versions and **which site uses which PHP** (use this name for the `enable` step).
- After installation: **which extensions aaPanel's PHP has that QHturbo does not have yet** (redis, imagick…).

## 2. Installation (10–40 minutes)

Run it inside `screen` so a dropped SSH connection does not stop the build:

```bash
screen -S qhturbo
sudo scripts/aapanel.sh install --php=8.5
# Ctrl+A then D to detach; reattach with: screen -r qhturbo
```

The script will:
1. Install the packages needed for the build (apt/dnf).
2. Download the exact official PHP tag and verify the commit. For 7.4/8.0 it also builds a separate OpenSSL 1.1.1.
3. Build PHP + PHP-FPM with commonly used extensions, then install into `/opt/qhturbo/8.5`.
4. **Copy settings from aaPanel's php.ini** (`disable_functions`, `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `date.timezone`…), so the site has the same limits and the same security level.
5. Run a separate PHP-FPM: service `qhturbo-php85-fpm`, socket `/run/qhturbo/php85-fpm.sock`, user `www` (same as aaPanel).
6. Create the file `/www/server/nginx/conf/enable-php-qhturbo85.conf`, copied from aaPanel's `enable-php-85.conf` with only the socket changed.
7. Run `qht-doctor` to check the configuration.

After this step **no site has changed yet**.

## 3. Adding missing extensions

```bash
sudo scripts/aapanel.sh check --php=8.5        # look at the "extensions in aaPanel PHP but not in QHturbo" line
sudo scripts/install-ext.sh redis --php=8.5
sudo scripts/install-ext.sh imagick --php=8.5  # requires first: apt install -y libmagickwand-dev
```

`install-ext.sh` downloads from PECL; if PECL is unreachable it downloads from GitHub (latest stable release), then builds, enables and reloads FPM. Tried: redis 6.3.0 on 8.5, apcu 5.1.28 on 7.4.

## 4. Switching a site to QHturbo

```bash
sudo scripts/aapanel.sh enable test.example.com --php=8.5
```

What the script does:
1. Records the site's HTTP status code **before** switching.
2. Backs up the vhost (`/www/server/panel/vhost/nginx/test.example.com.conf.qhturbo-bak`).
3. Changes exactly **one line**, `include enable-php-85.conf;`, to `include enable-php-qhturbo85.conf;`.
4. `nginx -t`: on error, restores immediately.
5. Reloads nginx and checks the HTTP status code again. **If the site returns 5xx (and did not before), it automatically switches back to aaPanel's PHP.**

If the site uses HTTPS or does not respond at `http://127.0.0.1/`, give the URL to check: `--url=https://test.example.com/`.

**Checking which PHP a site is running:** create a temporary file and delete it right after looking:

```bash
echo '<?php echo PHP_VERSION, " ", PHP_SAPI, " ", php_ini_loaded_file();' > /www/wwwroot/test.example.com/qht-check.php
curl -s https://test.example.com/qht-check.php      # 8.5.12 fpm-fcgi /opt/qhturbo/8.5/etc/php.ini
rm /www/wwwroot/test.example.com/qht-check.php
```

Then check the main features yourself: login, admin pages, uploads, form submission, payments (test environment), cron.

**Monitoring:** `tail -f /www/wwwlogs/test.example.com.error.log /var/log/qhturbo/php85-fpm.log /var/log/qhturbo/php85-slow.log`

Overall status: `sudo scripts/aapanel.sh status`

## 5. Comparing speed

Measure **the same page**, before and after switching:

```bash
# Time to first byte (TTFB), 20 times
for i in $(seq 20); do curl -s -o /dev/null -w '%{time_starttransfer}\n' https://test.example.com/; done | sort -n | awk '{a[NR]=$1} END {print "median", a[int(NR/2)+1], "s"}'
# Concurrent load (apt install apache2-utils)
ab -n 500 -c 20 https://test.example.com/
```

**Realistic expectations:** Compatible mode is standard PHP-FPM with a good OPcache configuration.
- Your aaPanel PHP **does not have OPcache enabled** or OPcache is too small: usually noticeably faster.
- Your aaPanel PHP already has a well-configured OPcache: speed is **about the same**.
- Upgrading the version (7.4 → 8.x): moderately faster, mainly because PHP code runs faster.
- A 7–8× speedup is only achievable in **Performance** mode (section 8), which is not meant for WordPress.

Measurements on real sites are very valuable, because that is the measurement the project still lacks (ISSUE-003). Please send your results back so `logs/benchmarks.md` can be updated.

## 6. Going back

```bash
sudo scripts/aapanel.sh disable test.example.com     # 1 site back to aaPanel PHP (immediately)
sudo scripts/aapanel.sh uninstall --php=8.5          # all sites back to aaPanel PHP, stop and remove the service
sudo rm -rf /opt/qhturbo/8.5 /opt/qhturbo-lab        # (optional) delete the files completely
```

If the script cannot run, recover by hand: `cp /www/server/panel/vhost/nginx/<site>.conf.qhturbo-bak /www/server/panel/vhost/nginx/<site>.conf && /www/server/nginx/sbin/nginx -s reload`.

## 7. Notes on using it alongside aaPanel

| Situation | What happens / how to handle it |
|---|---|
| Changing the site's "PHP version" in the aaPanel UI | aaPanel rewrites the `include` line, so the site **goes back to aaPanel's PHP** (fails safe). To use QHturbo again, run `enable` again |
| Editing php.ini / upload limits in the aaPanel UI | Does not apply to QHturbo. Run `sudo scripts/aapanel.sh sync-ini --php=8.5`, or edit `/opt/qhturbo/8.5/etc/conf.d/` |
| Installing extensions in the aaPanel UI | Only goes into aaPanel's PHP. For QHturbo use `scripts/install-ext.sh` |
| `open_basedir` (the site's `.user.ini` file) | Still in effect, as on aaPanel's PHP |
| Sessions / login | Same `/tmp` directory, so users **are not logged out** when switching (observed in testing) |
| aaPanel cron jobs calling `/www/server/php/85/bin/php` | Still use aaPanel's PHP. To change, edit them to `/opt/qhturbo/8.5/bin/php` |
| `disable_functions` | Copied from aaPanel. Performance mode removes only the `pcntl_*` functions (needed by workers) |
| Number of FPM processes | Default 5 × the number of CPUs. Compute from actual RAM: `python3 /opt/qhturbo/8.5/share/qhturbo/fpm-tune.py` |
| PHP 7.4 / 8.0 | Out of security support, and so is OpenSSL 1.1.1. Use only temporarily while upgrading |

## 8. (Advanced) Performance mode on aaPanel

Only for applications written to follow the [state rules](modes/performance.md) (not for WordPress):

1. `sudo cp /opt/qhturbo/8.5/share/qhturbo/worker.env.example /etc/qhturbo/worker-myapp.env`, then edit `QHT_APP`, `QHT_PORT`.
2. `sudo cp /opt/qhturbo/8.5/share/qhturbo/systemd/qhturbo-php85-worker@.service /etc/systemd/system/ && sudo systemctl daemon-reload && sudo systemctl enable --now qhturbo-php85-worker@myapp`
3. In aaPanel: **Website → site → Reverse proxy** pointing to `http://127.0.0.1:8080`.
4. Deploying new code: `sudo systemctl reload qhturbo-php85-worker@myapp` (replaces workers one by one, no dropped requests).

## What has been tested

`tests/aapanel-sim.sh` sets up a **simulated aaPanel**: the `/www/server/...` layout, the `www` user, aaPanel-style `enable-php-XX.conf` and vhosts, real nginx, and a PHP-FPM playing the role of aaPanel's PHP. Result **12/12**: check, sync-ini, enable (the site runs on QHturbo with aaPanel's exact `upload_max_filesize` and `disable_functions`), status, disable (vhost restored identical to the original), and automatic rollback when QHturbo returns 502.

**Not yet tried on a real aaPanel.** If you hit an error, please send back the output of `sudo scripts/aapanel.sh check` and the relevant error log excerpt.
