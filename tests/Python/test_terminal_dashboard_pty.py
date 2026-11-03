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
    def test_no_color_offers_plain_menu_and_returns_from_action(self):
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
        env = os.environ.copy()
        env['NO_COLOR'] = '1'
        env['TERM'] = 'xterm-256color'
        proc = subprocess.Popen(cmd, cwd=root, env=env, stdin=slave, stdout=slave, stderr=slave)
        os.close(slave)
        seen = bytearray()
        all_output = bytearray()

        def read_until(marker, timeout=15):
            deadline = time.monotonic() + timeout
            while marker not in seen:
                if time.monotonic() > deadline or proc.poll() is not None:
                    self.fail('Plain PTY did not reach expected screen; exit=%s' % proc.poll())
                readable, _, _ = select.select([master], [], [], 0.1)
                if readable:
                    try:
                        chunk = os.read(master, 65536)
                        seen.extend(chunk)
                        all_output.extend(chunk)
                    except OSError:
                        self.fail('Plain PTY closed before expected screen')

        try:
            read_until(b'Choose an action (number or q):')
            self.assertIn(b'Listen for review requests', seen)
            seen.clear()
            os.write(master, b'6\n')
            read_until(b'Press Enter to return:')
            seen.clear()
            os.write(master, b'\n')
            read_until(b'Choose an action (number or q):')
            seen.clear()
            os.write(master, b'10\n')
            read_until(b'Local Git repository path:')
            seen.clear()
            os.write(master, os.fsencode(root) + b'\n')
            read_until(b'Another repository path (Enter to search):')
            seen.clear()
            os.write(master, b'\n')
            read_until(b'Find text:')
            seen.clear()
            os.write(master, b'class TerminalDashboard\n')
            read_until(b'Press Enter to return:')
            self.assertIn(b'app/Support/TerminalDashboard.php:', seen)
            seen.clear()
            os.write(master, b'\n')
            read_until(b'Choose an action (number or q):')
            os.write(master, b'q\n')
            self.assertEqual(0, proc.wait(timeout=10))
            self.assertNotIn(b'\x1b', all_output)
            self.assertNotIn(b'|o.o|', all_output)
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            os.close(master)

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
            read_until(b'|o.o|')
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
            read_until('↑↓ move'.encode())
            os.write(master, b'q')
            self.assertEqual(0, proc.wait(timeout=10))
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
            os.close(master)


if __name__ == '__main__':
    unittest.main()
