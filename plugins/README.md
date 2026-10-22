# Control-panel plugins

**English** · [Tiếng Việt](README.vi.md)

The plugins are a user interface over the QHturbo scripts: every action they offer runs the same tested command-line tools (`scripts/aapanel.sh`, `scripts/sync-ext.sh`, `scripts/install-ext.sh`).

| Panel | Status |
|---|---|
| **aaPanel** (nginx or Apache) | Ready to try. Tested on simulated aaPanel servers: the plugin backend switches a site to QHturbo and back, rejects malformed input, and runs long tasks in the background. Not yet tested inside a real aaPanel. |
| DirectAdmin | Planned: needs one PHP-FPM pool per hosting account and DirectAdmin's own per-domain configuration. |
| cPanel / WHM | Planned: a WHM plugin with per-account pools, alongside EasyApache's PHP. |

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

After changing these files run `plugins/build.sh` and commit the new `qhturbo-aapanel.zip` (CI checks that it matches). Tests: `tests/aapanel-sim-apache.sh` drives the backend against a simulated aaPanel.
