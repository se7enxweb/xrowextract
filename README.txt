CSV Extract for eZ publish

Developed by
Björn Dieding ( bjoern@xrow.de )

This extenstion delivers tools for extracting/exporting content object data to csv.

It comes with following modules/views

xrowextract/csv

Preview

"Preview" in xrowextract/csv shows the export as a spreadsheet will show
it, before downloading: the same code builds the file, and the preview
reads it back with the chosen separator and quoting. It shows the first
10, 25, 50 or 100 rows (remembered per user), how many rows and columns
the file will have, how full each column is, rows that would shift their
columns and cells that were neutralised as formulas. Rows can be
filtered and sorted, cells expanded or wrapped (remembered), and the rows
shown copied tab separated. None of this changes the download.

Settings (csv.ini, [General])

NeutralizeFormulas=enabled         cells starting with = + - @ get a leading '
AllowPasswordHashExport=disabled   offer the user password hash as a column

Site archive (xrowextract/archive)

The content below one or more nodes as one archive: a CSV file for every
class (object id, remote id, main node, parent node, URL alias, dates,
then every attribute of the class), manifest.json and README.txt.
Ready-made node sets (content and media, content, media, users,
everything), single nodes added from a list with counts or the browse
page, classes ticked or unticked (all with content are ticked). ZIP,
TAR.GZ, TAR.BZ2 and TAR.XZ; 7-Zip and RAR when their programs (7z,
rar) are installed. Objects are read with the user's read access, at
their main location, and written once. Password hashes are never
exported. The archive is written to a private folder in the cache
directory and removed after the download.

Filters (the Filters card of xrowextract/csv)

Besides the date range, section, state, visibility and name quick filters,
several conditions can be built, each on a class attribute or an object /
tree field the kernel's own AttributeFilter already supports: name,
published, modified, modified_subnode, section, owner (by id or login),
priority, depth (the object's absolute tree depth), class_identifier,
class_name, node_id, contentobject_id, path, state. Operators: contains,
starts with, is, is not, greater/less (also >=, <=), is empty/not empty,
in list, not in list, between/not between, matches/does not match a *
pattern; every row can be inverted with "not". The rows join with "all
(and)" or "any (or)" — the kernel's AttributeFilter has one join for its
whole filter, not one per group, so "any" widens every active filter, not
only the condition rows, when more than one is set.

The tree scope also takes an exact / at most / at least depth below the
node (the kernel's own Depth/DepthOperator), and a second sort field that
breaks ties in the first.

An extended attribute filter (extendedattributefilter.ini — an eztags
filter, XrowExtractHasChildren, XrowExtractRelation, or any other
registered one) can be chained with the language filter: its own
parameters as one JSON object. XrowExtractHasChildren: {"has_children":
true|false}. XrowExtractRelation: {"object_id": <id>} (objects that
relate to it) or {"object_id": <id>, "reverse": true} (objects it relates
to).

"Named fetch" applies a fetchalias.ini alias (Module=content,
FunctionName tree/list/tree_count/list_count) from this siteaccess, any
active extension or the default siteaccess: its node, class, sort, depth,
limit/offset, main-locations and, where a Constant[attribute_filter] is a
plain "field;op;value", a condition. A Parameter[] entry it declares is
filled in through "Parameters", key=value,key=value.

The "Fetch parameters" box at the end of the card shows the resolved
filters as the literal fetch('content','tree', hash(...)) call a
template would make.

ext:xrowextract:csv: --where "<field> [not] <op> <value>", several joined
with " && " or " || " in one value; --depth accepting a plain number (with
--depth-operator); --sort2/--order2; --extended-filter/--extended-params;
--fetch-alias/--alias-param.

Presets (the Presets card of xrowextract/csv)

A preset is a complete, named export definition: scope, node (by id and
remote_id, so it survives a reinstall's renumbering), class, columns,
languages, every filter above, sort and output settings. "Save as preset"
captures the form as it stands; the picker loads one back, in place, or
"Run in the background" resolves and starts it as a job without loading
it first. A preset may declare {placeholder} tokens (for example a node
or a date), filled in through "Parameters" the same way a named fetch's
Parameter[] is, with its own defaults otherwise.

A preset may Extend another preset ("user:<id>") or a named fetch
("alias:<name>" or "alias:<name>:<siteaccess>"), overriding single keys —
strictly more expressive than a fetch alias alone: inheritance, and every
filter/column/sort/output setting, not only the fetch parameters.

Two layers: a person's own presets (private, or shared with every extract
user — editable by their owner or a user with the xrowextract/all_jobs
policy) are created and edited from the view and kept as JSON in
ezsite_data (name "xrowextract_preset_<20 hex>"); site presets are
`[Preset_<id>]` blocks of xrowextract.ini (Name, Description, View,
Extends, Definition as one JSON object, Placeholders), read only from the
view — ship one with an extension the way the two examples in
extension/xrowextract/settings/xrowextract.ini do. Each preset row's "INI"
disclosure shows the same block, to copy into settings.

A background job started from a preset records which one; the Jobs page
shows it next to the job.

ext:xrowextract:csv --preset "user:<id>"/"site:<id>" --param
key=value,key=value; --list-presets; --show-preset "<ref>" prints the
resolved definition (its own Extends chain followed) as JSON.

Typed column manifest

Every export carries a manifest: per column its key in the file, id,
name, datatype, chosen format, language and the import target it maps
back to; the class meta (required, translatable, selection options,
relation targets, identifier, remote id, a version signature); row
count, file size and SHA-256; export time, filters, preset, schedule,
site and siteaccess. It is written as <file>.manifest.json next to a
background job's or the command line's file, next to every class file
inside a site archive (and next to the archive itself), and on the One
class view as "Manifest only" or "Download with manifest (.zip)". XML
files carry it in a <manifest> element after <columns>, JSON files as an
envelope {"manifest": ..., "rows": [...], "summary": ...} (csv.ini
[Manifest] EmbedInJSON/EmbedInXML=disabled switch that off). The
importer (view and ext:xrowextract:import, which also takes --manifest,
--no-manifest and a zip of file + manifest) maps every column the
manifest describes exactly; a file without one imports as before.

Schedules, destinations, history (the Schedules tab)

xrowextract/schedules: a saved preset, a site archive, an Export as
package or an import from a local folder or a destination, on an hourly,
daily, weekly or monthly choice or a 5-field cron expression, in full or
as a delta (only changes since the last successful run). Imports always
do a dry run first and are applied only when it found no errors. What a
schedule refers to and no longer exists is skipped with a warning.
Started by the cronjob part (php runcronjobs.php xrowextract) or by
system cron with the crontab lines the page shows.
xrowextract/destinations: SFTP (system OpenSSH client, key or password,
trusted host key), FTP/FTPS, a local or NAS folder (below xrowextract.ini
[Destinations] LocalPathRoots[]), S3 compatible storage (SigV4), WebDAV
and HTTP POST. Credentials are encrypted with libsodium; the key file is
xrowextract.ini [Secrets] KeyFile (generated with 0600 on first use).
xrowextract/history: every run with who, what, rows, size, checksum,
delivery and warnings; filters and pages. Failed scheduled runs show as
a red badge on the Jobs tab until marked as seen. Notifications: e-mail
on failure (always the owner), on success (optional), a webhook.
Policies: xrowextract/schedule, xrowextract/destinations,
xrowextract/history. Tables (xrowextract_schedule, _destination,
_history) are created on first use from share/db_schema.dba; sql/<engine>
holds the same for a manual install.
Command line: ext:xrowextract:schedule (--list, --run, --enable,
--disable, --cron, --crontab, --create), ext:xrowextract:destination
(--list, --test, --send, --trust-host-key), ext:xrowextract:history.

Checks (the release gate)

bin/check.sh runs everything a release has to pass and prints PASS/FAIL
per part, exit code 1 when any part fails:
  lint     php -l on every PHP file, every error level on (a compile-time
           deprecation fails too); --php=/path/to/php picks the binary
  ts       xmllint --noout on translations/*/translation.ts
  dup      no <source> twice in one <context> of a .ts file
  phpstan  PHPStan (phpstan.neon.dist: level 6 without required type
           declarations, PHP 8.1 to 8.5; phpstan-baseline.neon holds only
           findings PHPStan cannot see past, each explained)
PHPStan needs the Exponential kernel and library classes: EXPONENTIAL_ROOT
names an Exponential root (default: the installation this extension is
installed in). Anywhere else, clone se7enxweb/exponential and point
EXPONENTIAL_ROOT at it; the Zeta Components come from that root's vendor/
or from EXPONENTIAL_VENDOR_DIR (a vendor directory with
zetacomponents/archive and zetacomponents/base). PHPSTAN names a phpstan
binary; without it the pinned release is downloaded once into var/tools/
and checked against its SHA-256. .github/workflows/check.yml runs the same
on PHP 8.1, 8.2, 8.3, 8.4 and 8.5 for every push and pull request.
