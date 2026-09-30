#!/usr/bin/env python3
"""View smoke test of xrowextract: every admin view opened by GET in a real browser (Playwright,
Chromium), logged in, with valid and invalid parameters. Nothing is submitted, so it changes nothing
but the session. A view fails on an HTTP status of 500 or above, PHP error text in the page (fatal
error, warning, notice, deprecation, an uncaught exception, the kernel's "unexpected error"), or a
JavaScript error on the page. A bad parameter must be answered with a message or a 404, never more.

  XROWEXTRACT_TEST_PASSWORD=... tests/integration/views.py <base URL> [admin path]

  base URL    the installation, e.g. http://127.0.0.1:18191 (Apache, php -S or Velocity alike)
  admin path  the admin siteaccess prefix (default /admin)
Environment: XROWEXTRACT_TEST_PASSWORD (required, never printed), XROWEXTRACT_TEST_LOGIN (default
admin), XROWEXTRACT_TEST_PACKAGE (a package of the repository for the per-package views; default: the
first one the package list shows), XROWEXTRACT_TEST_SHOTS (a directory for a screenshot of every failing view).
PASS/FAIL per view; the last line is the summary; exit code 1 when a view failed."""
import os
import re
import sys

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
PASSWORD = os.environ.get('XROWEXTRACT_TEST_PASSWORD', '')
LOGIN = os.environ.get('XROWEXTRACT_TEST_LOGIN', 'admin')
SHOTS = os.environ.get('XROWEXTRACT_TEST_SHOTS', '')
if not PASSWORD:
    print('FAIL XROWEXTRACT_TEST_PASSWORD is not set')
    sys.exit(2)

PHP_ERRORS = re.compile(r'(<b>)?(Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)(</b>)?: |'
                        r'Uncaught [A-Z][A-Za-z\\]+|Stack trace:|An unexpected error has occurred|'
                        r'Undefined (variable|array key|index|offset)|Call to a member function')
HEX32 = '0123456789abcdef' * 2
fails = 0
count = 0


def check(page, path, label, allow_404=False, js_errors=None):
    """Opens one path; returns the response body text (or '')."""
    global fails, count
    count += 1
    del js_errors[:]
    problems = []
    body = ''
    try:
        response = page.goto(BASE + ADMIN + path, wait_until='load', timeout=120000)
        status = response.status if response else 0
        page.wait_for_timeout(300)  # scripts that run on load
        body = page.content()
    except Exception as e:
        if 'Download is starting' in str(e):
            # A view that answers with a file: fetched again with the session, the file itself checked
            response = page.request.get(BASE + ADMIN + path, timeout=120000)
            status = response.status
            body = response.body()[:262144].decode('utf-8', 'replace')
        else:
            status = -1
            problems.append('navigation: ' + str(e).splitlines()[0][:160])
    if status >= 500:
        problems.append('HTTP %d' % status)
    if status == 404 and not allow_404:
        problems.append('HTTP 404')
    m = PHP_ERRORS.search(body)
    if m:
        text = re.sub(r'<[^>]+>', ' ', body[max(0, m.start() - 80):m.end() + 160])
        problems.append('PHP: ' + ' '.join(text.split())[:220])
    if js_errors:
        problems.append('JS: ' + '; '.join(e[:160] for e in js_errors[:2]))
    if problems:
        fails += 1
        if SHOTS:
            os.makedirs(SHOTS, exist_ok=True)
            shot = os.path.join(SHOTS, 'view_%02d.png' % count)
            try:
                page.screenshot(path=shot, full_page=True)
                problems.append('screenshot ' + shot)
            except Exception:
                pass
    print('%s %-58s %s' % ('FAIL' if problems else 'PASS', label, '; '.join(problems) or 'HTTP %d' % status))
    return body


with sync_playwright() as p:
    browser = p.chromium.launch()
    context = browser.new_context(ignore_https_errors=True, accept_downloads=False)
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.goto(BASE + ADMIN + '/user/login')
    page.fill('input[name="Login"]', LOGIN)
    page.fill('input[name="Password"]', PASSWORD)
    page.click('input[name="LoginButton"]')
    page.wait_for_load_state('networkidle')
    if page.locator('input[name="Password"]').count():
        print('FAIL could not log in as %s' % LOGIN)
        sys.exit(1)

    # The views as a person opens them from the tabs
    for view in ('csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations', 'history'):
        body = check(page, '/xrowextract/' + view, view, js_errors=errors)
        if view == 'package':
            package_page = body

    # A package of the repository (from the kernel's package list) for the per-package views
    names = re.findall(r'/xrowextract/(?:browse|compare)/([A-Za-z0-9_]+)', package_page)
    if not names:
        page.goto(BASE + ADMIN + '/package/list', wait_until='load')
        names = re.findall(r'/package/view/full/([A-Za-z0-9_]+)', page.content())
    package = os.environ.get('XROWEXTRACT_TEST_PACKAGE', '') or (names[0] if names else '')
    if package:
        check(page, '/xrowextract/package/' + package, 'package/<package>', js_errors=errors)
        check(page, '/xrowextract/browse/' + package, 'browse/<package>', js_errors=errors)
        check(page, '/xrowextract/browse/' + package + '/0/0', 'browse/<package>/0/0', js_errors=errors)
        check(page, '/xrowextract/browse/' + package + '/999999/999999', 'browse/<package> past the end', allow_404=True, js_errors=errors)
        check(page, '/xrowextract/browse/' + package + '/abc/-1', 'browse/<package> with offsets that are not numbers', allow_404=True, js_errors=errors)
        check(page, '/xrowextract/browse_file/' + package + '/0', 'browse_file/<package>/0', allow_404=True, js_errors=errors)
        check(page, '/xrowextract/browse_file/' + package + '/999999', 'browse_file/<package> past the end', allow_404=True, js_errors=errors)
        check(page, '/xrowextract/compare/' + package, 'compare/<package> with this site', js_errors=errors)
        check(page, '/xrowextract/compare/' + package + '/' + package, 'compare/<package>/<package>', js_errors=errors)
        check(page, '/xrowextract/compare/' + package + '/no_such_package_xyz', 'compare/<package>/<unknown>', allow_404=True, js_errors=errors)
    else:
        print('INFO no package in the repository: the per-package views are only checked with unknown names')

    # Unknown, malformed and hostile parameters: a message or a 404, nothing worse
    bad = [
        ('/xrowextract/package/no_such_package_xyz', 'package/<unknown>'),
        ('/xrowextract/package/%2e%2e%2f%2e%2e%2fetc', 'package/<path>'),
        ('/xrowextract/browse/no_such_package_xyz', 'browse/<unknown>'),
        ('/xrowextract/browse/%3Cscript%3E', 'browse/<markup>'),
        ('/xrowextract/browse_file/no_such_package_xyz/0', 'browse_file/<unknown>/0'),
        ('/xrowextract/compare/no_such_package_xyz', 'compare/<unknown>'),
        ('/xrowextract/job_status/zzz', 'job_status/<not an id>'),
        ('/xrowextract/job_status/' + HEX32, 'job_status/<no such job>'),
        ('/xrowextract/job_download/zzz/log', 'job_download/<not an id>/log'),
        ('/xrowextract/job_download/' + HEX32 + '/file', 'job_download/<no such job>/file'),
        ('/xrowextract/job_download/' + HEX32 + '/%2e%2e', 'job_download/<no such job>/<path>'),
        ('/xrowextract/schedules/999999999', 'schedules/<no such schedule>'),
        ('/xrowextract/schedules/abc', 'schedules/<not an id>'),
        ('/xrowextract/schedules/-1', 'schedules/-1'),
        ('/xrowextract/destinations/999999999', 'destinations/<no such destination>'),
        ('/xrowextract/destinations/abc', 'destinations/<not an id>'),
        ('/xrowextract/history?state=%3Cb%3E&kind=x&schedule=abc&offset=-5&from=2026-99-99&to=never&text=%3Cscript%3E', 'history with bad filters'),
        ('/xrowextract/history?offset=99999999', 'history past the end'),
        ('/xrowextract/jobs?offset=abc', 'jobs with a bad offset'),
        ('/xrowextract/csv/(Class_id)/abc', 'csv with a bad class parameter'),
        ('/xrowextract/csv?Class_id=%27%3B--&Subtree=abc&Offset=-1&Limit=abc', 'csv with bad query values'),
        ('/xrowextract/archive?Nodes=abc', 'archive with a bad node list'),
        ('/xrowextract/import?Package=no_such_package_xyz', 'import with an unknown package'),
        ('/xrowextract/upload_chunk', 'upload_chunk by GET'),
        ('/xrowextract/no_such_view', 'a view that does not exist'),
    ]
    for path, label in bad:
        check(page, path, label, allow_404=True, js_errors=errors)
    browser.close()

print()
print(('FAIL %d of %d views' % (fails, count)) if fails else ('PASS all %d views' % count))
sys.exit(1 if fails else 0)
