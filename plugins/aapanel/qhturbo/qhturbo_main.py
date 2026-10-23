#!/usr/bin/python
# coding: utf-8
"""QHturbo PHP plugin for aaPanel.

The panel calls these methods from index.html through
/plugin?action=a&name=qhturbo&s=<method>. Every change is made by
/opt/qhturbo-src/scripts/aapanel.sh (the same tested script used from the
command line). Long operations run as one background job whose log the
page shows.
"""
import os
import re
import signal
import subprocess

SRC = os.environ.get('QHT_SRC', '/opt/qhturbo-src')
SCRIPT = SRC + '/scripts/aapanel.sh'
JOB_DIR = os.environ.get('QHT_JOB_DIR', '/var/log/qhturbo')
JOB_LOG = JOB_DIR + '/plugin-job.log'
JOB_PID = JOB_DIR + '/plugin-job.pid'
JOB_CMD = JOB_DIR + '/plugin-job.cmd'
VERSIONS = ('7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5')
SITE_RE = re.compile(r'^[A-Za-z0-9][A-Za-z0-9._-]{0,252}$')
ANSI_RE = re.compile(r'\x1b\[[0-9;]*m')
# The panel process may have a minimal PATH; the scripts need the system tools.
SYS_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'


def _env(**extra):
    env = dict(os.environ)
    env['PATH'] = SYS_PATH + (':' + env['PATH'] if env.get('PATH') else '')
    env.update(extra)
    return env


def _ok(data=None, msg=''):
    return {'status': True, 'msg': msg, 'data': data}


def _err(msg):
    return {'status': False, 'msg': msg}


def _arg(get, name):
    return str(getattr(get, name, '') or '').strip()


def _read(path):
    try:
        with open(path) as f:
            return f.read()
    except (IOError, OSError):
        return ''


class qhturbo_main:
    # ------------------------------------------------------------ helpers --
    def _run(self, args, timeout=120):
        """Run aapanel.sh synchronously (quick commands only)."""
        p = subprocess.Popen(['/bin/bash', SCRIPT] + args, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, env=_env())
        try:
            out, _ = p.communicate(timeout=timeout)
        except subprocess.TimeoutExpired:
            p.kill()
            out, _ = p.communicate()
        return p.returncode, ANSI_RE.sub('', out.decode('utf-8', 'replace'))

    def _job_pid(self):
        """Process group of the running job, or 0."""
        try:
            pid, pgid = [int(x) for x in _read(JOB_PID).split()]
        except ValueError:
            return 0
        try:
            os.kill(pid, 0)
        except OSError:
            return 0
        # A finished child that nobody reaped yet is a zombie: not running.
        stat = _read('/proc/%d/stat' % pid)
        if stat and stat.rsplit(')', 1)[-1].split()[0] == 'Z':
            return 0
        return pgid

    def _start(self, args, title):
        if not os.path.exists(SCRIPT):
            return _err('QHturbo scripts not found in %s; reinstall the plugin' % SRC)
        if self._job_pid():
            return _err('Another task is still running: ' + _read(JOB_CMD).strip())
        if not os.path.isdir(JOB_DIR):
            os.makedirs(JOB_DIR)
        with open(JOB_LOG, 'w') as log:
            log.write('$ aapanel.sh %s\n' % ' '.join(args))
        # The job must outlive this request and must not stay a child of the
        # panel (it would become a zombie): a short-lived bash starts it in
        # the background, in its own session, and exits. "[exit N]" marks
        # the end in the log.
        wrapper = '( bash "$0" "$@"; echo "[exit $?]" ) >> "$QHT_JOB_LOG" 2>&1 < /dev/null & echo $!'
        env = _env(QHT_JOB_LOG=JOB_LOG)
        p = subprocess.Popen(['/bin/bash', '-c', wrapper, SCRIPT] + args, stdout=subprocess.PIPE,
                             stdin=open(os.devnull), cwd=SRC, close_fds=True, env=env, preexec_fn=os.setsid)
        out = p.communicate()[0].decode().strip()
        try:
            pid = int(out)
            pgid = os.getpgid(pid)
        except (ValueError, OSError):
            return _err('Could not start the task: ' + out)
        with open(JOB_PID, 'w') as f:
            f.write('%d %d' % (pid, pgid))
        with open(JOB_CMD, 'w') as f:
            f.write(title)
        return _ok({'pid': pid}, 'Started: ' + title)

    def _version(self, get):
        v = _arg(get, 'version')
        if v not in VERSIONS:
            raise ValueError('Unknown PHP version: %s' % v)
        return v

    def _site(self, get):
        s = _arg(get, 'site')
        if not SITE_RE.match(s):
            raise ValueError('Invalid site name: %s' % s)
        return s

    # -------------------------------------------------------------- reads --
    def get_overview(self, get):
        """Versions, sites and the current task, for the main page."""
        if not os.path.exists(SCRIPT):
            return _ok({'ready': False, 'src': SRC})
        versions, sites = [], []
        code, out = self._run(['versions'], 60)
        for line in out.splitlines():
            f = line.split('\t')
            if len(f) == 5:
                versions.append({'branch': f[0], 'installed': f[1] == '1', 'running': f[2] == '1',
                                 'version': '' if f[3] == '-' else f[3], 'aapanel': f[4] == '1'})
        code, out = self._run(['sites'], 60)
        for line in out.splitlines():
            f = line.split('\t')
            if len(f) == 4:
                orig = '' if f[2] == '-' else '%s.%s' % (f[2][0], f[2][1:])
                qht = '' if f[3] == '-' else '%s.%s' % (f[3][0], f[3][1:])
                sites.append({'site': f[0], 'web': f[1], 'php': orig, 'qhturbo': qht})
        # No "git -C" or "%cs": CentOS 7 has git 1.8.3.
        rev = subprocess.Popen(['git', 'log', '-1', '--format=%h %cd', '--date=short'], stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL, cwd=SRC, env=_env()).communicate()[0].decode().strip()
        return _ok({'ready': True, 'src': SRC, 'revision': rev, 'versions': versions, 'sites': sites,
                    'job': self._job_state()})

    def _job_state(self):
        log = ANSI_RE.sub('', _read(JOB_LOG))
        lines = log.splitlines()
        exit_code = None
        for line in reversed(lines[-3:]):
            m = re.match(r'^\[exit (\d+)\]$', line.strip())
            if m:
                exit_code = int(m.group(1))
                break
        return {'running': bool(self._job_pid()), 'title': _read(JOB_CMD).strip(), 'exit': exit_code,
                'log': '\n'.join(lines[-300:])}

    def get_job(self, get):
        return _ok(self._job_state())

    def get_check(self, get):
        """'aapanel.sh check': server, web server, sites, missing extensions."""
        try:
            v = self._version(get)
        except ValueError as e:
            return _err(str(e))
        code, out = self._run(['check', '--php=' + v], 180)
        return _ok({'exit': code, 'output': out})

    # ------------------------------------------------------------ actions --
    def install_php(self, get):
        try:
            v = self._version(get)
        except ValueError as e:
            return _err(str(e))
        return self._start(['install', '--php=' + v], 'Install QHturbo PHP %s (10-40 minutes)' % v)

    def sync_ext(self, get):
        try:
            v = self._version(get)
        except ValueError as e:
            return _err(str(e))
        return self._start(['sync-ext', '--php=' + v], 'Copy extensions into QHturbo PHP %s' % v)

    def enable_site(self, get):
        try:
            v, s = self._version(get), self._site(get)
        except ValueError as e:
            return _err(str(e))
        return self._start(['enable', s, '--php=' + v], 'Switch %s to QHturbo PHP %s' % (s, v))

    def disable_site(self, get):
        try:
            v, s = self._version(get), self._site(get)
        except ValueError as e:
            return _err(str(e))
        return self._start(['disable', s, '--php=' + v], "Switch %s back to aaPanel's PHP" % s)

    def uninstall_php(self, get):
        try:
            v = self._version(get)
        except ValueError as e:
            return _err(str(e))
        return self._start(['uninstall', '--php=' + v], 'Remove QHturbo PHP %s (sites go back first)' % v)

    def update_source(self, get):
        """git pull the QHturbo scripts."""
        if self._job_pid():
            return _err('Wait for the running task to finish')
        updater = SRC + '/scripts/update-src.sh'
        if os.path.exists(updater):
            cmd = ['/bin/bash', updater, SRC]
        else:  # copies older than update-src.sh
            cmd = ['git', 'pull', '--ff-only']
        p = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, cwd=SRC, env=_env())
        out = ANSI_RE.sub('', p.communicate()[0].decode('utf-8', 'replace'))
        return _ok({'exit': p.returncode, 'output': out}, 'Updated' if p.returncode == 0 else 'Update failed')

    def stop_job(self, get):
        pgid = self._job_pid()
        if not pgid:
            return _err('No task is running')
        os.killpg(pgid, signal.SIGTERM)
        return _ok(None, 'Task stopped')
