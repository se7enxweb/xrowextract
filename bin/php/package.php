#!/usr/bin/env php
<?php
/**
 * Content packages (.ezpkg) from the command line: everything the
 * xrowextract/package view does (list, inspect, install, build a sample
 * template) plus a plain node/subtree export, with the same code
 * (XrowExtractPackage) so behaviour cannot drift from the web view.
 *
 * Usage (from the installation root; ./console ext:xrowextract:package runs it too):
 *   php extension/xrowextract/bin/php/package.php --list
 *   php extension/xrowextract/bin/php/package.php --inspect=my_package
 *   php extension/xrowextract/bin/php/package.php --install=my_package --parent=2 --dry-run
 *   php extension/xrowextract/bin/php/package.php --install=my_package --parent=2 --site-access=site --object-mode=update --class-mode=skip
 *   php extension/xrowextract/bin/php/package.php --export --node=130 --file=var/tmp/ng_news_130.ezpkg
 *   php extension/xrowextract/bin/php/package.php --export --node=2 --subtree --class=ng_article --file=var/tmp/articles.ezpkg
 *   php extension/xrowextract/bin/php/package.php --template --class=ng_article --variant=both --file=var/tmp/ng_article_template.ezpkg
 *
 * --output writes a JSON report alongside the normal text output, for
 * --inspect and --install: how xrowextract/jobs.php (type "package") runs a
 * large package inspect/install in the background and reports it, through
 * bin/php/job.php the same way a csv/archive job does. --progress-file gets
 * a couple of coarse phase updates (eZPackage::install() has no natural
 * per-item hook to report finer progress from without patching the kernel).
 */

require_once dirname( __FILE__ ) . '/../../../../autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(
    'description'    => "Inspects, installs, exports or builds a sample of a content package (.ezpkg): a content class, content objects, or both.",
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
) );
$script->startup();
$options = $script->getOptions(
    '[list][inspect:][install:][export][template][clean][dry-run][parent:][site-access:][object-mode:][class-mode:][remove-after]' .
    '[node:][nodes:][subtree][class:][variant:][object-count:][languages:][name:][file:][keep][user:][output:][progress-file:]' .
    '[compare:][with:]' .
    '[scope:][depth:][depth-operator:][main-only][offset:][limit:][date-field:][since:][before:][date:][section:][state:][visibility:]' .
    '[name-contains:][where:][sort:][order:][sort2:][order2:][extended-filter:][extended-params:][fetch-alias:][alias-param:*][preset:][param:*]' .
    '[changed-since:][lenient]',
    '',
    array(
        'compare'      => 'Package name: compare it with --with=<other package> (classes and objects added, removed and changed, by remote id), or without --with with this site (what an install would create or change, down to the fields the dry run can compare)',
        'with'         => '--compare: the other package',
        'scope'        => '--export with filters: node (default) or all, as ext:xrowextract:csv',
        'depth'        => '--export with filters: tree (default), list or a number, as ext:xrowextract:csv',
        'depth-operator' => '--export with filters: eq, le or ge, as ext:xrowextract:csv',
        'main-only'    => '--export with filters: only main locations',
        'offset'       => '--export with filters: skip this many objects',
        'limit'        => '--export with filters: take at most this many objects',
        'date-field'   => '--export with filters: the date the date filters use, as ext:xrowextract:csv',
        'since'        => '--export with filters: only objects dated on or after this, as ext:xrowextract:csv',
        'before'       => '--export with filters: only objects dated on or before this',
        'date'         => '--export with filters: today, 7, 30, 90, 365, future or past',
        'section'      => '--export with filters: only this section (id or identifier)',
        'state'        => '--export with filters: only this object state (id)',
        'visibility'   => '--export with filters: visible or hidden',
        'name-contains' => '--export with filters: only objects whose name contains this (ext:xrowextract:csv --name)',
        'where'        => '--export with filters: conditions, exactly as ext:xrowextract:csv --where',
        'sort'         => '--export with filters: sort field, as ext:xrowextract:csv',
        'order'        => '--export with filters: asc or desc',
        'sort2'        => '--export with filters: a second sort field',
        'order2'       => '--export with filters: asc or desc, for --sort2',
        'extended-filter' => '--export with filters: an extendedattributefilter.ini id',
        'extended-params' => '--export with filters: its params as JSON',
        'fetch-alias'  => '--export with filters: a fetchalias.ini named fetch',
        'alias-param'  => '--export with filters: key=value for the named fetch (repeatable)',
        'preset'       => '--export: a saved export preset (user:<id> or site:<id>; ext:xrowextract:csv --list-presets): its node, class, languages, filters and sort',
        'param'        => '--export with --preset: key=value for the preset\'s placeholders (repeatable)',
        'changed-since' => '--export with filters: only objects modified after this (a delta)',
        'lenient'      => '--export with filters: skip what no longer resolves with a WARNING line, as ext:xrowextract:csv',
        'list'         => 'List the packages in the repository that carry a content class or content object',
        'inspect'      => 'Package name: show what it carries and what installing it would do (nothing is written)',
        'install'      => 'Package name: install it (through the same eZPackage::install() the web view uses)',
        'export'       => 'Export a node/subtree (or several, --nodes) of live content as a new package (--node, optionally --subtree/--class); registered in the repository only with --keep',
        'template'     => 'Build a sample content+class package for --class (--variant class/content/both, default both); registered in the repository only with --keep',
        'clean'        => 'Remove this extension\'s own leftover sample/export/template packages (xrowextract_sample_/xrowextract_export_/xrowextract_template_); lists them first, never touches any other package',
        'dry-run'      => '--install: inspect only, do not write anything. --clean: list what would be removed, remove nothing',
        'parent'       => '--install: the parent node id for the package\'s own top-level objects',
        'site-access'  => '--install: site access to map templates/overrides to (default: SiteSettings.DefaultAccess)',
        'object-mode'  => '--install: skip, update (default) or new, for an object that already exists (matched by remote id)',
        'class-mode'   => '--install: skip (default), replace or new, for a class that already exists (matched by remote id/identifier)',
        'remove-after' => '--install: remove the package from the repository once installed (whether or not the install itself was clean) - for a job installing a transient package (a "Try a sample"/"Start from a template" one the web view registered just for this job\'s run), so the repository ends up as if the whole thing had run in the request',
        'node'         => '--export: the node id to export',
        'nodes'        => '--export: several node ids, comma-separated (the whole One class/Site archive selection); an alternative to --node',
        'subtree'      => '--export: the whole subtree below --node/--nodes, not only that node',
        'class'        => '--export: only this class below --node (id or identifier, --node only, not --nodes); --template: the class to build a sample for (required)',
        'variant'      => '--template: class, content or both (default both)',
        'object-count' => '--template: how many sample content objects to create (default 3, max 5)',
        'languages'    => '--template: comma list of locales for the sample content (default: up to 2 of the site\'s content languages)',
        'name'         => '--export: package name (default: a name derived from the node)',
        'file'         => '--export/--template: file to write the .ezpkg to (default: --output, set by a background job)',
        'keep'         => '--export/--template: also register the package in the repository (default: write the .ezpkg file only, remove it from the repository again)',
        'user'         => 'Run with the access rights of this login (default: admin)',
        'output'       => '--inspect/--install/--export: also write a JSON report (inspect/install) or the archive itself (export) here (for a background job; see bin/php/job.php)',
        'progress-file' => '--install/--export: write {"done":n,"total":m,"phase":"..."} to this path after each phase (for a background job)',
    )
);
$script->initialize();

$fail = function ( $message ) use ( $cli, $script )
{
    $cli->error( $message );
    $script->shutdown( 1 );
};

// The datatype check: one WARNING line per datatype the package uses that this site does not have
// (bin/php/job.php collects "WARNING: " lines for the Jobs page and the install history), or a PASS line
$datatypeCheck = function ( array $missing ) use ( $cli )
{
    foreach ( XrowExtractPackage::missingDatatypeLines( $missing ) as $line )
        $cli->output( 'WARNING: ' . $line );
    if ( !$missing )
        $cli->output( 'Datatypes: every datatype the package uses exists on this site.' );
};

$login = $options['user'] ? $options['user'] : 'admin';
$user = eZUser::fetchByName( $login );
if ( !$user instanceof eZUser )
    $fail( "No user with login $login (--user)." );
$user->loginCurrent();

if ( $options['list'] )
{
    $packages = XrowExtractPackage::repositoryPackages();
    if ( !$packages )
        $cli->output( 'No content packages in the repository.' );
    foreach ( $packages as $row )
    {
        $cli->output( sprintf( '  %-40s %-8s %-6s classes=%-3d objects=%-3d %s', $row['name'], $row['version'] ?: '-', $row['is_installed'] ? 'installed' : '-',
                               $row['class_count'], $row['object_item_count'], $row['summary'] ) );
    }
    $script->shutdown( 0 );
}

if ( $options['clean'] )
{
    // Every package this extension's own code ever builds and might, through a bug or a killed
    // request, leave registered when it should not be - a "Try a sample" that skipped its own
    // cleanup, an --export or --template run without --keep from before that default existed, and
    // so on. Lists first, always; --dry-run removes nothing. Never touches a package this
    // extension did not build (findLeftoverPackages() only matches its own name prefixes).
    $leftovers = XrowExtractPackage::findLeftoverPackages();
    if ( !$leftovers )
    {
        $cli->output( 'PASS nothing to clean: no ' . implode( '/', XrowExtractPackage::leftoverPackagePrefixes() ) . ' package in the repository.' );
        $script->shutdown( 0 );
    }
    foreach ( $leftovers as $package )
        $cli->output( '  ' . $package->attribute( 'name' ) . ( $package->attribute( 'is_installed' ) ? ' (installed)' : '' ) );
    if ( $options['dry-run'] )
    {
        $cli->output( 'PASS ' . count( $leftovers ) . ' package(s) listed above would be removed (--dry-run: nothing removed).' );
        $script->shutdown( 0 );
    }
    foreach ( $leftovers as $package )
        $package->remove();
    $cli->output( 'PASS removed ' . count( $leftovers ) . ' package(s).' );
    $script->shutdown( 0 );
}

if ( $options['inspect'] )
{
    $package = eZPackage::fetch( $options['inspect'] );
    if ( !$package instanceof eZPackage )
        $fail( "No package '{$options['inspect']}' in the repository (--list shows them)." );
    $inspection = XrowExtractPackage::inspect( $package );
    $cli->output( $inspection['meta']['name'] . ' ' . $inspection['meta']['version'] . ' - ' . $inspection['meta']['summary'] );
    foreach ( $inspection['errors'] as $error )
        $cli->error( '  ' . $error );
    $cli->output( 'Classes:' );
    foreach ( $inspection['classes'] as $row )
        $cli->output( sprintf( '  %-10s %-30s %-24s attrs=%d', $row['state'], $row['identifier'], $row['name'], $row['attribute_count'] ) );
    $cli->output( 'Objects:' );
    foreach ( $inspection['objects'] as $row )
        $cli->output( sprintf( '  %-14s %-24s %-30s %s', $row['state'], $row['class_identifier'], $row['name'], implode( '+', $row['languages'] ) ) );
    $c = $inspection['counts'];
    $cli->output( sprintf( 'classes: %d create, %d update  |  objects: %d create, %d update, %d unchanged, %d class missing',
                           $c['classes_create'], $c['classes_update'], $c['objects_create'], $c['objects_update'], $c['objects_unchanged'], $c['objects_class_missing'] ) );
    $datatypeCheck( $inspection['missing_datatypes'] );
    if ( $options['output'] )
        file_put_contents( (string)$options['output'], json_encode( array( 'ok' => true, 'action' => 'inspect', 'inspection' => $inspection ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    $script->shutdown( 0 );
}

if ( $options['compare'] )
{
    // Two packages with each other (--compare=<a> --with=<b>), or one with the site (--compare=<a> alone:
    // the dry run's view per object, down to the fields that differ where the dry run can tell)
    $package = eZPackage::fetch( $options['compare'] );
    if ( !$package instanceof eZPackage )
        $fail( "No package '{$options['compare']}' in the repository (--list shows them)." );
    if ( $options['with'] )
    {
        $other = eZPackage::fetch( $options['with'] );
        if ( !$other instanceof eZPackage )
            $fail( "No package '{$options['with']}' in the repository (--with)." );
        $comparison = XrowExtractPackage::comparePackages( $package, $other );
        $cli->output( sprintf( '%s -> %s', $package->attribute( 'name' ), $other->attribute( 'name' ) ) );
        foreach ( array( 'classes', 'objects' ) as $kind )
        {
            $k = $comparison['counts'][$kind];
            $cli->output( sprintf( '%s: %d only in %s, %d only in %s, %d changed, %d the same', $kind, $k['removed'], $package->attribute( 'name' ),
                                   $k['added'], $other->attribute( 'name' ), $k['changed'], $k['unchanged'] ) );
            foreach ( $comparison[$kind] as $row )
            {
                $cli->output( sprintf( '  %-8s %-24s %s', $row['change'], $kind === 'classes' ? $row['identifier'] : $row['class_identifier'], $row['name'] ) );
                foreach ( $row['differences'] as $difference )
                    $cli->output( sprintf( '           %s %s%s: %s -> %s', $difference['kind'], $difference['field'],
                                           !empty( $difference['language'] ) ? ' (' . $difference['language'] . ')' : '', $difference['old'], $difference['new'] ) );
            }
        }
        if ( $options['output'] )
            file_put_contents( (string)$options['output'], json_encode( array( 'ok' => true, 'action' => 'compare', 'comparison' => $comparison ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        $script->shutdown( 0 );
    }
    $inspection = XrowExtractPackage::inspect( $package, $options['parent'] ? (int)$options['parent'] : false );
    $cli->output( sprintf( '%s compared with this site:', $package->attribute( 'name' ) ) );
    foreach ( $inspection['classes'] as $row )
    {
        $cli->output( sprintf( '  class  %-10s %s', $row['state'], $row['identifier'] ) );
        if ( $row['diff'] )
        {
            foreach ( $row['diff']['added'] as $d )
                $cli->output( sprintf( '           added %s (%s)', $d['identifier'], $d['datatype'] ) );
            foreach ( $row['diff']['removed'] as $d )
                $cli->output( sprintf( '           not in the package %s (%s)', $d['identifier'], $d['datatype'] ) );
            foreach ( $row['diff']['changed'] as $d )
                $cli->output( sprintf( '           changed %s: %s -> %s', $d['identifier'], $d['old_datatype'], $d['new_datatype'] ) );
        }
    }
    foreach ( $inspection['objects'] as $row )
    {
        if ( !in_array( $row['state'], array( 'create', 'update', 'class_missing' ), true ) )
            continue;
        $cli->output( sprintf( '  object %-14s %-24s %s', $row['state'], $row['class_identifier'], $row['name'] ) );
        foreach ( $row['field_changes'] as $change )
            $cli->output( sprintf( '           %s (%s): %s -> %s', $change['identifier'], $change['language'], $change['old'], $change['new'] ) );
    }
    $c = $inspection['counts'];
    $cli->output( sprintf( 'classes: %d create, %d update  |  objects: %d create, %d update, %d unchanged, %d class missing',
                           $c['classes_create'], $c['classes_update'], $c['objects_create'], $c['objects_update'], $c['objects_unchanged'], $c['objects_class_missing'] ) );
    $datatypeCheck( $inspection['missing_datatypes'] );
    $script->shutdown( 0 );
}

if ( $options['install'] )
{
    $package = eZPackage::fetch( $options['install'] );
    if ( !$package instanceof eZPackage )
        $fail( "No package '{$options['install']}' in the repository (--list shows them)." );
    if ( !$options['parent'] )
        $fail( 'Missing --parent (node id for the package\'s top-level objects).' );
    $parentNodeID = (int)$options['parent'];
    $parentNode = eZContentObjectTreeNode::fetch( $parentNodeID );
    if ( !$parentNode instanceof eZContentObjectTreeNode )
        $fail( "No node $parentNodeID (--parent)." );

    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 0, 2, 'inspecting' );

    if ( $options['dry-run'] )
    {
        $inspection = XrowExtractPackage::inspect( $package );
        $c = $inspection['counts'];
        $cli->output( sprintf( 'Dry run for %s: classes: %d create, %d update  |  objects: %d create, %d update, %d unchanged, %d class missing',
                               $package->attribute( 'name' ), $c['classes_create'], $c['classes_update'], $c['objects_create'], $c['objects_update'], $c['objects_unchanged'], $c['objects_class_missing'] ) );
        $datatypeCheck( $inspection['missing_datatypes'] );
        if ( $options['output'] )
            file_put_contents( (string)$options['output'], json_encode( array( 'ok' => true, 'action' => 'dry-run', 'inspection' => $inspection ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        if ( $options['progress-file'] )
            XrowExtractJob::writeProgress( (string)$options['progress-file'], 2, 2, 'done' );
        $script->shutdown( 0 );
    }

    $objectMode = $options['object-mode'] ? $options['object-mode'] : XrowExtractPackage::OBJECT_UPDATE;
    $classMode = $options['class-mode'] ? $options['class-mode'] : XrowExtractPackage::CLASS_SKIP;
    if ( !in_array( $objectMode, array( XrowExtractPackage::OBJECT_SKIP, XrowExtractPackage::OBJECT_UPDATE, XrowExtractPackage::OBJECT_NEW ), true ) )
        $fail( '--object-mode is skip, update or new.' );
    if ( !in_array( $classMode, array( XrowExtractPackage::CLASS_SKIP, XrowExtractPackage::CLASS_REPLACE, XrowExtractPackage::CLASS_NEW ), true ) )
        $fail( '--class-mode is skip, replace or new.' );
    $siteAccess = $options['site-access'] ? $options['site-access'] : eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' );

    // What this install is about to write: the Jobs page counts these in the database while the
    // kernel works (it reports nothing itself), so the job's progress is real, item by item
    $contents = XrowExtractPackage::packageContents( $package );
    $total = count( $contents['classes'] ) + count( $contents['objects'] );
    $cli->output( sprintf( '[%s] Installing %s below node %d (siteaccess %s): %d class(es), %d object(s); existing objects: %s, existing classes: %s',
                           date( 'H:i:s' ), $package->attribute( 'name' ), $parentNodeID, $siteAccess,
                           count( $contents['classes'] ), count( $contents['objects'] ), $objectMode, $classMode ) );
    // The dry run's own classification (create/update/unchanged/class missing), taken just before
    // installing: XrowExtractPackage::install()'s report only ever lists what it touched as
    // "created" (it looks every item up again afterwards, by remote id - it has no notion of
    // whether that item was new or already there), so this is the only place counts split that way
    // come from. bin/php/job.php reads it straight from the report for the Jobs page.
    $cli->output( sprintf( '[%s] Checking which classes and objects already exist ...', date( 'H:i:s' ) ) );
    $preInstallInspection = XrowExtractPackage::inspect( $package );
    $preInstallCounts = $preInstallInspection['counts'];
    $cli->output( sprintf( '[%s] To install: classes %d new, %d existing  |  objects %d new, %d existing, %d unchanged, %d with a missing class',
                           date( 'H:i:s' ), $preInstallCounts['classes_create'], $preInstallCounts['classes_update'],
                           $preInstallCounts['objects_create'], $preInstallCounts['objects_update'], $preInstallCounts['objects_unchanged'], $preInstallCounts['objects_class_missing'] ) );
    // The datatype check: installing goes ahead, but every datatype this site lacks is a WARNING line
    // (bin/php/job.php collects those for the Jobs page and the install history)
    $cli->output( sprintf( '[%s] Checking the datatypes the package uses against this site ...', date( 'H:i:s' ) ) );
    $missingDatatypes = $preInstallInspection['missing_datatypes'];
    $datatypeCheck( $missingDatatypes );
    unset( $preInstallInspection );

    if ( $options['progress-file'] )
    {
        $watchFile = dirname( (string)$options['progress-file'] ) . '/' . XrowExtractJob::INSTALL_WATCH_FILE;
        // One second back, so an object written in the very second the watch starts still counts
        file_put_contents( $watchFile, json_encode( array( 'started' => time() - 1, 'classes' => $contents['classes'], 'objects' => $contents['objects'] ) ) );
        XrowExtractJob::fixOwnership( $watchFile );
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 0, max( 1, $total ), 'installing' );
    }

    $installStarted = microtime( true );
    $report = XrowExtractPackage::install( $package, $parentNodeID, $siteAccess, $objectMode, $classMode, $user->attribute( 'contentobject_id' ) );
    // The site now differs from any cached dry run of this package
    XrowExtractPackage::forgetInspections( $package->attribute( 'name' ) );
    // What happened, in the terms of the dry run just before and the chosen handling of existing items
    // (install()'s own list names every item the package carries, whether it was written or left alone)
    $c = $preInstallCounts;
    $objectsExisting = $c['objects_update'] + $c['objects_unchanged'];
    $existingObjectsDid = array( 'skip' => 'left as they were', 'update' => 'updated', 'new' => 'added again as new copies' );
    $existingClassesDid = array( 'skip' => 'left as they were', 'replace' => 'replaced', 'new' => 'added again as new classes' );
    $cli->output( sprintf( '[%s] Done after %.1f s. Classes: %d created, %d already there - %s. Objects: %d created, %d already there - %s%s. %d error(s).',
                           date( 'H:i:s' ), microtime( true ) - $installStarted,
                           $c['classes_create'], $c['classes_update'], $existingClassesDid[$classMode],
                           $c['objects_create'], $objectsExisting, $existingObjectsDid[$objectMode],
                           $c['objects_class_missing'] ? sprintf( ', %d not installed (class missing)', $c['objects_class_missing'] ) : '',
                           count( $report['errors'] ) ) );
    foreach ( $report['errors'] as $error )
        $cli->error( '  ' . $error );
    // The first 50 of each by name; the whole list is in the job's report
    $listed = 0;
    foreach ( $report['created_classes'] as $row )
        if ( $listed++ < 50 )
            $cli->output( sprintf( '  class   %-30s #%d', $row['identifier'], $row['id'] ) );
    $listed = 0;
    foreach ( $report['created_objects'] as $row )
        if ( $listed++ < 50 )
            $cli->output( sprintf( '  object  %-30s #%d%s', $row['name'], $row['id'], $row['node_id'] ? ' node ' . $row['node_id'] : '' ) );
    if ( count( $report['created_objects'] ) > 50 )
        $cli->output( sprintf( '  ... and %d more object(s): see the job\'s report', count( $report['created_objects'] ) - 50 ) );
    $cli->output( $report['ok'] ? 'PASS installed' : 'FAIL install did not finish cleanly' );
    if ( $options['output'] )
    {
        file_put_contents( (string)$options['output'], json_encode( array(
            'ok' => (bool)$report['ok'], 'action' => 'install', 'report' => $report,
            'package_name' => $package->attribute( 'name' ), 'counts' => $preInstallCounts,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }
    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], max( 1, $total ), max( 1, $total ), 'done' );
    if ( $options['remove-after'] )
    {
        // Whether or not the install itself was clean: a job installing a transient package (a "Try
        // a sample"/"Start from a template" one the web view registered in the repository just for
        // this job's run) is the only caller that ever passes this, and that package has no business
        // staying in the repository either way - see modules/xrowextract/import.php.
        $stillThere = eZPackage::fetch( $package->attribute( 'name' ) );
        if ( $stillThere instanceof eZPackage )
            $stillThere->remove();
    }
    $script->shutdown( $report['ok'] ? 0 : 1 );
}

if ( $options['export'] )
{
    $exportFile = $options['file'] ? (string)$options['file'] : (string)$options['output'];
    if ( !$exportFile )
        $fail( 'Missing --file (or --output, set automatically for a background job).' );
    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 0, 3, 'collecting' );

    $nodeIDs = array();
    if ( $options['nodes'] )
    {
        foreach ( explode( ',', (string)$options['nodes'] ) as $piece )
            if ( ctype_digit( trim( $piece ) ) )
                $nodeIDs[] = (int)trim( $piece );
    }
    elseif ( $options['node'] )
    {
        $nodeIDs[] = (int)$options['node'];
    }
    else
    {
        $fail( 'Missing --node or --nodes.' );
    }
    $nodeIDs = array_values( array_unique( array_filter( $nodeIDs ) ) );
    $firstNode = null;
    foreach ( $nodeIDs as $nodeID )
    {
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        if ( !$node instanceof eZContentObjectTreeNode )
            $fail( "No node $nodeID (--node/--nodes)." );
        if ( $firstNode === null )
            $firstNode = $node;
    }

    $classID = false;
    $classIdentifier = false;
    if ( $options['class'] )
    {
        if ( $options['nodes'] )
            $fail( '--class only works with a single --node, not --nodes.' );
        $class = ctype_digit( (string)$options['class'] ) ? eZContentClass::fetch( (int)$options['class'] ) : eZContentClass::fetchByIdentifier( $options['class'] );
        if ( !$class instanceof eZContentClass )
            $fail( "No class {$options['class']} (--class)." );
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );
    }

    // Only names the kernel will import again (lowercase identifier form): see validPackageName()
    $packageName = XrowExtractPackage::validPackageName( $options['name'] ? $options['name']
                 : ( 'xrowextract_export_' . $firstNode->attribute( 'name' ) . '_' . $firstNode->attribute( 'node_id' ) ) );
    $summaryWhat = count( $nodeIDs ) > 1 ? ( count( $nodeIDs ) . ' selected nodes' ) : ( 'below node ' . $nodeIDs[0] . ' (' . $firstNode->attribute( 'name' ) . ')' );
    $package = eZPackage::create( $packageName, array( 'summary' => 'Exported ' . $summaryWhat, 'vendor' => 'xrowextract' ) );
    XrowExtractPackage::attachAboutDocument( $package, 'Exported by ext:xrowextract:package --export, ' . $summaryWhat . '.' );
    // eZPackage::packageHandler() reuses the SAME eZContentObjectPackageHandler instance for
    // every 'ezcontentobject' call in the process ($GLOBALS['eZPackageHandlers'], kernel/
    // classes/ezpackage.php) and its reset() is an inherited no-op (kernel/classes/
    // ezpackagehandler.php); on a long-running Velocity worker its public arrays would still
    // carry node/object ids an earlier, unrelated --export run added, and generatePackage()
    // only ever array_unique()s NodeIDArray, never clears it. This CLI command is normally
    // one-shot (a fresh process per run), but the background job path
    // (XrowExtractJob::writeProgress() above) can run it inside a longer-lived worker too, so
    // it is reset here the same way classes/xrowextractpackage.php resets it for the sample/
    // template builders.
    $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
    $objectHandler->NodeIDArray = array();
    $objectHandler->RootNodeIDArray = array();
    $objectHandler->NodeObjectArray = array();
    $objectHandler->ObjectArray = array();
    $objectHandler->RootNodeObjectArray = array();

    if ( $classID && $options['subtree'] )
    {
        // No node-level class filter exists in the kernel handler's own addNode()/
        // generateObjectArray() - every matching node in the subtree is collected here instead
        // (paged, no cap: "One class" export can be as large as the class itself) and each is
        // added on its own, non-recursively - addNode()'s own $isSubtree only means "this node
        // plus everything below it", nothing narrower.
        $offset = 0;
        $batch = 500;
        $matched = 0;
        do
        {
            $found = $firstNode->subTree( array(
                'ClassFilterType' => 'include', 'ClassFilterArray' => array( $classIdentifier ),
                'Offset' => $offset, 'Limit' => $batch, 'SortBy' => array( array( 'node_id', true ) ),
            ) );
            foreach ( (array)$found as $foundNode )
            {
                $objectHandler->addNode( (int)$foundNode->attribute( 'node_id' ), false );
                $matched++;
            }
            $offset += $batch;
            if ( $options['progress-file'] && $matched > 0 )
                XrowExtractJob::writeProgress( (string)$options['progress-file'], 0, 3, "collecting ($matched matched)" );
        }
        while ( count( (array)$found ) === $batch );
        // The class-filtered subtree walk above never revisits the root itself unless it is of
        // that class too - check it on its own so "the root is of this class" is not silently dropped
        if ( $firstNode->attribute( 'object' )->attribute( 'class_identifier' ) === $classIdentifier )
            $objectHandler->addNode( $nodeIDs[0], false );
    }
    elseif ( $classID )
    {
        // Not a subtree: the one node itself, only if it is that class
        if ( $firstNode->attribute( 'object' )->attribute( 'class_identifier' ) === $classIdentifier )
            $objectHandler->addNode( $nodeIDs[0], false );
    }
    else
    {
        foreach ( $nodeIDs as $nodeID )
            $objectHandler->addNode( $nodeID, (bool)$options['subtree'] );
    }

    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 1, 3, 'serializing' );
    $objectHandler->generatePackage( $package, array(
        'include_classes'   => true,
        'include_templates' => false,
        'site_access_array' => array(),
        'versions'          => 'current',
        'language_array'    => array_keys( XrowExtractColumns::contentLanguages() ),
        'node_assignment'   => 'selected',
        'related_objects'   => 'selected',
        'embed_objects'     => 'selected',
    ) );
    $package->setAttribute( 'is_active', true );
    $package->store();
    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 2, 3, 'archiving' );
    $exportPath = $package->exportToArchive( $exportFile );
    // Registered in the repository only with --keep - "Export as package" (One class/Site archive,
    // and this command run plainly) writes the .ezpkg file, nothing more, by default; the web views
    // never pass --keep, so every export they queue leaves the repository exactly as it was.
    if ( $options['keep'] )
    {
        if ( $options['progress-file'] )
            XrowExtractJob::writeProgress( (string)$options['progress-file'], 3, 3, 'done' );
        $cli->output( "PASS wrote $exportPath (package '{$package->attribute( 'name' )}', kept in the repository)" );
    }
    else
    {
        $package->remove();
        if ( $options['progress-file'] )
            XrowExtractJob::writeProgress( (string)$options['progress-file'], 3, 3, 'done' );
        $cli->output( "PASS wrote $exportPath (not registered in the repository; --keep to do that)" );
    }
    $script->shutdown( 0 );
}

if ( $options['template'] )
{
    if ( !$options['class'] )
        $fail( 'Missing --class.' );
    if ( !$options['file'] )
        $fail( 'Missing --file.' );
    $variant = $options['variant'] ? $options['variant'] : 'both';
    if ( !in_array( $variant, array( 'class', 'content', 'both' ), true ) )
        $fail( '--variant is class, content or both.' );
    $buildOptions = array();
    if ( $options['object-count'] )
        $buildOptions['object_count'] = (int)$options['object-count'];
    if ( $options['languages'] )
        $buildOptions['languages'] = array_filter( array_map( 'trim', explode( ',', $options['languages'] ) ) );

    if ( $variant !== 'class' )
    {
        $scratch = XrowExtractPackage::scratchLocationInfo();
        $cli->output( "Sample objects are created below {$scratch['path']} (node {$scratch['node_id']}), hidden the moment a folder is possible there - never the public front page (export.ini [PackageTemplate] ScratchNodeID)." );
    }
    $build = XrowExtractPackage::buildTemplatePackage( $options['class'], $variant, $buildOptions );
    foreach ( $build['errors'] as $error )
        $cli->error( '  ' . $error );
    if ( !$build['ok'] )
        $fail( 'Could not build the template package; see the errors above.' );
    $exportPath = $build['package']->exportToArchive( (string)$options['file'] );
    $cli->output( "PASS wrote $exportPath (package '{$build['package']->attribute( 'name' )}', variant $variant)" );
    $script->shutdown( 0 );
}

$fail( 'Nothing to do: pass one of --list, --inspect, --install, --export, --template, --compare, --clean (see --help).' );
