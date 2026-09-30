#!/usr/bin/env bash
# The release gate of xrowextract: every check a release has to pass, one PASS/FAIL line per part, exit
# code 1 when any part fails (2 for a usage error). Run it from anywhere; it works on the clone it is in.
#
#   bin/check.sh [--php=/path/to/php] [--only=lint,ts,dup,phpstan,unit,cli,views]
#
#   lint     php -l on every PHP file of the extension with that PHP binary, with every error level on,
#            so a compile-time deprecation (e.g. an implicitly nullable parameter) fails as well
#   ts       xmllint --noout on every translations/*/translation.ts
#   dup      no <source> twice in the same <context> of a .ts file
#   phpstan  PHPStan with phpstan.neon.dist (level and baseline there)
#   unit     the PHPUnit tests in tests/unit (no database needed)
#   cli      tests/integration/cli.sh: every command against a test installation (only with
#            XROWEXTRACT_TEST_ROOT set; it writes to that installation's content and database)
#   views    tests/integration/views.py: every admin view by GET in a browser (only with
#            XROWEXTRACT_TEST_URL and XROWEXTRACT_TEST_PASSWORD set)
# Without --only, every part runs whose requirements are there; cli and views are reported as SKIP
# when their variables are not set.
#
# Environment:
#   PHP               the PHP binary (default: php in PATH); --php overrides it
#   EXPONENTIAL_ROOT  the Exponential root PHPStan and the unit tests read the kernel classes from
#                     (default: the installation this extension is installed in, two levels up)
#   EXPONENTIAL_VENDOR_DIR  a Composer vendor directory with the Zeta Components, for a root without vendor/
#   PHPSTAN, PHPUNIT  a phpstan / phpunit binary or phar to use (default: the pinned releases below,
#                     downloaded once into var/tools/ and checked against their SHA-256)
#   XROWEXTRACT_TEST_ROOT, XROWEXTRACT_TEST_USER (default the owner of its var/), XROWEXTRACT_TEST_URL,
#   XROWEXTRACT_TEST_PASSWORD (the admin password of the test installation, never printed)
set -u
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
PHP_BIN=${PHP:-php}
ONLY=""
PHPSTAN_VERSION=2.2.16
PHPSTAN_URL=https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar
PHPSTAN_SHA256=1a2fb5460c142502d3cd06272529c18b19fda004ada974bd2581cb9fd6c0a53b
PHPUNIT_VERSION=10.5.65
PHPUNIT_URL=https://phar.phpunit.de/phpunit-$PHPUNIT_VERSION.phar
PHPUNIT_SHA256=ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835
for arg in "$@"; do
  case "$arg" in
    --php=*) PHP_BIN=${arg#--php=} ;;
    --only=*) ONLY=${arg#--only=} ;;
    -h|--help) sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "FAIL unknown option $arg (see --help)"; exit 2 ;;
  esac
done
want() { [ -z "$ONLY" ] && return 0; case ",$ONLY," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }
command -v "$PHP_BIN" > /dev/null 2>&1 || { echo "FAIL no PHP binary '$PHP_BIN'"; exit 2; }
PHP_BIN=$(command -v "$PHP_BIN")
cd "$HERE" || exit 2
failed=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; failed=$((failed + 1)); }
skip() { echo "SKIP $*"; }
phpfiles() { find . -name '*.php' -not -path './.git/*' -not -path './var/*' -not -path './vendor/*' | sort; }
ROOT=${EXPONENTIAL_ROOT:-}
[ -z "$ROOT" ] && [ -f "$HERE/../../autoload/ezp_kernel.php" ] && ROOT=$(cd "$HERE/../.." && pwd -P)
[ -n "$ROOT" ] && [ ! -f "$ROOT/autoload/ezp_kernel.php" ] && ROOT=""

# tool <given path> <name> <version> <url> <sha256>: prints the phar to use ('' when there is none)
tool() {
  local given=$1 name=$2 version=$3 url=$4 sha=$5 path
  [ -n "$given" ] && { echo "$given"; return; }
  path=$HERE/var/tools/$name-$version.phar
  if [ ! -f "$path" ]; then
    mkdir -p "$HERE/var/tools"
    curl -fsSL -o "$path.part" "$url" && mv -f "$path.part" "$path" || { rm -f "$path.part"; return; }
  fi
  [ "$(sha256sum "$path" | cut -d' ' -f1)" = "$sha" ] && echo "$path"
}
runner() { case "$1" in *.phar) echo "$PHP_BIN -d memory_limit=2G $1" ;; *) echo "$1" ;; esac; }

if want lint; then
  version=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null)
  count=0; bad=0
  while IFS= read -r f; do
    count=$((count + 1))
    out=$("$PHP_BIN" -d error_reporting=-1 -d display_errors=stderr -d display_startup_errors=0 -l "$f" 2>&1)
    if [ $? -ne 0 ] || [ -n "$(printf '%s\n' "$out" | grep -v '^No syntax errors detected')" ]; then
      bad=$((bad + 1)); printf '%s\n' "$out" | grep -v '^No syntax errors detected' | sed 's/^/  /' | head -5
    fi
  done < <(phpfiles)
  [ "$bad" = 0 ] && pass "lint: $count PHP files, PHP $version" || fail "lint: $bad of $count PHP files (PHP $version)"
fi

if want ts; then
  if command -v xmllint > /dev/null 2>&1; then
    out=$(xmllint --noout translations/*/translation.ts 2>&1)
    [ -z "$out" ] && pass "ts: $(ls translations/*/translation.ts | wc -l) translation files are well-formed XML" \
                  || { printf '%s\n' "$out" | head -10 | sed 's/^/  /'; fail "ts: xmllint"; }
  else
    fail "ts: xmllint is not installed (libxml2-utils)"
  fi
fi

if want dup; then
  out=$("$PHP_BIN" tests/tools/check_ts_duplicate_sources.php translations/*/translation.ts 2>&1)
  [ $? = 0 ] && pass "dup: ${out#PASS }" || { printf '%s\n' "$out" | grep '^FAIL' | head -20 | sed 's/^/  /'; fail "dup: duplicate <source> in a context"; }
fi

if want phpstan; then
  stan=$(tool "${PHPSTAN:-}" phpstan "$PHPSTAN_VERSION" "$PHPSTAN_URL" "$PHPSTAN_SHA256")
  level=$(sed -n 's/^    level: *\([0-9]*\)$/\1/p' phpstan.neon.dist)
  if [ -z "$ROOT" ]; then
    fail "phpstan: no Exponential root (set EXPONENTIAL_ROOT, e.g. to a checkout of se7enxweb/exponential)"
  elif [ -z "$stan" ]; then
    fail "phpstan: no phpstan $PHPSTAN_VERSION (set PHPSTAN, or allow its download and SHA-256 check)"
  else
    out=$(EXPONENTIAL_ROOT=$ROOT $(runner "$stan") analyse --no-progress --memory-limit=2G --error-format=raw 2>&1)
    if [ $? = 0 ]; then
      pass "phpstan: level $level, no errors (kernel from $ROOT)"
    else
      printf '%s\n' "$out" | grep -E '\.php:[0-9]+:|\[ERROR\]|rror' | head -40 | sed "s#$HERE/##; s/^/  /"
      fail "phpstan: level $level"
    fi
  fi
fi

if want unit; then
  unit=$(tool "${PHPUNIT:-}" phpunit "$PHPUNIT_VERSION" "$PHPUNIT_URL" "$PHPUNIT_SHA256")
  if [ -z "$ROOT" ]; then
    fail "unit: no Exponential root (set EXPONENTIAL_ROOT)"
  elif [ -z "$unit" ]; then
    fail "unit: no phpunit $PHPUNIT_VERSION (set PHPUNIT, or allow its download and SHA-256 check)"
  else
    out=$(EXPONENTIAL_ROOT=$ROOT $(runner "$unit") --configuration phpunit.xml.dist 2>&1)
    if [ $? = 0 ]; then
      pass "unit: $(printf '%s\n' "$out" | grep -E '^OK' | tail -1)"
    else
      printf '%s\n' "$out" | tail -40 | sed 's/^/  /'
      fail "unit"
    fi
  fi
fi

if want cli; then
  if [ -z "${XROWEXTRACT_TEST_ROOT:-}" ]; then
    [ -n "$ONLY" ] && fail "cli: XROWEXTRACT_TEST_ROOT is not set" || skip "cli: XROWEXTRACT_TEST_ROOT is not set"
  else
    out=$(PHP=$PHP_BIN bash tests/integration/cli.sh "$XROWEXTRACT_TEST_ROOT" 2>&1)
    rc=$?
    summary=$(printf '%s\n' "$out" | tail -1)
    [ $rc = 0 ] && pass "cli: ${summary#PASS }" || { printf '%s\n' "$out" | grep -E '^FAIL' | head -30 | sed 's/^/  /'; fail "cli: ${summary#FAIL }"; }
  fi
fi

if want views; then
  if [ -z "${XROWEXTRACT_TEST_URL:-}" ] || [ -z "${XROWEXTRACT_TEST_PASSWORD:-}" ]; then
    [ -n "$ONLY" ] && fail "views: XROWEXTRACT_TEST_URL / XROWEXTRACT_TEST_PASSWORD are not set" \
                   || skip "views: XROWEXTRACT_TEST_URL / XROWEXTRACT_TEST_PASSWORD are not set"
  else
    out=$(python3 tests/integration/views.py "$XROWEXTRACT_TEST_URL" 2>&1)
    rc=$?
    summary=$(printf '%s\n' "$out" | tail -1)
    [ $rc = 0 ] && pass "views: ${summary#PASS }" || { printf '%s\n' "$out" | grep -E '^FAIL' | head -30 | sed 's/^/  /'; fail "views: ${summary#FAIL }"; }
  fi
fi

if [ "$failed" = 0 ]; then echo "PASS all checks"; exit 0; fi
echo "FAIL $failed check(s)"; exit 1
