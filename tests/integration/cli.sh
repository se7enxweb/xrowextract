#!/usr/bin/env bash
# Integration test of every xrowextract command (bin/php/*.php, the ext:xrowextract:* console commands)
# and the cronjob part, against a TEST installation: valid runs and invalid input alike must end with a
# message and an exit code, never a fatal error, a PHP warning, notice or deprecation.
#
#   tests/integration/cli.sh <installation root> [class] [node]
#
# It runs the copy of the extension installed in <root>/extension/xrowextract (sync this checkout there
# first) as the owner of <root>/var (XROWEXTRACT_TEST_USER to override), with every error level shown.
# It WRITES to that installation: exports into var/tmp/xrowextract-cli-test/, a package it exports and
# registers (xrowextract_export_clitest, removed again by --clean), a dry-run import, a schedule --cron run.
# Never point it at a live site. The node defaults to 2 (the content root), the class to the first one
# with objects below it.
# PASS/FAIL per case; the last line is the summary; exit code 1 when a case failed.
set -u
ROOT=${1:?usage: cli.sh <installation root> [class] [node]}
CLASS=${2:-}
NODE=${3:-2}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
FIX=$HERE/fixtures
PHP_BIN=${PHP:-php}
ROOT=$(cd "$ROOT" && pwd -P) || { echo "FAIL no installation at $1"; exit 1; }
[ -f "$ROOT/extension/xrowextract/bin/php/csv.php" ] || { echo "FAIL $ROOT has no extension/xrowextract"; exit 1; }
RUNAS=${XROWEXTRACT_TEST_USER:-$(stat -c %U "$ROOT/var")}
W=$ROOT/var/tmp/xrowextract-cli-test
mkdir -p "$W" && cp -f "$FIX"/* "$W/"
[ "$(id -u)" = 0 ] && chown -R "$RUNAS" "$W"
cd "$ROOT" || exit 1

# The installed copy against this checkout: a stale copy would test old code
if ! diff -rq --exclude=var --exclude=.git "$HERE/../../classes" "$ROOT/extension/xrowextract/classes" > /dev/null 2>&1 \
   || ! diff -rq "$HERE/../../bin/php" "$ROOT/extension/xrowextract/bin/php" > /dev/null 2>&1; then
  echo "INFO the installed extension/xrowextract differs from this checkout (classes/ or bin/php/)"
fi

PHP_ERRORS='(PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)(:| +)|Uncaught [A-Za-z\\]+|Stack trace:|An unexpected error has occurred|Call to (a member function|undefined)|Undefined (variable|array key|index|offset)'
pass=0; failed=0
out=""; rc=0
run() {
  if [ "$(id -u)" = 0 ] && [ "$RUNAS" != root ]; then
    out=$(runuser -u "$RUNAS" -- "$PHP_BIN" -d error_reporting=-1 -d display_errors=stderr -d memory_limit=1G "$@" 2>&1 < /dev/null)
  else
    out=$("$PHP_BIN" -d error_reporting=-1 -d display_errors=stderr -d memory_limit=1G "$@" 2>&1 < /dev/null)
  fi
  rc=$?
}
if [ -z "$CLASS" ]; then
  run extension/xrowextract/bin/php/csv.php --list-classes --node=$NODE
  CLASS=$(printf '%s\n' "$out" | grep -E ' [1-9][0-9]* objects' | awk '{print $2}' | head -1)
  [ -n "$CLASS" ] || { echo "FAIL no class with objects below node $NODE (give one: cli.sh <root> <class> <node>)"; exit 1; }
fi
echo "INFO class $CLASS below node $NODE, as $RUNAS, extension/xrowextract of $ROOT"
# t <label> <ok|fail|any> <regex the output must match, or ''> <script> [args...]
#   ok: exit code 0; fail: exit code 1 to 3 with a message; any: 0 to 3
# What the kernel logs instead of printing (a failed query, an error of eZDebug) counts too: the new
# lines of var/log/error.log and warning.log since the case started
LOGS="$ROOT/var/log/error.log $ROOT/var/log/warning.log"
logsizes() { for f in $LOGS; do [ -f "$f" ] && stat -c %s "$f" || echo 0; done | tr '\n' ' '; }
newlog() { # <sizes before>: new log text of this case, 404 lines left out
  local i=1 f before
  for f in $LOGS; do
    before=$(echo "$1" | cut -d' ' -f$i); i=$((i + 1))
    [ -f "$f" ] && tail -c +$((before + 1)) "$f"
  done | grep -vE 'Error ocurred using URI|^\s*$' | grep -A1 -E '^\[ ' | grep -vE '^\[ |^--' | head -2 | tr '\n' ' '
}
t() {
  local label=$1 want=$2 expect=$3 script=$4
  shift 4
  local sizes; sizes=$(logsizes)
  run "extension/xrowextract/bin/php/$script" "$@"
  local problems=""
  local logged; logged=$(newlog "$sizes")
  [ -n "$logged" ] && problems="logged: ${logged:0:220}"
  case "$want" in
    ok) [ "$rc" = 0 ] || problems="exit $rc (want 0)" ;;
    fail) { [ "$rc" -ge 1 ] && [ "$rc" -le 3 ]; } || problems="exit $rc (want 1 to 3)"
          [ -n "$(printf '%s' "$out" | tr -d '[:space:]')" ] || problems="$problems no message" ;;
    any) [ "$rc" -le 3 ] || problems="exit $rc" ;;
  esac
  local err
  err=$(printf '%s\n' "$out" | grep -E "$PHP_ERRORS" | head -2)
  [ -n "$err" ] && problems="$problems PHP: $err"
  if [ -n "$expect" ] && ! printf '%s\n' "$out" | grep -qE "$expect"; then
    problems="$problems output lacks /$expect/"
  fi
  if [ -z "$problems" ]; then
    pass=$((pass + 1)); echo "PASS $label"
  else
    failed=$((failed + 1)); echo "FAIL $label: $problems"
    printf '%s\n' "$out" | tail -4 | sed 's/^/    | /'
  fi
}

# ext:xrowextract:csv
t 'csv --help' ok 'Usage' csv.php --help
t 'csv --list-classes' ok "$CLASS" csv.php --list-classes --node=$NODE
t 'csv --list-columns' ok 'Special columns' csv.php --list-columns --class=$CLASS
t 'csv to a file' ok '^Wrote ' csv.php --class=$CLASS --scope=all --limit=3 --output="$W/export.csv"
t 'csv --format=json' ok '^Wrote ' csv.php --class=$CLASS --scope=all --limit=3 --format=json --output="$W/export.json"
t 'csv --format=xml' ok '^Wrote ' csv.php --class=$CLASS --scope=all --limit=3 --format=xml --output="$W/export.xml"
t 'csv --format=ezpkg' ok '' csv.php --class=$CLASS --scope=all --limit=2 --format=ezpkg --output="$W/export.ezpkg"
t 'csv --preview' ok 'rows' csv.php --class=$CLASS --scope=all --preview=2
t 'csv --where, --sort' ok '^Wrote ' csv.php --class=$CLASS --scope=all --where='name contains a' --sort=name --order=desc --output="$W/where.csv"
t 'csv --list-presets' ok 'site:' csv.php --list-presets
t 'csv --show-preset of a site preset' ok 'Placeholders' csv.php --show-preset="$(sed -n 's/^\[Preset_\(.*\)\]$/site:\1/p' "$ROOT/extension/xrowextract/settings/xrowextract.ini" | head -1)"
t 'csv: unknown class' fail 'No class' csv.php --class=no_such_class_xyz
t 'csv: node that does not exist' fail '' csv.php --class=$CLASS --node=999999999
t 'csv: node beyond any id' fail '' csv.php --class=$CLASS --node=99999999999999999999
t 'csv: node that is not a number' fail '' csv.php --class=$CLASS --node=abc
t 'csv: class beyond any id' fail 'No class' csv.php --class=99999999999999999999
t 'csv: unknown format' fail '' csv.php --class=$CLASS --scope=all --format=pdf
t 'csv: quote as separator' fail 'separator' csv.php --class=$CLASS --separator='"'
t 'csv: unknown column' fail 'Unknown column' csv.php --class=$CLASS --columns=no_such_column
t 'csv: unknown language' fail 'Unknown language' csv.php --class=$CLASS --languages=xx-XX
t 'csv: unreadable date' fail '' csv.php --class=$CLASS --scope=all --since=not-a-date
t 'csv: condition without an operator' fail '' csv.php --class=$CLASS --scope=all --where='name'
t 'csv: unknown preset' fail '' csv.php --preset=site:no_such_preset
t 'csv: malformed preset ref' fail '' csv.php --preset='../../etc/passwd'
t 'csv: unknown scope' fail '' csv.php --class=$CLASS --scope=everything
t 'csv: depth that is not a number' fail '' csv.php --class=$CLASS --depth=deep
t 'csv: unknown named fetch' fail '' csv.php --fetch-alias=no_such_alias
t 'csv: extended params that are not JSON' any '' csv.php --class=$CLASS --scope=all --extended-filter=nope --extended-params='{x' --output="$W/ext.csv"

# ext:xrowextract:archive
t 'archive --list-sets' ok '' archive.php --list-sets
t 'archive --list-formats' ok 'zip' archive.php --list-formats
t 'archive --list-classes' ok '' archive.php --list-classes --nodes=$NODE
t 'archive --dry-run' ok 'files, [0-9]+ rows' archive.php --dry-run --nodes=$NODE --classes=$CLASS
t 'archive zip' ok '^Wrote ' archive.php --nodes=$NODE --classes=$CLASS --format=zip --output="$W/"
t 'archive tar.gz, json files' ok '^Wrote ' archive.php --nodes=$NODE --classes=$CLASS --format=tar.gz --files=json --output="$W/"
t 'archive: node that does not exist' fail '' archive.php --nodes=999999999
t 'archive: nodes that are not ids' fail '' archive.php --nodes=abc
t 'archive: nodes beyond any id' fail '' archive.php --nodes=99999999999999999999
t 'archive: class beyond any id' fail '' archive.php --nodes=$NODE --classes=99999999999999999999
t 'archive: unknown class' fail '' archive.php --nodes=$NODE --classes=no_such_class_xyz
t 'archive: unknown set' fail '' archive.php --set=no_such_set
t 'archive: unknown format' fail '' archive.php --nodes=$NODE --format=exe
t 'archive: unknown file format' fail '' archive.php --nodes=$NODE --files=pdf
t 'archive: output folder that does not exist' fail '' archive.php --nodes=$NODE --classes=$CLASS --output=/nonexistent-dir/x/

# ext:xrowextract:package
t 'package --list' ok '' package.php --list
t 'package --export --keep' ok 'PASS' package.php --export --node=$NODE --name=xrowextract_export_clitest --keep --file="$W/clitest.ezpkg"
t 'package --inspect' ok '' package.php --inspect=xrowextract_export_clitest
t 'package --install --dry-run' ok '' package.php --install=xrowextract_export_clitest --dry-run --parent=$NODE
t 'package --compare --with' ok '' package.php --compare=xrowextract_export_clitest --with=xrowextract_export_clitest
t 'package --export with filters' ok '' package.php --export --node=$NODE --subtree --class=$CLASS --limit=2 --file="$W/filtered.ezpkg"
t 'package: inspect unknown package' fail '' package.php --inspect=no_such_package_xyz
t 'package: inspect a file that is not a package' fail '' package.php --inspect="$W/garbage.ezpkg"
t 'package: install without a parent' any '' package.php --install=xrowextract_export_clitest --dry-run
t 'package: install unknown package' fail '' package.php --install=no_such_package_xyz --dry-run --parent=$NODE
t 'package: parent that does not exist' fail '' package.php --install=xrowextract_export_clitest --dry-run --parent=999999999
t 'package: parent beyond any id' fail '' package.php --install=xrowextract_export_clitest --dry-run --parent=99999999999999999999
t 'package: export of a node beyond any id' fail '' package.php --export --node=99999999999999999999 --file="$W/x.ezpkg"
t 'package: unknown object mode' fail '' package.php --install=xrowextract_export_clitest --dry-run --parent=$NODE --object-mode=bogus
t 'package --compare with this site' ok 'compared with this site' package.php --compare=xrowextract_export_clitest
t 'package: export without a node' fail 'Missing --node' package.php --export --file="$W/x.ezpkg"
t 'package: export of an empty node list' fail 'No node id' package.php --export --nodes=, --file="$W/x.ezpkg"
t 'package: export of a node that does not exist' fail 'No node' package.php --export --node=999999999 --file="$W/x.ezpkg"
t 'package: template for an unknown class' fail '' package.php --template --class=no_such_class_xyz
t 'package: unknown variant' fail '' package.php --template --class=$CLASS --variant=bogus
t 'package --clean --dry-run' ok '' package.php --clean --dry-run
t 'package --clean' ok '' package.php --clean

# ext:xrowextract:import (dry runs: nothing is written without --apply)
t 'import the CSV export (dry run)' ok 'Dry run' import.php --file="$W/export.csv"
t 'import the JSON export (dry run)' ok 'Dry run' import.php --file="$W/export.json"
t 'import the XML export (dry run)' ok 'Dry run' import.php --file="$W/export.xml"
t 'import a new row below a node (dry run)' ok 'CREATE' import.php --file="$W/folder.csv" --class=$CLASS --parent=$NODE
t 'import with a report file' ok '' import.php --file="$W/folder.csv" --class=$CLASS --parent=$NODE --report="$W/report.json"
t 'import: missing --file' fail 'Missing --file' import.php
t 'import: file that does not exist' fail '' import.php --file="$W/does-not-exist.csv"
t 'import: empty file' fail '' import.php --file="$W/empty.csv"
t 'import: header only' any '' import.php --file="$W/header-only.csv" --class=$CLASS --parent=$NODE
t 'import: binary garbage' any '' import.php --file="$W/garbage.csv"
t 'import: unclosed quote' any '' import.php --file="$W/unclosed-quote.csv" --class=$CLASS --parent=$NODE
t 'import: XML with a DOCTYPE' fail 'DOCTYPE' import.php --file="$W/doctype.xml"
t 'import: malformed XML' fail 'Malformed XML' import.php --file="$W/malformed.xml"
t 'import: malformed JSON' fail '' import.php --file="$W/malformed.json"
t 'import: JSON that is not rows' any '' import.php --file="$W/scalar.json"
t 'import: a zip that is not one' fail '' import.php --file="$W/fake.zip"
t 'import: unknown class' fail '' import.php --file="$W/folder.csv" --class=no_such_class_xyz --parent=$NODE
t 'import: parent that does not exist' any '' import.php --file="$W/folder.csv" --class=$CLASS --parent=999999999
t 'import: parent beyond any id' any '' import.php --file="$W/folder.csv" --class=$CLASS --parent=99999999999999999999
t 'import: class beyond any id' fail '' import.php --file="$W/folder.csv" --class=99999999999999999999 --parent=$NODE
t 'import: unknown match mode' fail 'Unknown --match' import.php --file="$W/folder.csv" --class=$CLASS --match=bogus
t 'import: unknown language' fail 'Unknown language' import.php --file="$W/folder.csv" --class=$CLASS --language=xx-XX
t 'import: map to an unknown target' any '' import.php --file="$W/folder.csv" --class=$CLASS --parent=$NODE --map=name=no_such_target
t 'import: unknown user' fail '' import.php --file="$W/folder.csv" --user=no_such_user_xyz
t 'import: resume from a row that is not a number' fail 'row number' import.php --file="$W/folder.csv" --resume-from=abc

# ext:xrowextract:schedule and the cronjob part
t 'schedule --list' ok '' schedule.php --list
t 'schedule --list --json' ok '' schedule.php --list --json
t 'schedule --crontab' ok 'runcronjobs' schedule.php --crontab
t 'schedule --next' ok '' schedule.php --next='*/15 * * * *'
t 'schedule --cron' ok '' schedule.php --cron
t 'schedule: --next of an invalid expression' fail '' schedule.php --next='61 * * * *'
t 'schedule: show one that does not exist' fail 'No schedule' schedule.php --show=999999999
t 'schedule: show one beyond any id' fail 'No schedule' schedule.php --show=99999999999999999999
t 'schedule: run one that does not exist' fail 'No schedule' schedule.php --run=999999999
t 'schedule: enable one that does not exist' fail '' schedule.php --enable=999999999
t 'schedule: create without a name' fail '' schedule.php --create --kind=archive --frequency=daily
t 'schedule: create of an unknown kind' fail '' schedule.php --create --name=cli-test --kind=bogus --frequency=daily
t 'schedule: create with an invalid cron expression' fail '' schedule.php --create --name=cli-test --kind=archive --frequency=cron --expression='* *'
t 'schedule: create with an unknown preset' fail '' schedule.php --create --name=cli-test --kind=preset --preset=site:no_such_preset --frequency=daily
run runcronjobs.php -q xrowextract
if [ "$rc" = 0 ] && ! printf '%s\n' "$out" | grep -qE "$PHP_ERRORS"; then pass=$((pass + 1)); echo "PASS runcronjobs.php xrowextract"
else failed=$((failed + 1)); echo "FAIL runcronjobs.php xrowextract: exit $rc"; printf '%s\n' "$out" | tail -4 | sed 's/^/    | /'; fi

# ext:xrowextract:destination
t 'destination --list' ok '' destination.php --list
t 'destination --list --json' ok '' destination.php --list --json
t 'destination: test one that does not exist' fail 'No destination' destination.php --test=999999999
t 'destination: test one beyond any id' fail 'No destination' destination.php --test=99999999999999999999
t 'destination: scan host keys of one that does not exist' fail 'No destination' destination.php --scan-host-key=999999999
t 'destination: create of an unknown type' fail '' destination.php --create --name=cli-test --type=gopher
t 'destination: create with a setting that is not key=value' fail 'takes key=value' destination.php --create --name=cli-test --type=local --config='{nope'
t 'destination: delete one that does not exist' fail '' destination.php --delete=999999999

# ext:xrowextract:history and ext:xrowextract:job
t 'history' ok '' history.php --limit=5
t 'history --json' ok '' history.php --limit=5 --json
t 'history with filters' ok '' history.php --state=done --kind=csv --from=2026-01-01 --to=2026-12-31
t 'history: show a row that does not exist' fail 'No history row' history.php --show=999999999
t 'history: show a row beyond any id' fail 'No history row' history.php --show=99999999999999999999
t 'history: a schedule beyond any id' ok '' history.php --schedule=99999999999999999999
t 'history: unknown state' any '' history.php --state=bogus
t 'history: unreadable date' any '' history.php --from=yesterday-ish
t 'job --list' ok '' job.php --list
t 'job --clean' ok '' job.php --clean
t 'job: run an id that is not one' fail 'Not a job id' job.php --run=zzz
t 'job: run a job that does not exist' fail '' job.php --run=0123456789abcdef0123456789abcdef
t 'job: run a path' fail '' job.php --run=../../../etc/passwd

echo
if [ "$failed" = 0 ]; then echo "PASS all $pass cases"; exit 0; fi
echo "FAIL $failed of $((pass + failed)) cases"; exit 1
