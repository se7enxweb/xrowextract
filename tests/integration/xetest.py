"""Shared parts of the xrowextract browser tests (views.py, views_post.py)."""
import os
import re

# PHP error text in a page: shown errors, uncaught exceptions, the kernel's error page
PHP_ERRORS = re.compile(r'(<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)(</b>)?: |'
                        r'Uncaught [A-Z][A-Za-z\\]+|Stack trace:|An unexpected error has occurred|'
                        r'Undefined (variable|array key|index|offset)|Call to a member function')


def php_error_in(text):
    """The first PHP error text in a page, as a short snippet, or ''."""
    m = PHP_ERRORS.search(text)
    if not m:
        return ''
    snippet = re.sub(r'<[^>]+>', ' ', text[max(0, m.start() - 80):m.end() + 160])
    return ' '.join(snippet.split())[:220]


class LogWatch:
    """New entries about xrowextract in the installation's log files since the last look.

    XROWEXTRACT_TEST_LOGS names the log files, comma separated (e.g. <root>/var/log/error.log,
    <root>/var/log/warning.log); a PHP warning or notice the kernel logs instead of showing is found
    there. Only entries that mention xrowextract count: the kernel and other extensions log their own."""

    def __init__(self):
        self.files = [f for f in os.environ.get('XROWEXTRACT_TEST_LOGS', '').split(',') if f]
        self.offsets = {}
        for f in self.files:
            self.offsets[f] = os.path.getsize(f) if os.path.exists(f) else 0

    def new_entries(self):
        found = []
        for f in self.files:
            if not os.path.exists(f):
                continue
            size = os.path.getsize(f)
            start = self.offsets.get(f, 0)
            if size < start:
                start = 0  # rotated
            with open(f, 'rb') as fh:
                fh.seek(start)
                text = fh.read().decode('utf-8', 'replace')
            self.offsets[f] = size
            for entry in re.split(r'\n(?=\[ )', text):
                # The kernel logs every 404 with its address: that is an answer, not an error
                if 'xrowextract' in entry and 'Error ocurred using URI' not in entry:
                    found.append(os.path.basename(f) + ': ' + ' '.join(entry.split())[:240])
        return found


def login(page, base, admin, login_name, password):
    page.goto(base + admin + '/user/login')
    page.fill('input[name="Login"]', login_name)
    page.fill('input[name="Password"]', password)
    page.click('input[name="LoginButton"]')
    page.wait_for_load_state('networkidle')
    return not page.locator('input[name="Password"]').count()
