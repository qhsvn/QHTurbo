# Control-panel plugins

**English** · [Tiếng Việt](README.vi.md)

The plugins are a user interface over the QHturbo scripts: every action they offer runs the same tested command-line tools (`scripts/aapanel.sh`, `scripts/directadmin.sh`, `scripts/sync-ext.sh`, `scripts/install-ext.sh`).

| Panel | Status |
|---|---|
| **aaPanel** (nginx or Apache) | Ready to try. Tested on simulated aaPanel servers: the plugin backend switches a site to QHturbo and back, rejects malformed input, and runs long tasks in the background. Not yet tested inside a real aaPanel. |
| **DirectAdmin** (Apache or nginx_apache, PHP-FPM) | Ready to try. Tested on a simulated DirectAdmin with a real Apache (32 checks: per-user pools, custom httpd code kept, rollback, the plugin page under PHP 7.4, the root agent refusing non-admin and forged requests). Not yet tested inside a real DirectAdmin. Plain nginx and (Open)LiteSpeed are not supported yet. |
| cPanel / WHM | Planned: a WHM plugin with per-account pools, alongside EasyApache's PHP. |

## DirectAdmin plugin

What it does, from DirectAdmin's admin level:
- **PHP versions:** build and install QHturbo PHP 7.4–8.5 beside DirectAdmin's PHP, copy the extensions of DirectAdmin's PHP of the same version, check, rebuild, remove.
- **Domains:** every domain with its user and the PHP it runs on; switch it to a QHturbo version, or back. The domain's user gets a PHP-FPM pool in QHturbo that **runs as that user**, copied from DirectAdmin's own pool for the user (same `open_basedir`, limits, mail settings). The switch is a marked block in the domain's *Custom HTTPD Configuration*; anything you wrote there yourself is kept. A domain that answers with an error after the switch is switched back automatically.
- **Task:** builds and switches run in the background; the page follows them.

How it works: DirectAdmin runs plugin pages as the logged-in admin's Linux user, never as root. The page therefore only writes a request file into `/var/spool/qhturbo`; the QHturbo agent (`scripts/panel-agent.sh`, started by a systemd path unit as root) runs requests written by DirectAdmin admins (listed in `admin.list`), checks every value, and runs `scripts/directadmin.sh`. Logs and lists are published in `/var/log/qhturbo/jobs` and `/var/lib/qhturbo`.

### Install

Snapshot the server first.

1. In DirectAdmin (admin level) open **Plugin Manager**, add a plugin from the URL `https://github.com/qhsvn/QHTurbo/raw/main/plugins/directadmin/qhturbo.tar.gz` (or upload the file). Keep the file name `qhturbo.tar.gz`: DirectAdmin names the plugin directory after it.
2. The plugin's install script downloads the QHturbo scripts to `/opt/qhturbo-src` and sets up the agent when DirectAdmin runs it as root. If the plugin page says the agent is not set up, run once as root:
   ```bash
   git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src
   /opt/qhturbo-src/scripts/panel-agent.sh setup directadmin
   ```
3. Open **QHturbo PHP** in the admin menu, install the PHP version your domains use, then switch one test domain.

The same works without the plugin, from SSH:

```bash
cd /opt/qhturbo-src
sudo scripts/directadmin.sh check   --php=7.4
sudo scripts/directadmin.sh install --php=7.4          # 10-40 minutes
sudo scripts/directadmin.sh enable  example.com --php=7.4
sudo scripts/directadmin.sh disable example.com
```

### Uninstall

Switch the domains back first (or `scripts/directadmin.sh uninstall --php=<version>`), then delete the plugin in Plugin Manager; its uninstall script removes the agent.

## aaPanel plugin

What it does, from the aaPanel UI:
- **PHP versions:** build and install QHturbo PHP 7.4–8.5 beside aaPanel's PHP, copy the extensions of aaPanel's PHP of the same version, check, rebuild, remove.
- **Sites:** each site with the PHP it runs on; switch it to a QHturbo version, or back to aaPanel's PHP. A site that returns errors after the switch is switched back automatically, and the reason is shown.
- **Task log:** builds and switches run in the background; the log updates live.

### Install

Either way, snapshot the server first.

**From the panel:** download [`qhturbo-aapanel.zip`](https://github.com/qhsvn/QHTurbo/raw/main/plugins/aapanel/qhturbo-aapanel.zip), then in aaPanel open **App Store**, use its **Import** (third-party plugin) button and upload the zip. aaPanel runs the plugin's `install.sh`, which downloads the QHturbo scripts to `/opt/qhturbo-src`.

**From the command line** (same result):

```bash
git clone https://github.com/qhsvn/QHTurbo.git /opt/qhturbo-src 2>/dev/null || git -C /opt/qhturbo-src pull
cp -r /opt/qhturbo-src/plugins/aapanel/qhturbo /www/server/panel/plugin/
cd /www/server/panel/plugin/qhturbo && bash install.sh install
cp icon.png /www/server/panel/BTPanel/static/img/soft_ico/ico-qhturbo.png
```

Then find **QHturbo PHP** in App Store → Installed.

### Uninstall

Switch every site back first (Sites tab), then remove the plugin in App Store. Removing the plugin leaves QHturbo PHP and its sites as they are; `/opt/qhturbo-src/scripts/aapanel.sh uninstall --php=<version>` removes a QHturbo PHP.

### Files

| File | Role |
|---|---|
| `info.json` | Name, title, version shown by aaPanel |
| `install.sh` | Run by aaPanel with `install` / `uninstall` |
| `qhturbo_main.py` | Backend; the page calls its methods through `/plugin?action=a&name=qhturbo&s=<method>` |
| `index.html` | The page (English and Vietnamese) |
| `icon.png` | App Store icon |

After changing the plugin files run `plugins/build.sh` and commit the new `qhturbo-aapanel.zip` / `qhturbo.tar.gz` (CI checks that they match). Tests: `tests/aapanel-sim-apache.sh` (aaPanel backend) and `tests/directadmin-sim.sh` (DirectAdmin script, plugin page and agent).
