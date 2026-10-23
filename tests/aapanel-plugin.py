"""Drive the aaPanel plugin backend (plugins/aapanel/qhturbo/qhturbo_main.py)
the way the panel does, against a running aaPanel simulation.
Called by tests/aapanel-sim-apache.sh with AAPANEL_ROOT, QHT_SRC,
QHT_JOB_DIR and QHT_URL set."""
import os
import sys
import time

sys.path.insert(0, sys.argv[1])
import qhturbo_main  # noqa: E402

# aaPanel's panel process runs with a minimal PATH (only its own Python):
# the plugin and the scripts must still find bash, git, apt-get/dnf, curl...
os.environ['PATH'] = '/www/server/panel/pyenv/bin'


P = qhturbo_main.qhturbo_main()
fails = []


class Get:  # aaPanel passes request fields as attributes of one object
    def __init__(self, **kw):
        self.__dict__.update(kw)


def check(cond, what):
    print('  %-5s plugin: %s' % ('ok' if cond else 'FAIL', what))
    if not cond:
        fails.append(what)


def wait_job():
    for _ in range(300):
        j = P.get_job(Get())['data']
        if not j['running']:
            return j
        time.sleep(0.5)
    return j


def site(name):
    for s in P.get_overview(Get())['data']['sites']:
        if s['site'] == name:
            return s
    return None


ov = P.get_overview(Get())
check(ov['status'] and ov['data']['ready'], 'overview loads')
v85 = [v for v in ov['data']['versions'] if v['branch'] == '8.5']
check(bool(v85) and v85[0]['installed'] and v85[0]['running'], 'PHP 8.5 listed as installed and running')
s = site('test.local')
check(s is not None and s['php'] == '8.4' and s['qhturbo'] == '' and s['web'] == 'apache', 'site listed with its aaPanel PHP: %r' % s)

check(not P.enable_site(Get(site='x;touch /tmp/qht-pwned', version='8.5'))['status'], 'rejects a malicious site name')
check(not P.enable_site(Get(site='test.local', version='9.9'))['status'], 'rejects an unknown PHP version')
check(not os.path.exists('/tmp/qht-pwned'), 'nothing was executed for the bad input')

r = P.enable_site(Get(site='test.local', version='8.5'))
check(r['status'], 'switch job starts')
check(not P.sync_ext(Get(version='8.5'))['status'], 'a second job is refused while one runs')
j = wait_job()
check(j['exit'] == 0 and 'now runs on QHturbo' in j['log'], 'switch job succeeds (exit %r)' % j['exit'])
s = site('test.local')
check(s['qhturbo'] == '8.5' and s['php'] == '8.4', 'site now shows QHturbo 8.5 (was aaPanel 8.4): %r' % s)

r = P.disable_site(Get(site='test.local', version='8.5'))
j = wait_job()
check(r['status'] and j['exit'] == 0, 'switch back succeeds')
check(site('test.local')['qhturbo'] == '', 'site back on aaPanel PHP')

c = P.get_check(Get(version='8.5'))
check(c['status'] and 'web server' in c['data']['output'], 'check output is returned')

sys.exit(1 if fails else 0)
