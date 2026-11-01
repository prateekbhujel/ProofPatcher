"""Exercise the dashboard with a real PTY and wait for command output."""
import fcntl
import os
from pathlib import Path
import pty
import select
import shutil
import struct
import subprocess
import termios
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TerminalDashboardPtyTest(unittest.TestCase):
    def test_status_output_stays_visible_until_enter(self):
        php = shutil.which('php')
        if not php or os.name != 'posix':
            self.skipTest('PHP and a POSIX PTY are required')
        extension = subprocess.run([php, '-r', "exit(extension_loaded('terminal') ? 0 : 1);"], check=False)
        if extension.returncode:
            self.skipTest('terminal extension unavailable')
        root = Path(os.environ.get('PROOFPATCH_TEST_APP_ROOT', str(ROOT)))
        cmd = [php]
        prepend = os.environ.get('PROOFPATCH_TUI_PREPEND')
        if prepend:
            cmd.extend(['-d', 'auto_prepend_file=' + prepend])
        cmd.extend(['artisan', 'kernel'])
        master, slave = pty.openpty()
        fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 100, 0, 0))
        env = os.environ.copy()
        env.pop('NO_COLOR', None)
        env['TERM'] = 'xterm-256color'
        proc = subprocess.Popen(cmd, cwd=root, env=env, stdin=slave, stdout=slave, stderr=slave)
        os.close(slave)
        seen = bytearray()

        def read_until(marker, timeout=15):
            deadline = time.monotonic() + timeout
            while marker not in seen:
                if time.monotonic() > deadline or proc.poll() is not None:
                    self.fail('PTY did not reach expected screen; exit=%s' % proc.poll())
                readable, _, _ = select.select([master], [], [], 0.1)
                if readable:
                    try:
                        seen.extend(os.read(master, 65536))
                    except OSError:
                        self.fail('PTY closed before expected screen')

        try:
            read_until(b'\x1b[?1049h')
            os.write(master, b'1')
            read_until(b'Press Enter to return:')
            self.assertEqual(1, seen.count(b'\x1b[?1049h'), 'Dashboard hid the output before Enter')
            time.sleep(0.3)
            if select.select([master], [], [], 0)[0]:
                seen.extend(os.read(master, 65536))
            self.assertEqual(1, seen.count(b'\x1b[?1049h'), 'Dashboard returned without confirmation')
            seen.clear()
            os.write(master, b'\n')
            read_until(b'\x1b[?1049h')
            os.write(master, b'q')
            self.assertEqual(0, proc.wait(timeout=10))
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            os.close(master)


if __name__ == '__main__':
    unittest.main()
