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
    '[list][inspect:][install:][export][template][parent:][dry-run][site-access:][object-mode:][class-mode:]' .
    '[node:][subtree][class:][variant:][object-count:][languages:][name:][file:][user:][output:][progress-file:]',
    '',
    array(
        'list'         => 'List the packages in the repository that carry a content class or content object',
        'inspect'      => 'Package name: show what it carries and what installing it would do (nothing is written)',
        'install'      => 'Package name: install it (through the same eZPackage::install() the web view uses)',
        'export'       => 'Export a node/subtree of live content as a new package (--node, optionally --subtree/--class)',
        'template'     => 'Build a sample content+class package for --class (--variant class/content/both, default both)',
        'parent'       => '--install: the parent node id for the package\'s own top-level objects',
        'dry-run'      => '--install: inspect only, do not write anything',
        'site-access'  => '--install: site access to map templates/overrides to (default: SiteSettings.DefaultAccess)',
        'object-mode'  => '--install: skip, update (default) or new, for an object that already exists (matched by remote id)',
        'class-mode'   => '--install: skip (default), replace or new, for a class that already exists (matched by remote id/identifier)',
        'node'         => '--export: the node id to export',
        'subtree'      => '--export: the whole subtree below --node, not only that node',
        'class'        => '--export: only this class below --node (id or identifier); --template: the class to build a sample for (required)',
        'variant'      => '--template: class, content or both (default both)',
        'object-count' => '--template: how many sample content objects to create (default 3, max 5)',
        'languages'    => '--template: comma list of locales for the sample content (default: up to 2 of the site\'s content languages)',
        'name'         => '--export: package name (default: a name derived from the node)',
        'file'         => '--export/--template: file to write the .ezpkg to (required)',
        'user'         => 'Run with the access rights of this login (default: admin)',
        'output'       => '--inspect/--install: also write a JSON report here (for a background job; see bin/php/job.php)',
        'progress-file' => '--install: write {"done":n,"total":m,"phase":"..."} to this path after each phase (for a background job)',
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
    if ( !$options['node'] )
        $fail( 'Missing --node.' );
    if ( !$options['file'] )
        $fail( 'Missing --file.' );
    $nodeID = (int)$options['node'];
    $node = eZContentObjectTreeNode::fetch( $nodeID );
    if ( !$node instanceof eZContentObjectTreeNode )
        $fail( "No node $nodeID (--node)." );
    $classID = false;
    if ( $options['class'] )
    {
        $class = ctype_digit( (string)$options['class'] ) ? eZContentClass::fetch( (int)$options['class'] ) : eZContentClass::fetchByIdentifier( $options['class'] );
        if ( !$class instanceof eZContentClass )
            $fail( "No class {$options['class']} (--class)." );
        $classID = (int)$class->attribute( 'id' );
    }

    $packageName = $options['name'] ? $options['name'] : ( 'xrowextract_export_' . preg_replace( '/[^A-Za-z0-9_]+/', '_', $node->attribute( 'name' ) ) . '_' . $nodeID );
    $package = eZPackage::create( $packageName, array( 'summary' => 'Exported below node ' . $nodeID . ' (' . $node->attribute( 'name' ) . ')', 'vendor' => 'xrowextract' ) );
    XrowExtractPackage::attachAboutDocument( $package, 'Exported by ext:xrowextract:package --export, below node ' . $nodeID . ' (' . $node->attribute( 'name' ) . ').' );
    $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
    $objectHandler->addNode( $nodeID, (bool)$options['subtree'] );
    $objectHandler->generatePackage( $package, array(
        'include_classes'   => true,
        'include_templates' => false,
        'site_access_array' => array(),
        'versions'          => 'current',
        'language_array'    => array_keys( XrowExtractColumns::contentLanguages() ),
        'node_assignment'   => $options['subtree'] ? 'selected' : 'selected',
        'related_objects'   => 'selected',
        'embed_objects'     => 'selected',
    ) );
    $package->setAttribute( 'is_active', true );
    $package->store();
    $exportPath = $package->exportToArchive( (string)$options['file'] );
    $cli->output( "PASS wrote $exportPath (package '{$package->attribute( 'name' )}')" );
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

$fail( 'Nothing to do: pass one of --list, --inspect, --install, --export, --template (see --help).' );
