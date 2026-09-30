#!/usr/bin/env bash
# The release gate of xrowextract: every check a release has to pass, one PASS/FAIL line per part, exit
# code 1 when any part fails (2 for a usage error). Run it from anywhere; it works on the clone it is in.
#
#   bin/check.sh [--php=/path/to/php] [--only=lint,ts,dup,phpstan]
#
#   lint     php -l on every PHP file of the extension with that PHP binary, with every error level on,
#            so a compile-time deprecation (e.g. an implicitly nullable parameter) fails as well
#   ts       xmllint --noout on every translations/*/translation.ts
#   dup      no <source> twice in the same <context> of a .ts file
#   phpstan  PHPStan with phpstan.neon.dist (level and baseline there)
#
# Environment:
#   PHP               the PHP binary (default: php in PATH); --php overrides it
#   EXPONENTIAL_ROOT  the Exponential root PHPStan reads the kernel classes from (default: the
#                     installation this extension is installed in, two levels up, when it is one)
#   PHPSTAN           a phpstan binary or phpstan.phar to use (default: phpstan in PATH, else the pinned
#                     release below, downloaded once into var/tools/ and checked against its SHA-256)
set -u
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)
PHP_BIN=${PHP:-php}
ONLY=lint,ts,dup,phpstan
PHPSTAN_VERSION=2.2.16
PHPSTAN_SHA256=1a2fb5460c142502d3cd06272529c18b19fda004ada974bd2581cb9fd6c0a53b
for arg in "$@"; do
  case "$arg" in
    --php=*) PHP_BIN=${arg#--php=} ;;
    --only=*) ONLY=${arg#--only=} ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "FAIL unknown option $arg (see --help)"; exit 2 ;;
  esac
done
want() { case ",$ONLY," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }
command -v "$PHP_BIN" > /dev/null 2>&1 || { echo "FAIL no PHP binary '$PHP_BIN'"; exit 2; }
cd "$HERE" || exit 2
failed=0
pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; failed=$((failed + 1)); }
phpfiles() { find . -name '*.php' -not -path './.git/*' -not -path './var/*' -not -path './vendor/*' | sort; }

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
  rc=$?
  [ $rc = 0 ] && pass "dup: ${out#PASS }" || { printf '%s\n' "$out" | grep '^FAIL' | head -20 | sed 's/^/  /'; fail "dup: duplicate <source> in a context"; }
fi

if want phpstan; then
  root=${EXPONENTIAL_ROOT:-}
  [ -z "$root" ] && [ -f "$HERE/../../autoload/ezp_kernel.php" ] && root=$(cd "$HERE/../.." && pwd)
  stan=${PHPSTAN:-}
  [ -z "$stan" ] && command -v phpstan > /dev/null 2>&1 && stan=$(command -v phpstan)
  if [ -z "$stan" ]; then
    stan=$HERE/var/tools/phpstan-$PHPSTAN_VERSION.phar
    if [ ! -f "$stan" ]; then
      mkdir -p "$HERE/var/tools"
      curl -fsSL -o "$stan.part" "https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar" \
        && mv -f "$stan.part" "$stan" || { rm -f "$stan.part"; stan=""; }
    fi
    if [ -n "$stan" ] && [ "$(sha256sum "$stan" | cut -d' ' -f1)" != "$PHPSTAN_SHA256" ]; then
      fail "phpstan: $stan is not the release $PHPSTAN_VERSION (SHA-256 differs)"; stan=""
    fi
  fi
  if [ -z "$root" ] || [ ! -f "$root/autoload/ezp_kernel.php" ]; then
    fail "phpstan: no Exponential root (set EXPONENTIAL_ROOT to a checkout of se7enxweb/exponential)"
  elif [ -z "$stan" ]; then
    [ "$failed" -gt 0 ] || fail "phpstan: no phpstan (set PHPSTAN, or allow the download of release $PHPSTAN_VERSION)"
  else
    case "$stan" in *.phar) run=("$PHP_BIN" -d memory_limit=2G "$stan") ;; *) run=("$stan") ;; esac
    out=$(EXPONENTIAL_ROOT=$root "${run[@]}" analyse --no-progress --memory-limit=2G --error-format=raw 2>&1)
    rc=$?
    level=$(sed -n 's/^    level: *\([0-9]*\)$/\1/p' phpstan.neon.dist)
    if [ $rc = 0 ]; then
      pass "phpstan: level $level, no errors (kernel from $root)"
    else
      printf '%s\n' "$out" | grep -E '^/|^[a-z].*\.php:|^ *\[ERROR\]|rror' | head -40 | sed "s#$HERE/##; s/^/  /"
      fail "phpstan: level $level"
    fi
  fi
fi

if [ "$failed" = 0 ]; then echo "PASS all checks ($ONLY)"; exit 0; fi
echo "FAIL $failed check(s)"; exit 1
