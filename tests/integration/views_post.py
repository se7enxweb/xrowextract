#!/usr/bin/env python3
"""POST robustness test of xrowextract: every POST variable a view reads (found in its source in
modules/xrowextract/*.php) is sent to that view, one at a time, with hostile values: an array where a
value is expected, a value where an array is expected, markup, a path, numbers out of range. A view may
refuse, ignore or report it, but must never answer with HTTP 500 or above or with PHP error text.
It SUBMITS forms, so it can create, change or delete things (schedules, destinations, presets, jobs):
run it against a TEST installation only.

  XROWEXTRACT_TEST_PASSWORD=... tests/integration/views_post.py <base URL> [admin path] [view,...]

Environment as for views.py (XROWEXTRACT_TEST_LOGIN, XROWEXTRACT_TEST_PASSWORD, XROWEXTRACT_TEST_LOGS). The form token of the
session (ezformtoken) is sent with every request. PASS/FAIL per view and variable; the last line is
the summary; exit code 1 when a request failed."""
import os
import re
import sys
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from xetest import LogWatch, login, php_error_in  # noqa: E402

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print('FAIL the Python package playwright is not installed (pip install playwright; playwright install chromium)')
    sys.exit(1)

if len(sys.argv) < 2:
    print(__doc__)
    sys.exit(2)
BASE = sys.argv[1].rstrip('/')
ADMIN = '/' + (sys.argv[2] if len(sys.argv) > 2 else '/admin').strip('/')
ONLY = set(sys.argv[3].split(',')) if len(sys.argv) > 3 else None
PASSWORD = os.environ.get('XROWEXTRACT_TEST_PASSWORD', '')
LOGIN = os.environ.get('XROWEXTRACT_TEST_LOGIN', 'admin')
if not PASSWORD:
    print('FAIL XROWEXTRACT_TEST_PASSWORD is not set')
    sys.exit(2)
MODULES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'modules', 'xrowextract')
LOGS = LogWatch()
# (label, how the value is sent): a scalar, or a list sent as name[]=...
VALUES = [
    ('array', ['x', '<b>y</b>']),
    ('nested array', {'a': ['b']}),
    ('markup', '<script>alert(1)</script>"\''),
    ('path', '../../../../etc/passwd'),
    ('huge number', '99999999999999999999999'),
    ('negative', '-1'),
    ('empty', ''),
]
views = {}
for name in sorted(os.listdir(MODULES)):
    if not name.endswith('.php') or name in ('module.php', 'function_definition.php'):
        continue
    view = name[:-4]
    if ONLY and view not in ONLY:
        continue
    source = open(os.path.join(MODULES, name), encoding='utf-8').read()
    names = sorted(set(re.findall(r"(?:hasPostVariable|postVariable)\(\s*'([A-Za-z0-9_]+)'", source)))
    if names:
        views[view] = names


def form_fields(name, value):
    """The multipart/form fields for one variable and value, as a list of (key, value)."""
    if isinstance(value, list):
        return [(name + '[]', v) for v in value]
    if isinstance(value, dict):
        return [('%s[%s][]' % (name, k), x) for k, vs in value.items() for x in vs]
    return [(name, value)]


fails = 0
count = 0
with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(ignore_https_errors=True, accept_downloads=False)
    page = context.new_page()
    if not login(page, BASE, ADMIN, LOGIN, PASSWORD):
        print('FAIL could not log in as %s' % LOGIN)
        sys.exit(1)
    page.goto(BASE + ADMIN + '/xrowextract/csv', wait_until='load')
    token = page.evaluate("() => { const t = document.querySelector('input[name=ezxform_token]'); return t ? t.value : ''; }")
    for view, names in views.items():
        url = BASE + ADMIN + '/xrowextract/' + view
        view_fails = 0
        statuses = {}
        for name in names:
            for label, value in VALUES:
                count += 1
                fields = form_fields(name, value)
                if token:
                    fields.append(('ezxform_token', token))
                body = '&'.join('%s=%s' % (quote(k, safe='[]'), quote(str(v), safe='')) for k, v in fields)
                try:
                    response = context.request.post(url, data=body, headers={'Content-Type': 'application/x-www-form-urlencoded'},
                                                    max_redirects=5, timeout=120000)
                    status = response.status
                    statuses[status] = statuses.get(status, 0) + 1
                    text = response.body()[:524288].decode('utf-8', 'replace')
                except Exception as e:
                    status = -1
                    text = str(e)
                problems = []
                if status >= 500 or status < 0:
                    problems.append('HTTP %d' % status)
                error = php_error_in(text)
                if error:
                    problems.append('PHP: ' + error)
                for entry in LOGS.new_entries():
                    problems.append('log: ' + entry)
                if problems:
                    fails += 1
                    view_fails += 1
                    print('FAIL %s %s=%s: %s' % (view, name, label, '; '.join(problems)))
        print('%s %-13s %d variable(s) x %d value(s), answered %s' % ('FAIL' if view_fails else 'PASS', view, len(names), len(VALUES),
              ', '.join('%d x HTTP %d' % (n, s) for s, n in sorted(statuses.items()))))
    browser.close()

print()
print(('FAIL %d of %d requests' % (fails, count)) if fails else ('PASS all %d requests' % count))
sys.exit(1 if fails else 0)
