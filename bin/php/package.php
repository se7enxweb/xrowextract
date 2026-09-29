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
    '[list][inspect:][install:][export][template][clean][dry-run][parent:][site-access:][object-mode:][class-mode:]' .
    '[node:][nodes:][subtree][class:][variant:][object-count:][languages:][name:][file:][keep][user:][output:][progress-file:]',
    '',
    array(
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
    if ( $options['output'] )
        file_put_contents( (string)$options['output'], json_encode( array( 'ok' => true, 'action' => 'inspect', 'inspection' => $inspection ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
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

    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 1, 2, 'installing' );

    $report = XrowExtractPackage::install( $package, $parentNodeID, $siteAccess, $objectMode, $classMode, $user->attribute( 'contentobject_id' ) );
    foreach ( $report['errors'] as $error )
        $cli->error( '  ' . $error );
    foreach ( $report['created_classes'] as $row )
        $cli->output( sprintf( '  class   %-30s #%d', $row['identifier'], $row['id'] ) );
    foreach ( $report['created_objects'] as $row )
        $cli->output( sprintf( '  object  %-30s #%d%s', $row['name'], $row['id'], $row['node_id'] ? ' node ' . $row['node_id'] : '' ) );
    $cli->output( $report['ok'] ? 'PASS installed' : 'FAIL install did not finish cleanly' );
    if ( $options['output'] )
        file_put_contents( (string)$options['output'], json_encode( array( 'ok' => (bool)$report['ok'], 'action' => 'install', 'report' => $report ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    if ( $options['progress-file'] )
        XrowExtractJob::writeProgress( (string)$options['progress-file'], 2, 2, 'done' );
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

$fail( 'Nothing to do: pass one of --list, --inspect, --install, --export, --template, --clean (see --help).' );
