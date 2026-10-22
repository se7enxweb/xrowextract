# xrowextract for Exponential

xrowextract gets content **out of** an Exponential site and back **in**. It exports the objects of a class (or of a
whole site) as CSV, XML, JSON or a content package, previews the file before you download it, runs large exports in
the background, and reads the same files back in to create or update objects. Exports can run on a schedule and be
delivered to SFTP, FTP, S3, WebDAV, HTTP or a local folder, and every run is kept in a history.

Who it is for:

- **editors and site managers** who need a spreadsheet of articles, users, products or comments;
- **developers** moving content between installations (the "migration" column set, content packages, a typed manifest
  for each file);
- **administrators** who want nightly exports, backups of a subtree or regular deliveries to a partner.

Everything is reached from the **Export** tab of the administration (shown with the download icon in admin4), from
the content tree's context menus, from the console and from a cronjob part.

- Source: <https://github.com/se7enxweb/xrowextract>
- Composer package: [`se7enxweb/xrowextract`](https://packagist.org/packages/se7enxweb/xrowextract)
- Issues: <https://github.com/se7enxweb/xrowextract/issues>
- Changes: [CHANGELOG.txt](CHANGELOG.txt)

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Command line](#command-line)
- [Cronjob and schedules](#cronjob-and-schedules)
- [Permissions](#permissions)
- [Template fetch functions](#template-fetch-functions)
- [Upgrading](#upgrading)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Development and tests](#development-and-tests)
- [License](#license)
- [Credits and history](#credits-and-history)

## Features

The Export tab has six pages:

| Page | View | What it does |
| --- | --- | --- |
| One class of content export | `xrowextract/csv` | One class below a node (or the whole site) as CSV, XML, JSON or a content package, with the columns, filters, sort and format of your choice, a preview, presets and background runs |
| Multi class of content export | `xrowextract/archive` | Every class below one or more nodes, one CSV per class plus `manifest.json` and `README.txt`, packed as ZIP, TAR.GZ, TAR.BZ2 or TAR.XZ (7-Zip and RAR when `7z` / `rar` are installed) |
| Import content file | `xrowextract/import` | Reads an XML, CSV or JSON export (or a content package) back in, with a column mapping and a dry run before anything is written; large files upload in chunks and run in the background |
| Import content package | `xrowextract/package` | Inspects and installs content packages (`.ezpkg`), compares them with the site or with another package, browses their files, builds a sample package for a class |
| Schedules | `xrowextract/schedules`, `destinations`, `history` | Exports and imports that run on their own (hourly, daily, weekly, monthly or a cron expression, full or delta), their delivery destinations and the history of every run |
| Jobs | `xrowextract/jobs` | Background exports and imports: progress, log, download, removal |

Highlights:

- **Filters**: date range, section, object state, visibility, name, plus any number of conditions on attributes or
  fields (name, published, modified, section, owner, priority, depth, class, node id, path, state ...) with operators
  such as contains, starts with, is, greater/less, between, in list and `*` patterns; tree depth, a second sort field,
  extended attribute filters and named fetches (`fetchalias.ini`).
- **Preview**: the first 10 to 100 rows as a spreadsheet will show them, how full each column is, rows that would
  shift their columns and cells neutralized as formulas.
- **Presets**: named, complete export definitions, your own (private or shared) or shipped by the site in
  `xrowextract.ini`, with `{placeholder}` parameters and inheritance (`Extends=`).
- **Typed manifest**: every export carries `<file>.manifest.json` (columns, datatypes, formats, languages, row count,
  SHA-256 ...); the importer maps columns exactly from it.
- **Safe spreadsheets**: cells starting with `=`, `+`, `-` or `@` get a leading `'` (`NeutralizeFormulas`).
- **Context menus**: the content tree's *Advanced > Export* menu and the node view's *Import/Export* button open the
  forms filled in for the chosen node (Exponential 6.0.15 and later).
- Light and dark mode of admin4; translations in eighteen languages.

## Requirements

- Exponential 6.x (the legacy kernel)
- PHP 8.1 or newer with `mbstring`, `ctype` and `json`, and writable `var` and cache folders (required)
- Optional, per feature:

| Feature | Needs |
| --- | --- |
| Import of XML files | `dom`, `xmlreader` |
| ZIP downloads and site archives | `zip` |
| Content packages | `dom`, `zlib`, `proc_open()`, `tar` and `gzip`, a writable storage folder |
| Background jobs and schedules | `proc_open()`, `exec()` and a PHP command line binary |
| Destination passwords and keys | `sodium` |
| HTTP, S3, WebDAV and FTP destinations | `curl` |
| SFTP destinations | the OpenSSH client (`sftp`, `ssh-keyscan`, `ssh-keygen`) |

Check a server with the built-in requirements check (run it as the web server's user):

```bash
php extension/xrowextract/bin/php/requirements.php            # PASS / WARN / FAIL per requirement
php extension/xrowextract/bin/php/requirements.php --feature=package,jobs
./console ext:xrowextract:requirements --json
```

Each page also names the features the server cannot offer there.

## Installation

### With Composer

```bash
composer require se7enxweb/xrowextract
```

The installer plugin places the extension in `extension/xrowextract`.

### By hand

1. Download a release from <https://github.com/se7enxweb/xrowextract/releases> (or `git clone` the repository) and
   copy it to `extension/xrowextract`.

### Then, in both cases

2. Activate the extension in `settings/override/site.ini.append.php`:

   ```ini
   [ExtensionSettings]
   ActiveExtensions[]=xrowextract
   ```

3. Regenerate the autoload arrays and clear the caches:

   ```bash
   php bin/php/ezpgenerateautoloads.php -e
   php bin/php/ezcache.php --clear-all
   ```

4. The tables of schedules, destinations and history (`xrowextract_schedule`, `xrowextract_destination`,
   `xrowextract_history`) are created on first use from `share/db_schema.dba`. To create them by hand, use the file of
   your database in `sql/` (`mysql`, `postgresql`, `sqlite`, `oracle`), for example:

   ```bash
   mysql -u <user> -p <database> < extension/xrowextract/sql/mysql/schema.sql
   ```

5. Give the people who export or import a role with the policies they need (see [Permissions](#permissions)).
6. If you use schedules, add the cronjob (see [Cronjob and schedules](#cronjob-and-schedules)).
7. Run `php extension/xrowextract/bin/php/requirements.php` once.

## Configuration

The extension's settings are in `extension/xrowextract/settings/`. Put your changes in
`settings/override/<file>.append.php` (or a siteaccess), not in the extension.

### csv.ini: export, jobs, manifest, uploads

```ini
[General]
# Datatypes that can be exported; each has a [<datatype>] block with HandlerFile and HandlerClass
ExportableDatatypes[]=ezstring
ExportableDatatypes[]=ezxmltext
# ...
StripURLText=true
# Cells starting with = + - @ get a leading ' so a spreadsheet does not run them as formulas
NeutralizeFormulas=enabled
# The password hash of user accounts, for users with the policy xrowextract/password_hash
AllowPasswordHashExport=enabled

[Jobs]
# Days a finished background job is kept
RetentionDays=7
# The PHP command line binary of background jobs; empty: detected
PhpCli=

[Manifest]
EmbedInJSON=enabled
EmbedInXML=enabled

[Uploads]
RetentionHours=24
MinFreeMarginMB=256
# Above this many rows or MB, dry run and import run as background jobs
QueueThresholdRows=2000
QueueThresholdMB=20
JsonOneShotThresholdMB=20

[ezenhancedobjectrelation]
# false exports the IDs of related objects instead of their names
OutputRelatedObjectNames=true
```

A datatype of your own is exported by adding it to `ExportableDatatypes[]` and giving it a handler block:

```ini
[mydatatype]
HandlerFile=extension/myextension/classes/mydatatypehandler.php
HandlerClass=MyDatatypeHandler
```

`classes/parsers/xrowezexamplehandler.php` is a starting point for a handler.

### export.ini: the CSV form and the site archive

```ini
[ExportSettings]
# Classes offered; empty: all
ExportClasses[]
#ExportClasses[]=user
# The node and class the form starts with; empty: the root node of the default siteaccess / the class with most objects
StartNodeID=
DefaultClassID=
PreselectAttributes=true
Limit=0
Offset=0

[SiteArchive]
# The node whose children make the "Sites" set; empty: the content structure
SitesParentNodeID=
DefaultSiteNodeIDs[]

[PackageTemplate]
# Where the sample package builder creates (and removes again) its temporary objects;
# empty: content.ini [NodeSettings] MediaRootNode. Never a node the public site shows.
ScratchNodeID=
```

### xrowextract.ini: presets, schedules, destinations, secrets

```ini
[Schedules]
# The crontab line on the Schedules page runs the cronjob part every this many minutes
CronjobEveryMinutes=5

[History]
RetentionDays=365
PageSize=25

[Destinations]
# The only folders a "Local or NAS folder" destination may write to (and a scheduled import read from)
LocalPathRoots[]
#LocalPathRoots[]=/mnt/nas/exports
MaxAttempts=3
BackoffSeconds=10
SshBinaryDir=

[Secrets]
# Key of the encrypted destination credentials, created with mode 0600 on first use. Never commit it.
KeyFile=settings/override/xrowextract-secrets.key

[Notifications]
# When set, webhooks are signed (X-Xrowextract-Signature: sha256=...)
WebhookSecret=
```

A **site preset** is a `[Preset_<id>]` block in the same file:

```ini
[Preset_recent_news]
Name=Recent news (last 30 days)
Description=News changed in the last 30 days, newest first.
Audience=Site
View=csv
Definition={"scope":"tree","subtree":"2","class_identifier":"news","sort_field":"modified","sort_ascending":false,"filters":{"date_mode":"30","date_field":"modified"}}

[Preset_hidden_content]
Name=Hidden content
Audience=Maintenance
View=csv
Definition={"scope":"tree","subtree":"{node}","sort_field":"modified","sort_ascending":false,"filters":{"visibility":"hidden"}}
Placeholders={"node":{"default":"2"}}
```

`Audience` groups the preset (Site, Editors, Developers, Partners, Users or Maintenance). `Extends=` builds on a
named fetch (`alias:<name>[:<siteaccess>]`) or a user preset (`user:<id>`). The "INI" disclosure of each preset on the
CSV page shows its block, ready to copy. The presets shipped in the extension's `xrowextract.ini` were written for a
demo site (its class identifiers and node ids); replace or extend them for your site.

### Other settings files

| File | What it does |
| --- | --- |
| `extendedattributefilter.ini` | The extended attribute filters `XrowExtractHasChildren`, `XrowExtractRelation`, `XrowExtractUserStatus` (and the internal `XrowExtractTranslation`) |
| `fetchalias.ini` | Two example named fetches: `xrowextract_recent_content`, `xrowextract_by_section` |
| `browse.ini` | The browse modes for the export node, the archive nodes and the import parent |
| `admininterface.ini` | The menu templates of the *Advanced > Export* and *Import/Export* menus |
| `menu.ini` | The **Export** tab (`[Topmenu_xrowextract]`, `Icon=download`) |
| `cronjob.ini` | The cronjob part `xrowextract` |
| `module.ini`, `design.ini`, `site.ini` | Register the module, the design (and its menu script) and the translations |

## Usage

### Export one class as CSV

1. Open **Export > One class of content export** (or right-click a node in the content tree: *Advanced > Export >
   Subtree as CSV*).
2. Choose the node and the scope (children only, or the whole subtree), then the class.
3. Tick the columns. Column sets such as **all** or **migration** fill them in for you.
4. Optional: open **Filters** (for example *modified in the last 30 days* and *section is Standard*), choose the sort.
5. Choose the file format (CSV, XML, JSON or content package), separator, line endings and quoting.
6. Click **Preview** to see the first rows, then download, or **Run in the background** for a large export and
   pick the file up on the **Jobs** page.
7. **Save as preset** keeps everything for the next time.

A link that opens the form filled in, for example from your own template:

```
xrowextract/csv/(node)/2/(scope)/tree/(class)/16/(columns)/migration
```

### Export a whole site or several classes

**Export > Multi class of content export**: choose a node set (content and media, content, media, users, everything)
or single nodes, untick classes you do not need, choose the archive format and download. Each class becomes one CSV
file; password hashes are never exported here.

### Import a file

1. Open **Export > Import content file** (or *Import into this item* in a node's *Import/Export* menu).
2. Upload the file (any size; it uploads in chunks and can resume) and choose the class and the parent node.
3. Check the column mapping (taken from the manifest when the file has one) and whether rows are matched to existing
   objects (by object id or remote id) to update them.
4. Run the **dry run**: it writes nothing and lists what would be created, updated or refused.
5. Apply. Large files run as a background job.

### Install a content package

**Export > Import content package**: upload a `.ezpkg` or pick one from the package repository, inspect it (a dry run),
compare it with the site, then install it (in the background, with live progress). The same page builds a sample
package for a class.

### Schedule an export

1. **Export > Schedules > Destinations**: add where files go (SFTP with a key or password and a trusted host key,
   FTP/FTPS, a local or NAS folder below `LocalPathRoots[]`, S3 compatible storage, WebDAV or HTTP POST) and test it.
2. **Schedules**: create a schedule from a preset, a site archive, a package export or an import; choose how often
   (or a cron expression such as `30 2 * * 1-5`), full or delta, and the destinations.
3. Make sure the cronjob runs (next section). Every run appears in **History**; failed runs show a badge on the
   **Jobs** tab until you have seen them, and the owner gets an e-mail on failure.

## Command line

Every command can be run as `./console ext:xrowextract:<name>` or as `php extension/xrowextract/bin/php/<name>.php`,
from the installation root. `--help` lists all options.

| Command | What it does |
| --- | --- |
| `csv` | One class as a file, with every option of the CSV page, presets and filters |
| `archive` | The site archive |
| `import` | Reads a file back in (a dry run unless `--apply`) |
| `package` | Lists, inspects, installs, exports, compares and builds content packages |
| `job` | Runs, lists and cleans up background jobs |
| `schedule` | Lists, runs, enables, disables and creates schedules; prints crontab lines |
| `destination` | Lists, tests, creates and updates destinations; delivers a file by hand |
| `history` | The export history; `--clean` applies the retention |
| `requirements` | What the extension needs from PHP and the server |

Examples:

```bash
# one class as CSV, XML or a preview
php extension/xrowextract/bin/php/csv.php --class=article --node=2 --output=articles.csv
php extension/xrowextract/bin/php/csv.php --class=user --scope=all --separator=';' --line-endings=win32
php extension/xrowextract/bin/php/csv.php --class=article --node=2 --preview=10
php extension/xrowextract/bin/php/csv.php --list-columns --class=article
php extension/xrowextract/bin/php/csv.php --preset "site:hidden_content" --param node=2

# a site archive
php extension/xrowextract/bin/php/archive.php --set=everything --format=tar.xz --output=var/backups/
php extension/xrowextract/bin/php/archive.php --nodes=2,43 --exclude-classes=image,file

# import: dry run first, then apply
php extension/xrowextract/bin/php/import.php --file=articles.xml --class=article --parent=2
php extension/xrowextract/bin/php/import.php --file=articles.xml --class=article --parent=2 --apply

# content packages
php extension/xrowextract/bin/php/package.php --list
php extension/xrowextract/bin/php/package.php --install=my_package --parent=2 --dry-run
php extension/xrowextract/bin/php/package.php --export --node=2 --subtree --class=article --file=var/tmp/articles.ezpkg

# schedules, destinations, history
php extension/xrowextract/bin/php/schedule.php --list
php extension/xrowextract/bin/php/schedule.php --run=3 --mode=delta --wait
php extension/xrowextract/bin/php/schedule.php --next="30 2 * * 1-5"
php extension/xrowextract/bin/php/destination.php --test=2
php extension/xrowextract/bin/php/history.php --state=failed --limit=50
```

Secrets of destinations are never given on the command line: use `--secret-env=password=VARIABLE` or
`--secret-file=private_key=/path/to/key`.

## Cronjob and schedules

The cronjob part `xrowextract` starts every due schedule as a background job and then removes job folders and history
rows past their retention. Run it every few minutes:

```cron
*/5 * * * * cd /path/to/exponential && php runcronjobs.php xrowextract
```

Or let system cron run each schedule itself: `php extension/xrowextract/bin/php/schedule.php --crontab` prints the
lines (the Schedules page shows them too).

## Permissions

The module `xrowextract` has these policy functions:

| Function | Allows |
| --- | --- |
| `csv` | The CSV and site archive pages, the export menu entries |
| `password_hash` | Exporting the password hash and hash type of user accounts (when `AllowPasswordHashExport` is enabled) |
| `jobs` | The Jobs page: see, download and delete your own background jobs |
| `all_jobs` | Everyone's jobs, schedules, history and shared presets, not only your own |
| `import` | Import of files and content packages, the package browser and compare view |
| `schedule` | Creating, changing, running, enabling and disabling your schedules |
| `destinations` | Managing delivery destinations and their credentials |
| `history` | The export history (your own runs; with `all_jobs` everyone's) |

Reading content always uses the user's own read access: an export never contains what the user cannot see.

## Template fetch functions

Two fetch functions, used by the extension's own templates, are available to yours:

```smarty
{* how many scheduled runs failed or were skipped since the current user last looked *}
{def $alerts = fetch( 'xrowextract', 'schedule_alerts' )}
{if $alerts|gt( 0 )}{$alerts} scheduled run(s) need attention{/if}

{* the features of a page this server cannot offer; page is csv, archive, import, package, jobs, schedules, destinations or history *}
{def $notices = fetch( 'xrowextract', 'requirement_notices', hash( 'page', 'package' ) )}
```

## Upgrading

1. Replace the extension (`composer update se7enxweb/xrowextract`, or copy the new release over
   `extension/xrowextract`).
2. Regenerate the autoloads and clear the caches (see [Installation](#installation)).
3. Run `php extension/xrowextract/bin/php/requirements.php`.
4. Read [CHANGELOG.txt](CHANGELOG.txt). New tables are created on first use.

## Known limits

- The kernel's attribute filter has one join for the whole filter: with **any (or)** and more than one active filter,
  every filter is widened, not only the condition rows.
- JSON import files above `JsonOneShotThresholdMB` are still read in one go (PHP has no streaming JSON parser); XML
  and CSV files are streamed at any size.
- 7-Zip and RAR archives need the `7z` and `rar` programs on the server.
- Losing the `[Secrets] KeyFile` makes stored destination credentials unreadable; enter them again.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| The Export tab is missing | The extension is in `ActiveExtensions[]`, caches are cleared, the user has a role with `xrowextract` policies |
| A page says a feature is not available | Run `requirements.php` as the web server's user; it names what is missing |
| Background jobs stay "queued" | `proc_open()`/`exec()` are allowed and a PHP CLI binary is found; set `[Jobs] PhpCli` if detection guesses wrong |
| Schedules never run | The cronjob part `xrowextract` runs (or the crontab lines from `--crontab` are installed) |
| A spreadsheet shows `'` before some cells | That is `NeutralizeFormulas=enabled` protecting against formula injection |
| A column of a datatype is missing | Add the datatype to `[General] ExportableDatatypes[]` with a handler block in `csv.ini` |
| SFTP destination refuses the server | Trust its host key: `destination.php --scan-host-key=<id>`, then `--trust-host-key=<id> --fingerprint=...` |

## Development and tests

`bin/check.sh` runs the release gate and prints PASS or FAIL per part:

```bash
bin/check.sh                       # lint, translations, PHPStan, unit tests, requirements
bin/check.sh --only=lint,unit
```

- `tests/unit`: PHPUnit tests without a database;
- `tests/integration/cli.sh <root>`: every command against a **test** installation;
- `tests/integration/views.py` and `views_post.py`: every admin view in a browser (Playwright), with hostile input.

PHPStan needs the Exponential kernel classes: set `EXPONENTIAL_ROOT` to an Exponential checkout. The GitHub workflow
`.github/workflows/check.yml` runs the gate on PHP 8.1 to 8.5. Pull requests are welcome at
<https://github.com/se7enxweb/xrowextract>.

## License

GNU General Public License v2.0 (or any later version). See [LICENSE.txt](LICENSE.txt).

## Credits and history

xrowextract was written by Björn Dieding at xrow GmbH around 2008 for eZ Publish, the content management system that
was discontinued by its creator after about eighteen years, as a CSV export of one class. 7x maintains it for
Exponential and has grown it into a complete export and import tool: other formats, content packages, presets,
schedules, destinations, history, a requirements check, PHP 8.1 to 8.5 support and translations. The detailed
technical notes (filter grammar, preview, manifest, release gate) are in [README.txt](README.txt).
