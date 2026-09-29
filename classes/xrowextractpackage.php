<?php

/**
 * Content-package (.ezpkg) support for xrowextract/package: inspecting a
 * package's content classes and content objects before anything is written
 * (the dry run), installing it through the same kernel code path
 * package/install uses (eZPackage::install()), and building a rich sample
 * "content + class" template package for a chosen class through the kernel
 * package handlers (eZContentClassPackageHandler, eZContentObjectPackageHandler)
 * so the result is guaranteed to install the same way any other Exponential
 * package does.
 *
 * Nothing here re-implements package parsing: every read goes through
 * eZPackage's own DOM helpers, and every write goes through eZPackage,
 * eZContentClassPackageHandler and eZContentObjectPackageHandler, the same
 * classes kernel/package/{install,create,export}.php use.
 */
class XrowExtractPackage
{
    /** Content object existing-object handling: mirrors eZContentObject::PACKAGE_*. */
    const OBJECT_SKIP = 'skip';
    const OBJECT_UPDATE = 'update';
    const OBJECT_NEW = 'new';

    /** Content class existing-class handling: mirrors eZContentClassPackageHandler::ACTION_*. */
    const CLASS_SKIP = 'skip';
    const CLASS_REPLACE = 'replace';
    const CLASS_NEW = 'new';

    // ------------------------------------------------------------ upload (xrowextract/import)

    /**
     * What an uploaded file is, for the Import view to hand off to this class
     * instead of the row importer: 'package' (.ezpkg or .tar.gz - the same
     * gzip-compressed tar either way, only the extension differs),
     * 'contentclass' (a standalone content-class definition XML, the file
     * eZContentClassPackageHandler writes as one package install item), or
     * 'contentobject' (a standalone content-object XML, the file
     * eZContentObjectPackageHandler writes). False for anything else (a row
     * CSV/XML/JSON file, handled by XrowExtractImport as before).
     */
    public static function detectUploadKind( $storedPath, $originalName )
    {
        $lowerName = strtolower( (string)$originalName );
        if ( self::endsWith( $lowerName, '.ezpkg' ) || self::endsWith( $lowerName, '.tar.gz' ) || self::endsWith( $lowerName, '.tgz' ) )
            return 'package';

        $head = @file_get_contents( $storedPath, false, null, 0, 4 );
        if ( $head !== false && strlen( $head ) >= 2 && $head[0] === "\x1f" && $head[1] === "\x8b" )
            return 'package'; // gzip magic bytes, whatever the extension

        if ( self::endsWith( $lowerName, '.xml' ) )
        {
            // A peek at the root element, not a full parse: cheap, and safe on a huge file.
            $sample = @file_get_contents( $storedPath, false, null, 0, 2048 );
            if ( $sample !== false )
            {
                $sample = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', $sample ) );
                if ( preg_match( '/^<\?xml[^>]*>\s*/', $sample, $m ) )
                    $sample = substr( $sample, strlen( $m[0] ) );
                if ( strpos( $sample, '<content-class' ) === 0 )
                    return 'contentclass';
                if ( strpos( $sample, '<content-object' ) === 0 )
                    return 'contentobject';
            }
        }
        return false;
    }

    protected static function endsWith( $haystack, $needle )
    {
        $len = strlen( $needle );
        return $len === 0 || substr( $haystack, -$len ) === $needle;
    }

    /**
     * Lists every entry a gzip-compressed tar (.ezpkg or .tar.gz) carries,
     * refusing one with an absolute path, a ".." component or a symlink
     * anywhere in it, before the archive is handed to eZPackage::import()
     * (which extracts it for real). Reads through PHP's Phar/PharData
     * (streaming: entries are listed without loading the archive into
     * memory), not eZ's own ezcArchive, specifically so this check runs
     * first and independently of how the kernel itself later extracts it.
     */
    public static function scanArchiveEntries( $path )
    {
        $real = realpath( $path );
        if ( $real === false || !is_file( $real ) )
            return array( 'ok' => false, 'entries' => array(), 'error' => 'no such file' );

        // Listed with the system tar binary (verbose: type letter, owner/group, size, date,
        // time, name - a symlink's name ends " -> target"), not PHP's own Phar/PharData:
        // tried first, but PharFileInfo::isLink() turned out not to recognise a tar symlink
        // entry as one at all in this PHP build (it was silently listed as a plain file),
        // where GNU tar's own listing reliably shows the leading "l". This also sidesteps
        // needing the phar:// stream wrapper, which autoload.php unregisters on every
        // request/command on purpose. No shell is involved (proc_open with an argument
        // array), so nothing in the archive's own file name ever reaches a shell.
        $descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
        $process = @proc_open( array( 'tar', '-tvzf', $real ), $descriptors, $pipes, null, null, array( 'bypass_shell' => true ) );
        if ( !is_resource( $process ) )
            return array( 'ok' => false, 'entries' => array(), 'error' => 'could not run tar to read the archive' );
        $out = (string)stream_get_contents( $pipes[1] );
        $err = (string)stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $exitCode = proc_close( $process );
        if ( $exitCode !== 0 )
            return array( 'ok' => false, 'entries' => array(), 'error' => 'not a valid .ezpkg/.tar.gz archive' . ( trim( $err ) !== '' ? ': ' . trim( $err ) : '' ) );

        $entries = array();
        $error = null;
        foreach ( preg_split( '/\r?\n/', trim( $out ) ) as $line )
        {
            if ( $line === '' )
                continue;
            // "<type+perm> <owner>/<group> <size> <date> <time> <name>[ -> <target>]"
            // Fail closed: a line this cannot read refuses the archive rather than being skipped
            if ( !preg_match( '/^(.)\S*\s+\S+\s+\S+\s+\S+\s+\S+\s+(.*)$/', $line, $m ) )
            {
                $error = 'unreadable archive listing line refused';
                break;
            }
            $type = $m[1];
            $name = $m[2];
            if ( $type === 'l' )
            {
                $error = 'symlink entry refused: ' . preg_replace( '/\s*->.*/', '', $name );
                break;
            }
            // Only plain files and folders: a hard link ("h", "... link to <target>") can point at a file
            // outside the package, and device, fifo or socket entries have no place in a package at all
            if ( $type !== '-' && $type !== 'd' )
            {
                $error = ( $type === 'h' ? 'hard link' : "special ($type)" ) . ' entry refused: '
                       . preg_replace( '/\s+link to .*$/', '', $name );
                break;
            }
            $relative = rtrim( $name, '/' );
            if ( $relative === '' || $relative[0] === '/' || preg_match( '#(^|/)\.\.(/|$)#', $relative ) )
            {
                $error = "unsafe entry path refused: $relative";
                break;
            }
            $entries[] = $relative;
        }

        return array( 'ok' => $error === null, 'entries' => $entries, 'error' => $error );
    }

    /**
     * Imports an uploaded .ezpkg/.tar.gz into the local package repository,
     * after scanArchiveEntries() has refused a path-traversal/symlink
     * archive. Returns array( 'ok', 'package' => eZPackage|null, 'error' ).
     */
    public static function importUploadedArchive( $storedPath )
    {
        // An absolute path: the kernel opens the archive as "compress.zlib://<path>", and a relative
        // one (var/site/cache/...) cannot be opened that way ("can not be opened for reading")
        $real = realpath( (string)$storedPath );
        if ( $real !== false )
            $storedPath = $real;
        $scan = self::scanArchiveEntries( $storedPath );
        if ( !$scan['ok'] )
            return array( 'ok' => false, 'package' => null, 'error' => $scan['error'] );

        $packageName = '';
        try
        {
            // Repository forced to 'local', not left to eZPackage::import()'s own default:
            // with none given, it derives the repository from the archive's own <vendor>
            // (kernel/classes/ezpackage.php, "vendor-dir"), so a package whose builder set a
            // vendor - every package this extension builds does ('xrowextract') - lands
            // under var/storage/packages/xrowextract/ instead of .../local/, where
            // eZPackage::create() (and this class's own uniquePackageName() existence check)
            // always puts a locally built package. Not wrong on its own, but the mismatch
            // meant a package built here and later re-uploaded here could never collide with
            // its own earlier self by name, and a fetch that assumes 'local' (as
            // uniquePackageName() does) would miss it entirely.
            //
            // A well-formed archive with a nonsensical package.xml (missing elements the
            // kernel's own parser assumes are there, e.g. a bare <package/>) makes
            // eZPackage::import() throw a PHP 8 TypeError deep inside kernel/classes/
            // ezpackage.php (getElementsByTagName() on null), not return false - caught
            // here so a malformed upload is refused cleanly instead of a fatal error page.
            $imported = eZPackage::import( $storedPath, $packageName, true, 'local', false );
        }
        catch ( \Throwable $e )
        {
            return array( 'ok' => false, 'package' => null, 'error' => 'not a valid Exponential package (.ezpkg): ' . $e->getMessage() );
        }
        if ( $imported instanceof eZPackage )
            return array( 'ok' => true, 'package' => $imported, 'error' => null );
        if ( $imported === eZPackage::STATUS_ALREADY_EXISTS )
            return array( 'ok' => false, 'package' => null, 'error' => "a package named '$packageName' already exists in the repository" );
        if ( $imported === eZPackage::STATUS_INVALID_NAME )
            return array( 'ok' => false, 'package' => null, 'error' => "the package name '$packageName' is invalid" );
        return array( 'ok' => false, 'package' => null, 'error' => 'not a valid Exponential package (.ezpkg)' );
    }

    /**
     * Builds a transient, local package wrapping one standalone content-class
     * or content-object XML file (as detectUploadKind() found), so it can be
     * inspected and installed through the exact same eZPackage/
     * XrowExtractPackage::inspect()/install() code as a full .ezpkg - "build
     * a transient package around it", per the class/content XML upload
     * requirement. Returns array( 'ok', 'package', 'error' ).
     */
    public static function wrapStandaloneXML( $storedPath, $kind, $originalName = '' )
    {
        if ( !in_array( $kind, array( 'contentclass', 'contentobject' ), true ) )
            return array( 'ok' => false, 'package' => null, 'error' => 'unknown file kind' );

        $dom = new DOMDocument( '1.0', 'utf-8' );
        $dom->preserveWhiteSpace = false;
        libxml_use_internal_errors( true );
        // Refuse a DOCTYPE outright: never written by this tool's own exports, and the classic
        // way to smuggle in external entities (matches the row-XML importer's own rule).
        $loaded = false;
        $raw = @file_get_contents( $storedPath );
        if ( $raw !== false && stripos( $raw, '<!doctype' ) === false )
            $loaded = $dom->loadXML( $raw, LIBXML_NONET );
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        if ( !$loaded || !$dom->documentElement )
        {
            $message = $xmlErrors ? trim( $xmlErrors[0]->message ) : 'could not be read as XML';
            return array( 'ok' => false, 'package' => null, 'error' => $message );
        }

        $base = preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $originalName !== '' ? pathinfo( $originalName, PATHINFO_FILENAME ) : $kind );
        $packageName = self::uniquePackageName( 'xrowextract_upload_' . $base );
        $package = eZPackage::create( $packageName, array(
            'summary' => 'Uploaded on xrowextract/import: a standalone ' . ( $kind === 'contentclass' ? 'content class' : 'content object' ) . ' file.',
            'vendor'  => 'xrowextract',
        ) );
        self::attachAboutDocument( $package, 'Wraps one uploaded ' . ( $kind === 'contentclass' ? 'content-class' : 'content-object' ) . " file (originally $originalName) in a package of its own, for xrowextract/import to inspect and install." );

        $type = $kind === 'contentclass' ? 'ezcontentclass' : 'ezcontentobject';
        $subdirectory = $type;
        $filename = 'upload';
        $package->appendInstall( $type, false, false, true, $filename, $subdirectory, array( 'content' => $dom->documentElement ) );
        $package->appendInstall( $type, false, false, false, $filename, $subdirectory, array( 'content' => false ) );
        $package->setAttribute( 'is_active', true );
        $package->store();

        return array( 'ok' => true, 'package' => $package, 'error' => null );
    }

    // ------------------------------------------------------------ listing

    /** Packages in the repository that carry a content class or content object install item. */
    public static function repositoryPackages()
    {
        $out = array();
        foreach ( eZPackage::fetchPackages() as $package )
        {
            $types = self::installItemTypes( $package );
            if ( !in_array( 'ezcontentclass', $types, true ) && !in_array( 'ezcontentobject', $types, true ) )
                continue;
            $out[] = array(
                'name'          => $package->attribute( 'name' ),
                'summary'       => (string)$package->attribute( 'summary' ),
                'version'       => (string)$package->attribute( 'version-number' ),
                'is_installed'  => (bool)$package->attribute( 'is_installed' ),
                'install_type'  => (string)$package->attribute( 'install_type' ),
                'has_classes'   => in_array( 'ezcontentclass', $types, true ),
                'has_objects'   => in_array( 'ezcontentobject', $types, true ),
                'class_count'   => self::countItems( $package, 'ezcontentclass' ),
                'object_item_count' => self::countItems( $package, 'ezcontentobject' ),
            );
        }
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return $out;
    }

    protected static function installItemTypes( eZPackage $package )
    {
        $types = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) )
                $types[$item['type']] = true;
        return array_keys( $types );
    }

    protected static function countItems( eZPackage $package, $type )
    {
        return count( self::installItemsOfType( $package, $type ) );
    }

    /**
     * Every install item of one type. Not eZPackage::installItemsList($type):
     * called with only a type and no $os, its "found" fallback treats every
     * item as a match once the type check fails, so it silently returns the
     * whole install list instead of filtering - see the kernel bug note in
     * this extension's port docs (installItemsList( 'ezcontentclass' ) was
     * observed to also return the package's 'ezcontentobject' item).
     */
    protected static function installItemsOfType( eZPackage $package, $type )
    {
        $matches = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) && $item['type'] === $type )
                $matches[] = $item;
        return $matches;
    }

    // ------------------------------------------------------------ metadata

    /** Package-level metadata for the inspect screen: name, summary, version, licence, dependencies, changelog. */
    public static function packageMeta( eZPackage $package )
    {
        $changelog = array();
        foreach ( (array)$package->attribute( 'changelog' ) as $entry )
        {
            $changelog[] = array(
                'person'    => isset( $entry['person'] ) ? $entry['person'] : '',
                'timestamp' => isset( $entry['timestamp'] ) ? (int)$entry['timestamp'] : 0,
                'changes'   => isset( $entry['changes'] ) ? (array)$entry['changes'] : array(),
            );
        }
        $dependencies = array();
        $depData = (array)$package->attribute( 'dependencies' );
        foreach ( array( 'requires', 'provides', 'obsoletes', 'conflicts' ) as $section )
        {
            foreach ( (array)( isset( $depData[$section] ) ? $depData[$section] : array() ) as $dep )
                $dependencies[] = array( 'section' => $section, 'type' => isset( $dep['type'] ) ? $dep['type'] : '',
                                         'name' => isset( $dep['name'] ) ? $dep['name'] : '', 'value' => isset( $dep['value'] ) ? $dep['value'] : '' );
        }
        return array(
            'name'          => $package->attribute( 'name' ),
            'summary'       => (string)$package->attribute( 'summary' ),
            'description'   => (string)$package->attribute( 'description' ),
            'version'       => (string)$package->attribute( 'version-number' ),
            'release'       => (string)$package->attribute( 'release-number' ),
            'licence'       => (string)$package->attribute( 'licence' ),
            'vendor'        => (string)$package->attribute( 'vendor' ),
            'install_type'  => (string)$package->attribute( 'install_type' ),
            'is_installed'  => (bool)$package->attribute( 'is_installed' ),
            'maintainers'   => (array)$package->attribute( 'maintainers' ),
            'dependencies'  => $dependencies,
            'changelog'     => $changelog,
        );
    }

    // ------------------------------------------------------------ inspect (dry run)

    /**
     * The dry run: every content class and content object install item, matched
     * against what already exists on this site by remote id (classes fall back
     * to identifier). Nothing is written. $parentNodeID (the parent chosen in
     * the install form, when known) is only used to describe where a new
     * top-level object would land - it changes nothing about matching.
     */
    public static function inspect( eZPackage $package, $parentNodeID = false )
    {
        // eZContentObject::fetch()/fetchDataMap() keep their results in
        // $GLOBALS['eZContentObjectContentObjectCache']/[...DataMapCache], populated once and
        // never refreshed on their own (kernel/classes/ezcontentobject.php). On a short-lived
        // FPM/CLI request that is invisible; on a long-running Velocity worker, an object
        // touched by an earlier, unrelated request in the same process (another dry run, a real
        // apply, an admin edit served by the same worker) can still be sitting in there, so
        // objectFieldChanges()'s "old" (live) value can be one that no longer matches the
        // database - the exact way a genuine old -> new difference silently disappears.
        // Cleared here, once per dry run, so every match against "what already exists on this
        // site" (this method's own contract, see the class comment above) reads the database,
        // not a previous request's leftovers.
        eZContentObject::clearCache();
        $classes = array();
        $objects = array();
        $errors = array();
        $classRemoteIDsInPackage = array();
        $classIdentifiersInPackage = array();

        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::inspectClassItem( $package, $item );
            if ( $row === null )
            {
                $errors[] = 'Could not read a content class item from the package (' . ( isset( $item['filename'] ) ? $item['filename'] : '?' ) . ').';
                continue;
            }
            $classes[] = $row;
            if ( $row['remote_id'] )
                $classRemoteIDsInPackage[$row['remote_id']] = true;
            if ( $row['identifier'] )
                $classIdentifiersInPackage[$row['identifier']] = true;
        }

        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
        {
            foreach ( self::inspectObjectItem( $package, $item, $classRemoteIDsInPackage, $classIdentifiersInPackage, $parentNodeID ) as $row )
                $objects[] = $row;
        }

        $classIdentifiers = array();
        foreach ( $classes as $c )
            if ( $c['identifier'] )
                $classIdentifiers[$c['identifier']] = true;
        foreach ( $objects as $o )
            if ( $o['class_identifier'] )
                $classIdentifiers[$o['class_identifier']] = true;
        $languagesUsed = array();
        foreach ( $objects as $o )
            foreach ( $o['languages'] as $l )
                $languagesUsed[$l] = true;
        $parentInfo = null;
        if ( $parentNodeID )
        {
            $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
            if ( $parentNode instanceof eZContentObjectTreeNode )
                $parentInfo = array( 'node_id' => (int)$parentNodeID, 'path' => $parentNode->attribute( 'path_identification_string' ) );
        }

        return array(
            'meta'    => self::packageMeta( $package ),
            'classes' => $classes,
            'objects' => $objects,
            'errors'  => $errors,
            'files'   => self::packageFiles( $package ),
            'summary' => array(
                'classes'            => array_keys( $classIdentifiers ),
                'new_objects_parent' => $parentInfo,
                'languages'          => array_keys( $languagesUsed ),
                'match_mode'         => 'remote id',
            ),
            'counts'  => array(
                'classes_create' => count( array_filter( $classes, function ( $c ) { return $c['state'] === 'create'; } ) ),
                'classes_update' => count( array_filter( $classes, function ( $c ) { return $c['state'] === 'update'; } ) ),
                'objects_create' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'create'; } ) ),
                'objects_update' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'update'; } ) ),
                'objects_unchanged' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'unchanged'; } ) ),
                'objects_class_missing' => count( array_filter( $objects, function ( $o ) { return $o['state'] === 'class_missing'; } ) ),
            ),
        );
    }

    /**
     * Reshapes inspect()'s output into exactly the array XrowExtractImport::run()
     * returns ('rows' with number/action/object_name/object_id/node_id/
     * class_identifier/changes/reason, plus 'counts'), so a package's dry run
     * renders through the same design:xrowextract/import_result.tpl as an
     * XML/CSV/JSON row import - "just like json, csv, xml", per the owner. One
     * row per content-class item (action 'create'/'update', its attribute diff
     * as 'changes'), then one row per content-object item ('class_missing' maps
     * to 'error', its reason explaining why; field-level changes as 'changes').
     */
    public static function inspectionToResultRows( array $inspection )
    {
        $rows = array();
        $number = 0;
        // A class's own row never buckets under $ResultClasses (import.php only buckets rows that
        // carry a class_id): class_identifier is still shown on the row itself, just not counted as
        // one of "the classes rows import into" the way an object row is.
        foreach ( $inspection['classes'] as $classRow )
        {
            $number++;
            $changes = array();
            if ( $classRow['diff'] )
            {
                foreach ( $classRow['diff']['added'] as $added )
                    $changes[] = array( 'field' => $added['identifier'], 'old' => '', 'new' => $added['datatype'] . ' (' . ezpI18n::tr( 'design/standard/extract', 'new attribute' ) . ')' );
                foreach ( $classRow['diff']['removed'] as $removed )
                    $changes[] = array( 'field' => $removed['identifier'], 'old' => $removed['datatype'] . ' (' . ezpI18n::tr( 'design/standard/extract', 'removed' ) . ')', 'new' => '' );
                foreach ( $classRow['diff']['changed'] as $changed )
                    $changes[] = array( 'field' => $changed['identifier'], 'old' => $changed['old_datatype'], 'new' => $changed['new_datatype'] );
            }
            $rows[] = array(
                'number' => $number,
                'action' => $classRow['state'],
                'object_name' => ezpI18n::tr( 'design/standard/extract', 'Class: %name', null, array( '%name' => $classRow['name'] !== '' ? $classRow['name'] : $classRow['identifier'] ) ),
                'object_id' => $classRow['existing_id'],
                'node_id' => null,
                'class_id' => null,
                'class_identifier' => $classRow['identifier'],
                'class_name' => $classRow['name'],
                'changes' => $changes,
                'reason' => '',
            );
        }
        $classIDCache = array();
        foreach ( $inspection['objects'] as $objectRow )
        {
            $number++;
            $action = $objectRow['state'] === 'class_missing' ? 'error' : $objectRow['state'];
            $changes = array();
            foreach ( $objectRow['field_changes'] as $fieldChange )
                $changes[] = array( 'field' => $fieldChange['identifier'], 'old' => $fieldChange['old'], 'new' => $fieldChange['new'] );
            $identifier = $objectRow['class_identifier'];
            if ( $identifier !== '' && !array_key_exists( $identifier, $classIDCache ) )
            {
                $liveClass = eZContentClass::fetchByIdentifier( $identifier );
                $classIDCache[$identifier] = $liveClass instanceof eZContentClass ? array( (int)$liveClass->attribute( 'id' ), $liveClass->attribute( 'name' ) ) : array( null, $identifier );
            }
            $rows[] = array(
                'number' => $number,
                'action' => $action,
                'object_name' => $objectRow['name'],
                'object_id' => $objectRow['existing_id'],
                'node_id' => $objectRow['placement'] ? $objectRow['placement']['node_id'] : null,
                'class_id' => $identifier !== '' ? $classIDCache[$identifier][0] : null,
                'class_identifier' => $identifier,
                'class_name' => $identifier !== '' ? $classIDCache[$identifier][1] : '',
                'changes' => $changes,
                'reason' => $objectRow['state'] === 'class_missing'
                          ? ezpI18n::tr( 'design/standard/extract', 'Class %class does not exist on this site.', null, array( '%class' => $objectRow['class_identifier'] ) )
                          : '',
            );
        }
        $counts = array( 'create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'skip' => 0 );
        foreach ( $rows as $row )
            if ( isset( $counts[$row['action']] ) )
                $counts[$row['action']]++;
        return array( 'rows' => $rows, 'counts' => $counts, 'ezoe' => true );
    }

    /** Every file a package carries outside its XML install items (simplefiles/, images/), with sizes. */
    public static function packageFiles( eZPackage $package )
    {
        $out = array();
        $base = $package->path();
        foreach ( array( 'simplefiles', 'images' ) as $sub )
        {
            $dir = $base . '/' . $sub;
            if ( !is_dir( $dir ) )
                continue;
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $iterator as $file )
            {
                if ( !$file->isFile() )
                    continue;
                $relative = ltrim( str_replace( $dir, '', $file->getPathname() ), '/' );
                $out[] = array( 'path' => $sub . '/' . $relative, 'size' => $file->getSize() );
            }
        }
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['path'], $b['path'] ); } );
        return $out;
    }

    protected static function inspectClassItem( eZPackage $package, array $item )
    {
        if ( empty( $item['filename'] ) )
            return null;
        $dom = self::fetchItemDOM( $package, $item );
        if ( !$dom )
            return null;
        $content = $dom;
        $identifierNode = $content->getElementsByTagName( 'identifier' )->item( 0 );
        $identifier = $identifierNode ? $identifierNode->textContent : '';
        $remoteIDNode = $content->getElementsByTagName( 'remote-id' )->item( 0 );
        $remoteID = $remoteIDNode ? $remoteIDNode->textContent : '';
        $name = '';
        $nameNode = $content->getElementsByTagName( 'name' )->item( 0 );
        if ( $nameNode )
            $name = $nameNode->textContent;
        else
        {
            $serializedNameListNode = $content->getElementsByTagName( 'serialized-name-list' )->item( 0 );
            if ( $serializedNameListNode && class_exists( 'eZContentClassNameList' ) )
            {
                $nameList = new eZContentClassNameList( $serializedNameListNode->textContent );
                $name = $nameList->name();
            }
        }
        $attributesNode = $content->getElementsByTagName( 'attributes' )->item( 0 );
        $attributeRows = array();
        if ( $attributesNode )
        {
            foreach ( $attributesNode->getElementsByTagName( 'attribute' ) as $attrNode )
            {
                $attrIdentifierNode = $attrNode->getElementsByTagName( 'identifier' )->item( 0 );
                $attrTypeNode = $attrNode->getElementsByTagName( 'type' )->item( 0 );
                $attributeRows[] = array(
                    'identifier' => $attrIdentifierNode ? $attrIdentifierNode->textContent : '',
                    'datatype'   => $attrTypeNode ? $attrTypeNode->textContent : '',
                    'required'   => $attrNode->getAttribute( 'required' ) === 'true',
                );
            }
        }

        $existing = $remoteID ? eZContentClass::fetchByRemoteID( $remoteID ) : null;
        if ( !$existing && $identifier )
            $existing = eZContentClass::fetchByIdentifier( $identifier );
        $state = $existing instanceof eZContentClass ? 'update' : 'create';

        return array(
            'identifier'  => $identifier,
            'remote_id'   => $remoteID,
            'name'        => $name,
            'state'       => $state,
            'existing_id' => $existing instanceof eZContentClass ? (int)$existing->attribute( 'id' ) : null,
            'attributes'  => $attributeRows,
            'attribute_count' => count( $attributeRows ),
            'diff'        => $existing instanceof eZContentClass ? self::classAttributeDiff( $attributeRows, $existing ) : null,
        );
    }

    /** Attributes added, removed or changed datatype, package vs. the installed class of the same identifier/remote id. */
    protected static function classAttributeDiff( array $packageAttributeRows, eZContentClass $existingClass )
    {
        $existingRows = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$existingClass->attribute( 'id' ), eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
            $existingRows[$attribute->attribute( 'identifier' )] = $attribute->attribute( 'data_type_string' );

        $packageRows = array();
        foreach ( $packageAttributeRows as $row )
            if ( $row['identifier'] !== '' )
                $packageRows[$row['identifier']] = $row['datatype'];

        $added = array();
        $changed = array();
        foreach ( $packageRows as $identifier => $datatype )
        {
            if ( !isset( $existingRows[$identifier] ) )
                $added[] = array( 'identifier' => $identifier, 'datatype' => $datatype );
            elseif ( $existingRows[$identifier] !== $datatype )
                $changed[] = array( 'identifier' => $identifier, 'old_datatype' => $existingRows[$identifier], 'new_datatype' => $datatype );
        }
        $removed = array();
        foreach ( $existingRows as $identifier => $datatype )
            if ( !isset( $packageRows[$identifier] ) )
                $removed[] = array( 'identifier' => $identifier, 'datatype' => $datatype );

        return array( 'added' => $added, 'removed' => $removed, 'changed' => $changed,
                      'has_changes' => (bool)( $added || $removed || $changed ) );
    }

    /** One install item can carry many content objects (inline or one XML file per object). */
    protected static function inspectObjectItem( eZPackage $package, array $item, array $classRemoteIDsInPackage, array $classIdentifiersInPackage, $parentNodeID = false )
    {
        $rows = array();
        foreach ( self::objectDOMNodes( $package, $item ) as $objectNode )
        {
            $name = $objectNode->getAttribute( 'name' );
            $remoteID = $objectNode->getAttribute( 'remote_id' );
            $classRemoteID = $objectNode->getAttribute( 'class_remote_id' );
            $classIdentifier = $objectNode->getAttributeNS( 'http://ez.no/ezobject', 'class_identifier' );
            $modifiedText = $objectNode->getAttributeNS( 'http://ez.no/ezobject', 'modified' );

            $languages = array();
            $versionListNode = $objectNode->getElementsByTagName( 'version-list' )->item( 0 );
            if ( $versionListNode )
            {
                foreach ( $versionListNode->getElementsByTagName( 'object-translation' ) as $translationNode )
                {
                    $lang = $translationNode->getAttribute( 'language' );
                    if ( $lang && !in_array( $lang, $languages, true ) )
                        $languages[] = $lang;
                }
            }

            $classExists = ( $classRemoteID && eZContentClass::fetchByRemoteID( $classRemoteID ) )
                         || ( $classIdentifier && eZContentClass::fetchByIdentifier( $classIdentifier ) )
                         || ( $classRemoteID && isset( $classRemoteIDsInPackage[$classRemoteID] ) )
                         || ( $classIdentifier && isset( $classIdentifiersInPackage[$classIdentifier] ) );

            $existing = $remoteID ? eZContentObject::fetchByRemoteID( $remoteID ) : null;
            if ( !$classExists )
                $state = 'class_missing';
            elseif ( !$existing instanceof eZContentObject )
                $state = 'create';
            else
            {
                $state = 'update';
                if ( $modifiedText !== '' && class_exists( 'eZDateUtils' ) )
                {
                    $packageModified = eZDateUtils::textToDate( $modifiedText );
                    if ( $packageModified && (int)$packageModified === (int)$existing->attribute( 'modified' ) )
                        $state = 'unchanged';
                }
            }

            $existingInfo = null;
            $existingNode = null;
            if ( $existing instanceof eZContentObject )
            {
                $existingNode = $existing->attribute( 'main_node' );
                $existingInfo = array(
                    'id'    => (int)$existing->attribute( 'id' ),
                    'name'  => $existing->name(),
                    'class_identifier' => $existing->attribute( 'class_identifier' ),
                    'node_id' => $existingNode instanceof eZContentObjectTreeNode ? (int)$existingNode->attribute( 'node_id' ) : null,
                    'path'    => $existingNode instanceof eZContentObjectTreeNode ? $existingNode->attribute( 'path_identification_string' ) : null,
                );
            }

            $placement = null;
            if ( $state === 'create' && $parentNodeID )
            {
                $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
                if ( $parentNode instanceof eZContentObjectTreeNode )
                    $placement = array( 'node_id' => (int)$parentNodeID, 'path' => $parentNode->attribute( 'path_identification_string' ), 'reason' => 'new' );
            }
            elseif ( in_array( $state, array( 'update', 'unchanged' ), true ) && $existingInfo && $existingInfo['node_id'] )
            {
                $placement = array( 'node_id' => $existingInfo['node_id'], 'path' => $existingInfo['path'], 'reason' => 'current' );
            }

            $fieldChanges = ( $state === 'update' && $existing instanceof eZContentObject )
                          ? self::objectFieldChanges( $objectNode, $existing )
                          : array();

            $rows[] = array(
                'name'             => $name,
                'remote_id'        => $remoteID,
                'class_identifier' => $classIdentifier,
                'languages'        => $languages,
                'state'            => $state,
                'existing_id'      => $existing instanceof eZContentObject ? (int)$existing->attribute( 'id' ) : null,
                'existing'         => $existingInfo,
                'placement'        => $placement,
                'field_changes'    => $fieldChanges,
            );
        }
        return $rows;
    }

    /**
     * The class attribute datatypes this class can compare old (live) vs new
     * (package) value for by reading the exact field
     * eZDataType::serializeContentObjectAttribute()'s default implementation
     * writes for a datatype with no custom serializer (kernel/classes/
     * ezdatatype.php): identifier => array( eZContentObjectAttribute field
     * to read the live value from, package XML child element name to read
     * the new value from ). A datatype not listed here (ezxmltext, ezimage,
     * ezobjectrelation(list), ezselection, eztags, ezdate(time)... - each
     * has its own custom serializer) is not compared field by field; the
     * object as a whole is still reported as 'update'.
     */
    protected static function diffableAttributeFields()
    {
        return array(
            'ezstring'     => array( 'source' => 'data_text',  'xml' => 'text' ),
            'eztext'       => array( 'source' => 'data_text',  'xml' => 'text' ),
            'ezinteger'    => array( 'source' => 'data_int',   'xml' => 'value' ),
            'ezfloat'      => array( 'source' => 'data_float', 'xml' => 'value' ),
            'ezboolean'    => array( 'source' => 'data_int',   'xml' => 'value', 'bool' => true ),
            'ezemail'      => array( 'source' => 'data_text',  'xml' => 'email' ),
            'ezidentifier' => array( 'source' => 'data_text',  'xml' => 'identifier' ),
        );
    }

    /** Old (live) -> new (package) value per attribute, for the datatypes diffableAttributeFields() covers; only attributes that actually differ are returned. */
    protected static function objectFieldChanges( DOMElement $objectNode, eZContentObject $existing )
    {
        $changes = array();
        $map = self::diffableAttributeFields();
        $versionListNode = $objectNode->getElementsByTagName( 'version-list' )->item( 0 );
        if ( !$versionListNode )
            return $changes;
        $seen = array();
        foreach ( $versionListNode->getElementsByTagName( 'object-translation' ) as $translationNode )
        {
            $language = $translationNode->getAttribute( 'language' );
            $dataMap = $existing->fetchDataMap( false, $language ?: false );
            foreach ( $translationNode->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' ) as $attrNode )
            {
                $identifier = $attrNode->getAttributeNS( 'http://ez.no/ezobject', 'identifier' );
                $datatype = $attrNode->getAttribute( 'type' );
                if ( $identifier === '' || !isset( $map[$datatype] ) || !isset( $dataMap[$identifier] ) )
                    continue;
                $spec = $map[$datatype];
                $childNode = $attrNode->getElementsByTagName( $spec['xml'] )->item( 0 );
                $newRaw = $childNode ? $childNode->textContent : '';
                $oldRaw = (string)$dataMap[$identifier]->attribute( $spec['source'] );
                $old = !empty( $spec['bool'] ) ? ( $oldRaw ? 'yes' : 'no' ) : $oldRaw;
                $new = !empty( $spec['bool'] ) ? ( $newRaw ? 'yes' : 'no' ) : $newRaw;
                $key = $identifier . '|' . $language;
                if ( $old !== $new && !isset( $seen[$key] ) )
                {
                    $seen[$key] = true;
                    $changes[] = array( 'identifier' => $identifier, 'datatype' => $datatype, 'language' => $language, 'old' => $old, 'new' => $new );
                }
            }
        }
        return $changes;
    }

    /**
     * For buildSamplePackage()'s 'update' demo object: edits the first attribute value
     * objectFieldChanges() knows how to compare (see diffableAttributeFields()), so the
     * dry run's field-level old -> new has something real to show, not only the object's
     * outer name/modified date. A class with none of those datatypes is left as is - the
     * object still shows as 'update' (via its modified date), just with no field diff row.
     */
    protected static function mutateOneDiffableAttributeValue( DOMElement $objectNode )
    {
        $map = self::diffableAttributeFields();
        $versionListNode = $objectNode->getElementsByTagName( 'version-list' )->item( 0 );
        if ( !$versionListNode )
            return false;
        foreach ( $versionListNode->getElementsByTagName( 'object-translation' ) as $translationNode )
        {
            foreach ( $translationNode->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' ) as $attrNode )
            {
                $datatype = $attrNode->getAttribute( 'type' );
                if ( !isset( $map[$datatype] ) )
                    continue;
                $childNode = $attrNode->getElementsByTagName( $map[$datatype]['xml'] )->item( 0 );
                if ( !$childNode )
                    continue;
                $old = $childNode->textContent;
                if ( !empty( $map[$datatype]['bool'] ) )
                    $new = ( $old === '1' ) ? '0' : '1';
                elseif ( $datatype === 'ezinteger' )
                    $new = (string)( (int)$old + 1 );
                elseif ( $datatype === 'ezfloat' )
                    $new = sprintf( '%.2f', (float)$old + 1 );
                else
                    $new = $old . ' (sample, edited)';
                while ( $childNode->firstChild )
                    $childNode->removeChild( $childNode->firstChild );
                $childNode->appendChild( $childNode->ownerDocument->createTextNode( $new ) );
                return true;
            }
        }
        return false;
    }

    /** DOM nodes for every content object an install item carries, inline or in separate files. */
    protected static function objectDOMNodes( eZPackage $package, array $item )
    {
        if ( empty( $item['filename'] ) )
            return array();
        $dom = self::fetchItemDOM( $package, $item );
        if ( !$dom )
            return array();

        $objectListNode = $dom->getElementsByTagName( 'object-list' )->item( 0 );
        if ( $objectListNode )
            return iterator_to_array( $objectListNode->getElementsByTagName( 'object' ) );

        $nodes = array();
        $objectFilesListNode = $dom->getElementsByTagName( 'object-files-list' )->item( 0 );
        if ( !$objectFilesListNode )
            return $nodes;
        $handler = eZPackage::packageHandler( 'ezcontentobject' );
        $directory = $handler ? $handler->contentObjectDirectory() : 'ezcontentobject';
        foreach ( $objectFilesListNode->getElementsByTagName( 'object-file' ) as $fileNode )
        {
            $filePath = $package->path() . '/' . $directory . '/' . $fileNode->getAttribute( 'filename' );
            $objectDOM = $package->fetchDOMFromFile( $filePath );
            if ( $objectDOM )
                $nodes[] = $objectDOM->documentElement;
        }
        return $nodes;
    }

    /** The document element of an install item's own XML file (class or top-level content object file). */
    protected static function fetchItemDOM( eZPackage $package, array $item )
    {
        $filename = $item['filename'];
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        $filePath = $subdirectory ? $subdirectory . '/' . $filename . '.xml' : $filename . '.xml';
        $filePath = $package->path() . '/' . $filePath;
        $dom = $package->fetchDOMFromFile( $filePath );
        return $dom ? $dom->documentElement : false;
    }

    // ------------------------------------------------------------ install

    /**
     * Installs a package through eZPackage::install(), the same convenience
     * method kernel/package/install.php's per-item loop is built on. All
     * "top" nodes the package carries (a content package's own root objects)
     * are placed under $parentNodeID; existing classes/objects are handled
     * per $classMode/$objectMode.
     */
    public static function install( eZPackage $package, $parentNodeID, $siteAccess, $objectMode, $classMode, $userID = false )
    {
        $report = array( 'ok' => false, 'errors' => array(), 'created_classes' => array(), 'created_objects' => array() );

        $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
        if ( !$parentNode instanceof eZContentObjectTreeNode || !$parentNode->canRead() )
        {
            $report['errors'][] = "No node $parentNodeID, or you may not read it.";
            return $report;
        }

        // Remote ids present in the package before install, so afterwards we can
        // resolve exactly the classes/objects this run touched (eZPackage does not
        // hand back a report of what installItem() created).
        $beforeClasses = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::inspectClassItem( $package, $item );
            if ( $row && $row['remote_id'] )
                $beforeClasses[] = $row['remote_id'];
        }
        $beforeObjects = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
            foreach ( self::objectDOMNodes( $package, $item ) as $node )
                if ( $node->getAttribute( 'remote_id' ) )
                    $beforeObjects[] = $node->getAttribute( 'remote_id' );

        $objectActionMap = array(
            self::OBJECT_SKIP   => eZContentObject::PACKAGE_SKIP,
            self::OBJECT_UPDATE => eZContentObject::PACKAGE_UPDATE,
            self::OBJECT_NEW    => eZContentObject::PACKAGE_NEW,
        );
        $classActionMap = array(
            self::CLASS_SKIP    => eZContentClassPackageHandler::ACTION_SKIP,
            self::CLASS_REPLACE => eZContentClassPackageHandler::ACTION_REPLACE,
            self::CLASS_NEW     => eZContentClassPackageHandler::ACTION_NEW,
        );

        $installParameters = array(
            // eZPackageHandler::errorChoosenAction() unconditionally does
            // count( $installParameters['error'] ) once error_default_actions
            // is set for a handler type/code (kernel/classes/ezpackagehandler.php);
            // the step-wizard install.php always seeds this, a direct
            // eZPackage::install() call must do the same or PHP 8 throws a
            // TypeError (count(): Argument #1 must be of type Countable|array,
            // null given) the moment a class/object it carries already exists.
            'error'           => array(),
            'top_nodes_map'   => array( '*' => (int)$parentNodeID ),
            'site_access_map' => array( '*' => $siteAccess ),
            'language_map'    => $package->defaultLanguageMap(),
            'user_id'         => $userID ? (int)$userID : eZUser::currentUserID(),
            'continue-on-error' => true,
            'error_default_actions' => array(
                'ezcontentobject' => array( eZContentObject::PACKAGE_ERROR_EXISTS => isset( $objectActionMap[$objectMode] ) ? $objectActionMap[$objectMode] : eZContentObject::PACKAGE_UPDATE ),
                'ezcontentclass'  => array( eZContentClassPackageHandler::ERROR_EXISTS => isset( $classActionMap[$classMode] ) ? $classActionMap[$classMode] : eZContentClassPackageHandler::ACTION_SKIP ),
            ),
        );

        $ok = $package->install( $installParameters );
        $package->setInstalled();

        foreach ( $beforeClasses as $remoteID )
        {
            $class = eZContentClass::fetchByRemoteID( $remoteID );
            if ( $class instanceof eZContentClass )
                $report['created_classes'][] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ) );
        }
        foreach ( $beforeObjects as $remoteID )
        {
            eZContentObject::clearCache();
            $object = eZContentObject::fetchByRemoteID( $remoteID );
            if ( $object instanceof eZContentObject )
            {
                $mainNode = $object->attribute( 'main_node' );
                $report['created_objects'][] = array(
                    'id'      => (int)$object->attribute( 'id' ),
                    'name'    => $object->name(),
                    'node_id' => $mainNode instanceof eZContentObjectTreeNode ? (int)$mainNode->attribute( 'node_id' ) : null,
                );
            }
        }

        $report['ok'] = (bool)$ok;
        if ( !$ok )
            $report['errors'][] = 'One or more package items failed to install; see the debug log for the exact item. Items that did install are listed above.';

        return $report;
    }

    // ------------------------------------------------------------ read-only packages from existing content
    //
    // "Try a sample" and "Start from a template" on xrowextract/import both build a real package
    // through the same kernel handlers (eZContentClassPackageHandler, eZContentObjectPackageHandler)
    // as package/create - but read-only, from content that already exists on this site, instead of
    // creating (and then removing) scratch objects the way the older Package-page template builder
    // below does. addNode()/generatePackage() only read and serialize; nothing here writes to the
    // content tree. A class with no content yet still falls back to the scratch-object builder
    // below, flagged in the result so the caller can say so on the page.

    const SAMPLE_PACKAGE_PREFIX = 'xrowextract_sample_';
    const SAMPLE_PACKAGE_KEEP_MARKER = '.xrowextract_keep';
    /** How long an unwanted "Try a sample" package is left in the repository before cleanup removes it. */
    const SAMPLE_PACKAGE_MAX_AGE = 21600; // 6 hours

    /**
     * Builds a real, installable sample content package for xrowextract/import's
     * "Try a sample content package" button: the class definition plus up to 3
     * existing content objects of it, read straight off this site through
     * eZContentClassPackageHandler/eZContentObjectPackageHandler - nothing is
     * created, changed or removed on the live site. The package is then
     * altered in place (a fresh copy on disk, never the live objects) so its
     * dry run demonstrates every outcome the inspection screen can show:
     * one object left exactly as exported (unchanged), one with an edited
     * name and an older modified date (update), one given a brand new remote
     * id (create), and one given a class identifier that does not exist
     * (class missing). A class with no content yet gets the class definition
     * only, noted in the result.
     *
     * The package never lands in the repository at all: it is built there
     * only because eZContentClassPackageHandler/eZContentObjectPackageHandler
     * need a real eZPackage to work with, then exported to a private .ezpkg
     * file (exportToPrivateFile()) and removed again from the repository
     * before this method returns - "Try a sample" leaves nothing registered
     * unless keepSamplePackage() is called for it later (import.php imports
     * the returned file transiently, one request at a time, and removes it
     * again after each - see XrowExtractPackage::withTransientImport()).
     */
    public static function buildSamplePackage( $classID )
    {
        $result = array( 'ok' => false, 'errors' => array(), 'file' => null, 'object_count' => 0, 'outcomes' => array(), 'note' => '' );
        $class = ctype_digit( (string)$classID ) ? eZContentClass::fetch( (int)$classID ) : eZContentClass::fetchByIdentifier( $classID );
        if ( !$class instanceof eZContentClass )
        {
            $result['errors'][] = "No such class: $classID";
            return $result;
        }
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );

        $objects = eZContentObject::fetchSameClassList( $classID, true, 0, 3 );
        $objects = is_array( $objects ) ? $objects : array();

        $packageName = self::uniquePackageName( self::SAMPLE_PACKAGE_PREFIX . $classIdentifier );
        $package = eZPackage::create( $packageName, array(
            'summary' => "Sample content package for '$classIdentifier', built read-only from this site's own content.",
            'vendor'  => 'xrowextract',
        ) );
        self::attachAboutDocument( $package, "Try-a-sample package for class '$classIdentifier'. Built read-only from this site's own content by xrowextract/import; exists in the repository only for the moment it takes to write the .ezpkg file." );

        if ( !$objects )
        {
            eZContentClassPackageHandler::addClass( $package, $classID );
            $result['note'] = "Class '$classIdentifier' has no content on this site yet; the sample carries the class definition only.";
        }
        else
        {
            self::exportExistingObjectsIntoPackage( $package, $class, $objects, true );
            $result['outcomes'] = self::mutateSampleOutcomes( $package, $classIdentifier, $result['errors'] );
        }

        $package->setAttribute( 'is_active', true );
        $package->store();

        $file = self::exportToPrivateFile( $package, 'sample_' . $classIdentifier );
        $package->remove();

        if ( $file === false )
        {
            $result['errors'][] = 'Could not write the sample package to a temporary file.';
            return $result;
        }
        $result['ok'] = true;
        $result['file'] = $file;
        $result['object_count'] = count( $objects );
        return $result;
    }

    /** Exports $package to a randomly-named .ezpkg inside XrowExtractImport::uploadDir() (the same private, 0700 folder a row sample/upload uses), never a public path. Returns the file path, or false on failure. */
    public static function exportToPrivateFile( eZPackage $package, $baseName )
    {
        $dir = XrowExtractImport::uploadDir();
        $safeBase = preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $baseName );
        $name = 'pkg_' . $safeBase . '_' . date( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.ezpkg';
        $target = $dir . '/' . $name;
        $written = $package->exportToArchive( $target );
        if ( !$written || !is_file( $target ) )
            return false;
        @chmod( $target, 0600 );
        return $target;
    }

    /**
     * Imports $ezpkgPath into the repository just long enough to run $callback( eZPackage $package )
     * against it, then removes it again - "a package must never land in the
     * repository unless the user keeps it" applied uniformly, for every
     * request a sample's dry run/install touches (Preview, Apply, or simply
     * the meta line on first load), not only at Remove/sweep time.
     * $callback's return value is passed straight through. If $callback
     * throws, the transient package is still removed before the exception
     * continues up (a request that errors out must not leak one either).
     */
    public static function withTransientImport( $ezpkgPath, callable $callback )
    {
        $imported = self::importUploadedArchive( $ezpkgPath );
        if ( !$imported['ok'] || !( $imported['package'] instanceof eZPackage ) )
            return $callback( null, $imported['error'] );
        try
        {
            return $callback( $imported['package'], null );
        }
        finally
        {
            // A fresh instance, not $imported['package']: install()/setInstalled() etc. inside the
            // callback may have mutated attributes that make the original reference stale.
            $stillThere = eZPackage::fetch( $imported['package']->attribute( 'name' ) );
            if ( $stillThere instanceof eZPackage )
                $stillThere->remove();
        }
    }

    /**
     * addNode()+generatePackage() for a list of existing eZContentObject
     * instances: read-only, the same call package/create makes for real
     * nodes. $includeClasses also adds the class item(s) the objects belong
     * to (eZContentObjectPackageHandler::generatePackage() does this itself
     * when true).
     */
    protected static function exportExistingObjectsIntoPackage( eZPackage $package, eZContentClass $class, array $objects, $includeClasses )
    {
        $nodeIDs = array();
        foreach ( $objects as $object )
        {
            $mainNode = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
            if ( $mainNode instanceof eZContentObjectTreeNode )
                $nodeIDs[] = (int)$mainNode->attribute( 'node_id' );
        }
        if ( !$nodeIDs )
            return false;

        $languages = array_keys( XrowExtractColumns::contentLanguages() );
        if ( !$languages )
            $languages = array( 'eng-US' );

        // eZPackage::packageHandler() reuses the SAME eZContentObjectPackageHandler instance for
        // every 'ezcontentobject' call in the process (kernel/classes/ezpackage.php's own
        // $GLOBALS['eZPackageHandlers'] registry) and its reset() is an inherited no-op
        // (kernel/classes/ezpackagehandler.php) - on a single short-lived FPM/CLI request this never
        // shows, but on a long-running Velocity worker every one of its public arrays
        // (NodeIDArray/ObjectArray/...) still carries whatever an earlier, unrelated call in the
        // same worker (a previous sample, a template build, an "Export as package" job) added, and
        // generatePackage() only ever array_unique()s NodeIDArray, never clears it. Cleared by hand
        // here so this call only ever exports the nodes it was just given.
        $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
        $objectHandler->NodeIDArray = array();
        $objectHandler->RootNodeIDArray = array();
        $objectHandler->NodeObjectArray = array();
        $objectHandler->ObjectArray = array();
        $objectHandler->RootNodeObjectArray = array();
        foreach ( $nodeIDs as $nodeID )
            $objectHandler->addNode( $nodeID, false );
        // language_array is the *allow-list* eZContentObjectVersion::serialize() checks each of an
        // object's own translations against (kernel/classes/ezcontentobjectversion.php) - every
        // configured site language, not a guess, so no real translation the objects happen to have
        // is silently dropped from the export.
        $objectHandler->generatePackage( $package, array(
            'include_classes'   => $includeClasses,
            'include_templates' => false,
            'site_access_array' => array(),
            'versions'          => 'current',
            'language_array'    => $languages,
            'node_assignment'   => 'selected',
            'related_objects'   => 'selected',
            'embed_objects'     => 'selected',
        ) );
        return true;
    }

    /**
     * Alters a just-exported object item on disk (never the live objects) so
     * its dry run shows every outcome: object 0 stays untouched (unchanged),
     * object 1 (if there is one) gets an edited name and an older modified
     * date (update), a fresh clone gets a brand new remote id (create), and
     * another fresh clone gets a class identifier that exists nowhere
     * (class_missing). Returns the outcomes produced, for the page to list.
     */
    protected static function mutateSampleOutcomes( eZPackage $package, $classIdentifier, array &$errors )
    {
        $items = self::installItemsOfType( $package, 'ezcontentobject' );
        if ( !$items )
        {
            $errors[] = 'Could not find the exported object item to build the sample outcomes from.';
            return array();
        }
        $item = $items[0];
        if ( empty( $item['filename'] ) )
            return array();
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        $filePath = $package->path() . '/' . ( $subdirectory ? $subdirectory . '/' . $item['filename'] . '.xml' : $item['filename'] . '.xml' );
        $dom = $package->fetchDOMFromFile( $filePath );
        if ( !$dom )
        {
            $errors[] = "Could not re-read the exported object file ($filePath) to build the sample outcomes.";
            return array();
        }
        $objectListNode = $dom->getElementsByTagName( 'object-list' )->item( 0 );
        if ( !$objectListNode )
            return array(); // >=100 objects would use object-files-list instead; never reached with at most 3

        $objectNodes = iterator_to_array( $objectListNode->getElementsByTagName( 'object' ) );
        $n = count( $objectNodes );
        if ( $n === 0 )
            return array();

        $originalNames = array();
        foreach ( $objectNodes as $i => $node )
            $originalNames[$i] = $node->getAttribute( 'name' );

        // Built from pristine copies before anything is edited in place, so the 'create'/'class_missing'
        // clones never pick up the 'update' edit made to object 1 below.
        $createSourceIndex = $n >= 3 ? 2 : ( $n - 1 );
        $createClone = $objectNodes[$createSourceIndex]->cloneNode( true );
        $createRemoteID = 'xrowextract-sample-create-' . substr( md5( uniqid( '', true ) ), 0, 10 );
        $createClone->setAttribute( 'remote_id', $createRemoteID );
        $createClone->setAttribute( 'name', $originalNames[$createSourceIndex] . ' (sample, new)' );

        $missingClone = $objectNodes[0]->cloneNode( true );
        $missingRemoteID = 'xrowextract-sample-missing-' . substr( md5( uniqid( '', true ) ), 0, 10 );
        $missingClone->setAttribute( 'remote_id', $missingRemoteID );
        $missingClone->setAttribute( 'class_remote_id', 'xrowextract-sample-missing-class-remote-id' );
        $missingClone->setAttributeNS( 'http://ez.no/ezobject', 'ezremote:class_identifier', $classIdentifier . '_sample_missing_demo' );
        $missingClone->setAttribute( 'name', $originalNames[0] . ' (sample, missing class demo)' );

        $outcomes = array();
        if ( $n >= 2 )
        {
            $node = $objectNodes[1];
            $node->setAttribute( 'name', $originalNames[1] . ' (sample, edited)' );
            $node->setAttributeNS( 'http://ez.no/ezobject', 'ezremote:modified', eZDateUtils::rfc1123Date( time() - 172800 ) );
            // Also edit one real attribute value where the class has a datatype objectFieldChanges()
            // can compare, so the update demo shows a field-level old -> new, not only a changed name.
            self::mutateOneDiffableAttributeValue( $node );
            $outcomes[] = array( 'outcome' => 'update', 'name' => $originalNames[1] );
        }
        $outcomes[] = array( 'outcome' => 'unchanged', 'name' => $originalNames[0] );

        $objectListNode->appendChild( $createClone );
        $outcomes[] = array( 'outcome' => 'create', 'name' => $originalNames[$createSourceIndex] . ' (sample, new)' );
        $objectListNode->appendChild( $missingClone );
        $outcomes[] = array( 'outcome' => 'class_missing', 'name' => $originalNames[0] . ' (sample, missing class demo)' );

        $package->storeDOM( $filePath, $dom );
        return $outcomes;
    }

    /** Marks a "Try a sample" package as one the user chose to keep, exempting it from cleanupOldSamplePackages(). */
    public static function keepSamplePackage( eZPackage $package )
    {
        @file_put_contents( $package->path() . '/' . self::SAMPLE_PACKAGE_KEEP_MARKER, (string)time() );
    }

    /** If $sessionEntry names a "Try a sample" package that was never kept, removes it from the repository right away (leaving before cleanupOldSamplePackages() gets to it). */
    public static function forgetUnkeptSamplePackage( array $sessionEntry )
    {
        if ( empty( $sessionEntry['is_sample'] ) || empty( $sessionEntry['package_name'] ) )
            return;
        $package = eZPackage::fetch( $sessionEntry['package_name'] );
        if ( !$package instanceof eZPackage )
            return;
        if ( is_file( $package->path() . '/' . self::SAMPLE_PACKAGE_KEEP_MARKER ) )
            return;
        $package->remove();
    }

    /**
     * Removes any "Try a sample" package older than SAMPLE_PACKAGE_MAX_AGE
     * that was never kept, so trying a few samples in a row does not
     * accumulate packages in the repository forever. Called once per
     * xrowextract/import request, the same pattern as
     * XrowExtractImport::cleanupOldUploads().
     */
    public static function cleanupOldSamplePackages()
    {
        $removed = 0;
        foreach ( eZPackage::fetchPackages() as $package )
        {
            $name = $package->attribute( 'name' );
            if ( strpos( $name, self::SAMPLE_PACKAGE_PREFIX ) !== 0 )
                continue;
            $path = $package->path();
            if ( is_file( $path . '/' . self::SAMPLE_PACKAGE_KEEP_MARKER ) )
                continue;
            $packageXML = $path . '/package.xml';
            $age = is_file( $packageXML ) ? ( time() - filemtime( $packageXML ) ) : PHP_INT_MAX;
            if ( $age < self::SAMPLE_PACKAGE_MAX_AGE )
                continue;
            $package->remove();
            $removed++;
        }
        return $removed;
    }

    /** The name prefixes ext:xrowextract:package --clean and cleanupOldSamplePackages() both look for - every package this extension itself builds and might leave behind, never another extension's. */
    public static function leftoverPackagePrefixes()
    {
        return array( self::SAMPLE_PACKAGE_PREFIX, 'xrowextract_export_', 'xrowextract_template_' );
    }

    /** Every repository package whose name starts with one of leftoverPackagePrefixes() - what ext:xrowextract:package --clean lists and, without --dry-run, removes. Never matches a package this extension did not build (sevenx_*, and so on). */
    public static function findLeftoverPackages()
    {
        $prefixes = self::leftoverPackagePrefixes();
        $matches = array();
        foreach ( eZPackage::fetchPackages() as $package )
        {
            $name = $package->attribute( 'name' );
            foreach ( $prefixes as $prefix )
            {
                if ( strpos( $name, $prefix ) === 0 )
                {
                    $matches[] = $package;
                    break;
                }
            }
        }
        return $matches;
    }

    /**
     * Builds a real .ezpkg for "Start from a template" on xrowextract/import:
     * class only, content only, or class + content, for one class - preferring
     * up to 3 real existing objects (read-only, same as buildSamplePackage())
     * over the older scratch-object builder below. Falls back to
     * buildTemplatePackage()'s scratch-content approach only when the class
     * has no existing content and the variant needs some, flagged with
     * 'used_scratch' => true so the page can say so.
     */
    public static function buildContentPackage( $classID, $variant, array $options = array() )
    {
        $variant = in_array( $variant, array( 'class', 'content', 'both' ), true ) ? $variant : 'both';
        $result = array( 'ok' => false, 'errors' => array(), 'package' => null, 'used_scratch' => false, 'object_count' => 0 );
        $class = ctype_digit( (string)$classID ) ? eZContentClass::fetch( (int)$classID ) : eZContentClass::fetchByIdentifier( $classID );
        if ( !$class instanceof eZContentClass )
        {
            $result['errors'][] = "No such class: $classID";
            return $result;
        }
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );

        if ( $variant === 'class' )
        {
            $package = eZPackage::create( self::uniquePackageName( 'xrowextract_template_' . $classIdentifier . '_class' ), array(
                'summary' => "Content-package template for '$classIdentifier': the content class only.",
                'vendor'  => 'xrowextract',
            ) );
            self::attachAboutDocument( $package, "Class-only template for '$classIdentifier'. Built by the xrowextract Import page." );
            eZContentClassPackageHandler::addClass( $package, $classID );
            $package->setAttribute( 'is_active', true );
            $package->store();
            $result['ok'] = true;
            $result['package'] = $package;
            return $result;
        }

        $objects = eZContentObject::fetchSameClassList( $classID, true, 0, 3 );
        $objects = is_array( $objects ) ? $objects : array();
        if ( $objects )
        {
            $packageName = self::uniquePackageName( 'xrowextract_template_' . $classIdentifier . '_' . $variant );
            $package = eZPackage::create( $packageName, array(
                'summary' => "Content-package template for '$classIdentifier': " . ( $variant === 'both' ? 'the content class and existing sample content' : 'existing sample content (the class must already exist on the installing site)' ),
                'vendor'  => 'xrowextract',
            ) );
            self::attachAboutDocument( $package, "Template for '$classIdentifier' ($variant), built from " . count( $objects ) . " existing object(s) already on this site - read-only, nothing created or changed. By the xrowextract Import page." );
            self::exportExistingObjectsIntoPackage( $package, $class, $objects, $variant === 'both' );
            $package->setAttribute( 'is_active', true );
            $package->store();
            $result['ok'] = true;
            $result['package'] = $package;
            $result['object_count'] = count( $objects );
            return $result;
        }

        // No existing content for this class: fall back to the scratch-object builder, flagged.
        $fallback = self::buildTemplatePackage( $classID, $variant, $options );
        $fallback['used_scratch'] = $fallback['ok'];
        return $fallback;
    }

    /** The raw bytes of the single XML file a class-only or content-only package carries, for "Download class/object XML" on their own (no archive). */
    public static function singleItemXMLBytes( eZPackage $package, $type )
    {
        $items = self::installItemsOfType( $package, $type );
        if ( !$items || empty( $items[0]['filename'] ) )
            return false;
        $item = $items[0];
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        $filePath = $package->path() . '/' . ( $subdirectory ? $subdirectory . '/' . $item['filename'] . '.xml' : $item['filename'] . '.xml' );
        return is_file( $filePath ) ? @file_get_contents( $filePath ) : false;
    }

    // ------------------------------------------------------------ template / sample package builder

    public static function templateVariants()
    {
        return array(
            'class'   => 'Class only',
            'content' => 'Content only',
            'both'    => 'Class + content',
        );
    }

    /**
     * Builds a real, installable sample package for one content class: the
     * class definition itself (variant class/both) and/or 2-3 real content
     * objects with a valid value for every importable datatype the class has
     * (variant content/both). The sample objects are created for real, added
     * to the package through the kernel handlers exactly as package/create
     * does, and then removed again - nothing from this call is left in the
     * content tree.
     */
    public static function buildTemplatePackage( $classID, $variant, array $options = array() )
    {
        $result = array( 'ok' => false, 'errors' => array(), 'package' => null, 'sample_object_ids' => array(), 'scratch_location' => null );

        $variant = in_array( $variant, array( 'class', 'content', 'both' ), true ) ? $variant : 'both';
        $class = ctype_digit( (string)$classID ) ? eZContentClass::fetch( (int)$classID ) : eZContentClass::fetchByIdentifier( $classID );
        if ( !$class instanceof eZContentClass )
        {
            $result['errors'][] = "No such class: $classID";
            return $result;
        }
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );
        $needsContent = $variant !== 'class';
        $needsOwnClassItem = $variant === 'class';

        $languages = self::sampleLanguages( $options );
        $objectCount = isset( $options['object_count'] ) ? max( 1, min( 5, (int)$options['object_count'] ) ) : 3;

        $createdObjectIDs = array();
        $createdNodeIDs = array();
        $scratchFolderNodeID = false;

        if ( $needsContent )
        {
            $scratchNodeID = isset( $options['scratch_node_id'] ) ? (int)$options['scratch_node_id'] : self::defaultScratchNodeID();
            $scratchNode = eZContentObjectTreeNode::fetch( $scratchNodeID );
            if ( !$scratchNode instanceof eZContentObjectTreeNode || !$scratchNode->checkAccess( 'create', $classID ) )
            {
                $result['errors'][] = "Cannot create sample content of class '$classIdentifier' below node $scratchNodeID (export.ini [PackageTemplate] ScratchNodeID, or content.ini [NodeSettings] MediaRootNode): no such node, or no create permission.";
                return $result;
            }

            // A temporary, explicitly hidden folder below the scratch node, so the sample
            // objects are not merely unrendered but hidden for the seconds they exist; if
            // this site has no creatable 'folder' class here, they go straight below the
            // scratch node instead (still never the public front page).
            $scratchFolderNodeID = self::createScratchFolder( $scratchNodeID, $languages[0], $result['errors'] );
            $creationNodeID = $scratchFolderNodeID ?: $scratchNodeID;

            $created = self::createSampleObjects( $class, $objectCount, $languages, $creationNodeID, $result['errors'] );
            if ( !$created )
            {
                if ( $scratchFolderNodeID )
                    eZContentObjectTreeNode::removeSubtrees( array( $scratchFolderNodeID ), false );
                $result['errors'][] = "Could not create any sample content object for class '$classIdentifier'; see the errors above.";
                return $result;
            }
            $createdObjectIDs = $created['object_ids'];
            $createdNodeIDs = $created['node_ids'];
            $result['scratch_location'] = array(
                'node_id'           => $creationNodeID,
                'base_node_id'      => $scratchNodeID,
                'base_path'         => $scratchNode->attribute( 'path_identification_string' ) ?: ( 'node ' . $scratchNodeID ),
                'used_hidden_folder' => (bool)$scratchFolderNodeID,
            );
        }

        $packageName = isset( $options['package_name'] ) && $options['package_name'] !== ''
                     ? preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $options['package_name'] )
                     : self::uniquePackageName( 'xrowextract_template_' . $classIdentifier . '_' . $variant );

        $variantLabel = array( 'class' => 'the content class only', 'content' => 'sample content only (the class must already exist on the installing site)', 'both' => 'the content class and matching sample content' );
        $package = eZPackage::create( $packageName, array(
            'summary'     => "Content-package template for '$classIdentifier': " . $variantLabel[$variant],
            'description' => "Generated by the xrowextract Package Template builder.\n" .
                              "Class: $classIdentifier (" . $class->name() . ")\n" .
                              "Variant: $variant\n" .
                              ( $needsContent ? 'Sample objects: ' . $objectCount . ', languages: ' . implode( ', ', $languages ) . "\n" : '' ) .
                              "Every attribute of the class that this extension's import feature can write was filled with a real, valid sample value; " .
                              "attributes with no import handler for this class keep their class default. " .
                              "Install with xrowextract/package (or kernel package/install) and choose where the sample content is placed.",
            'vendor'      => 'xrowextract',
            'licence'     => 'GPL-2.0-or-later',
        ) );
        $package->setRelease( '1.0', '1', time(), 'GPL-2.0-or-later', 'stable' );
        $package->appendMaintainer( 'xrowextract Package template', '', 'developer' );
        $package->appendChange( 'xrowextract Package template', '', array( "Generated a '$variant' sample package for class '$classIdentifier'." ) );
        self::attachAboutDocument( $package, "Sample package for class '$classIdentifier', variant '$variant'. Built by the xrowextract Package template." );
        $package->appendProvides( 'ezcontentclass', $classIdentifier, $class->attribute( 'remote_id' ) );
        if ( $needsContent )
            $package->appendDependency( 'requires', array( 'type' => 'ezcontentclass', 'name' => $classIdentifier, 'value' => $class->attribute( 'remote_id' ) ) );

        if ( $needsOwnClassItem )
        {
            eZContentClassPackageHandler::addClass( $package, $classID );
        }

        if ( $needsContent )
        {
            // Same reused-handler reset as exportExistingObjectsIntoPackage() above - see the long
            // comment there (eZPackage::packageHandler()'s reset() is an inherited no-op).
            $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
            $objectHandler->NodeIDArray = array();
            $objectHandler->RootNodeIDArray = array();
            $objectHandler->NodeObjectArray = array();
            $objectHandler->ObjectArray = array();
            $objectHandler->RootNodeObjectArray = array();
            foreach ( $createdNodeIDs as $nodeID )
                $objectHandler->addNode( $nodeID, false );
            $objectHandler->generatePackage( $package, array(
                'include_classes'      => ( $variant === 'both' ),
                'include_templates'    => false,
                'site_access_array'    => array(),
                'versions'             => 'current',
                'language_array'       => $languages,
                'node_assignment'      => 'selected',
                'related_objects'      => 'selected',
                'embed_objects'        => 'selected',
            ) );
        }

        $package->setAttribute( 'is_active', true );
        $package->store();

        // Removing the hidden scratch folder takes its children (the sample objects) with
        // it in one call; without a folder, remove the sample objects directly. Either way
        // $moveToTrash = false: a final removal, not a trip through the trash can, and the
        // kernel's own remove path clears the search index and URL aliases for the removed
        // ids along with it (checked in this extension's sandbox tests).
        if ( $scratchFolderNodeID )
            eZContentObjectTreeNode::removeSubtrees( array( $scratchFolderNodeID ), false );
        elseif ( $createdNodeIDs )
            eZContentObjectTreeNode::removeSubtrees( $createdNodeIDs, false );

        $result['ok'] = true;
        $result['package'] = $package;
        $result['sample_object_ids'] = $createdObjectIDs;
        return $result;
    }

    protected static function sampleLanguages( array $options )
    {
        if ( !empty( $options['languages'] ) && is_array( $options['languages'] ) )
            return array_values( $options['languages'] );
        $languages = array_keys( XrowExtractColumns::contentLanguages() );
        if ( !$languages )
            $languages = array( 'eng-US' );
        // At most two: the default/first, plus one more to show a real translation.
        return array_slice( $languages, 0, 2 );
    }

    /**
     * Where the template builder's sample objects are created while a package
     * is being built. Never the public site's front page: export.ini
     * [PackageTemplate] ScratchNodeID if set, otherwise content.ini
     * [NodeSettings] MediaRootNode of the default siteaccess - a location no
     * shipped layout, search index or the static/content-view cache renders
     * for a visitor, unlike RootNode.
     */
    public static function defaultScratchNodeID()
    {
        $exportINI = eZINI::instance( 'export.ini' );
        if ( $exportINI->hasVariable( 'PackageTemplate', 'ScratchNodeID' ) )
        {
            $configured = trim( (string)$exportINI->variable( 'PackageTemplate', 'ScratchNodeID' ) );
            if ( $configured !== '' )
                return (int)$configured;
        }
        $publicContentINI = eZSiteAccess::getIni( eZINI::instance()->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
        return (int)$publicContentINI->variable( 'NodeSettings', 'MediaRootNode' );
    }

    /** Node id and path of where a template build's sample objects would be created, for display before/after a build. */
    public static function scratchLocationInfo( $nodeID = false )
    {
        $nodeID = $nodeID ? (int)$nodeID : self::defaultScratchNodeID();
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        $path = $node instanceof eZContentObjectTreeNode ? $node->attribute( 'path_identification_string' ) : false;
        return array(
            'node_id' => $nodeID,
            'path'    => $path ?: ( 'node ' . $nodeID ),
            'exists'  => $node instanceof eZContentObjectTreeNode,
        );
    }

    /**
     * Creates a temporary, hidden "scratch" folder below $parentNodeID to hold
     * a template build's sample objects, so even the seconds they exist they
     * are not merely unrendered but explicitly hidden. Returns the new
     * folder's node id, or false if this site has no creatable 'folder' class
     * here (the caller then creates the sample objects directly below
     * $parentNodeID instead - still not the public front page, just without
     * the extra hidden layer).
     */
    protected static function createScratchFolder( $parentNodeID, $language, array &$errors )
    {
        $folderClass = eZContentClass::fetchByIdentifier( 'folder' );
        if ( !$folderClass instanceof eZContentClass )
            return false;
        $folderClassID = (int)$folderClass->attribute( 'id' );
        $parentNode = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
        if ( !$parentNode instanceof eZContentObjectTreeNode || !$parentNode->checkAccess( 'create', $folderClassID ) )
            return false;

        $classAttributes = eZContentClassAttribute::fetchListByClassID( $folderClassID, eZContentClass::VERSION_STATUS_DEFINED, true );
        $remoteID = 'xrowextract-pkgtpl-scratch-' . date( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        list( $row, $mapping ) = self::sampleRow( $classAttributes, 'folder', 1, array( 1 => $remoteID ), $parentNodeID, $language );

        $runResult = XrowExtractImport::run( array(
            'rows'         => array( $row ),
            'mapping'      => $mapping,
            'classID'      => $folderClassID,
            'match'        => 'remote_id',
            'language'     => $language,
            'parentNodeID' => $parentNodeID,
            'apply'        => true,
        ) );
        $rowResult = $runResult['rows'][0];
        if ( $rowResult['action'] === 'error' || !$rowResult['object_id'] )
        {
            $errors[] = 'scratch folder (not fatal, sample objects go directly below the scratch node instead): ' . $rowResult['reason'];
            return false;
        }

        eZContentObject::clearCache( array( (int)$rowResult['object_id'] ) );
        $object = eZContentObject::fetch( (int)$rowResult['object_id'] );
        $mainNode = $object instanceof eZContentObject ? $object->attribute( 'main_node' ) : null;
        if ( !$mainNode instanceof eZContentObjectTreeNode )
            return false;
        $folderNodeID = (int)$mainNode->attribute( 'node_id' );

        // Hidden immediately: eZContentOperationCollection::changeHideStatus() is the same
        // call kernel/content/action.php's HideButton uses, toggling is_hidden on a node
        // that starts visible (a freshly published node always does).
        if ( class_exists( 'eZContentOperationCollection' ) )
            eZContentOperationCollection::changeHideStatus( $folderNodeID );

        return $folderNodeID;
    }

    /** $base, or $base_2, $base_3, ... the first one not already in the local package repository. */
    /**
     * A one-line "about" document, purely so the package's <documents> is
     * never empty. Not decorative: eZPackage::parseDOMTree() (kernel/classes/
     * ezpackage.php, the code eZPackage::import() re-parses a package.xml
     * with) does $root->getElementsByTagName('documents')->item(0) and then
     * calls a method on the result with no null check - and the writer only
     * emits <documents> at all when there is at least one document
     * (kernel/classes/ezpackage.php's own domStructure(): if (count(
     * $documents) > 0)). A package with none, exactly what this class built
     * before this method existed, is valid XML that the kernel's own writer
     * produces and its own reader then fatals on reading back - reported,
     * not fixed here (kernel is out of scope); this is the workaround inside
     * this extension's own package-building code so a package built by this
     * extension always survives the eZPackage::import() an upload runs it
     * through.
     */
    public static function attachAboutDocument( eZPackage $package, $text )
    {
        $package->appendDocument( 'about.txt', 'text/plain', false, false, false, $text );
    }

    protected static function uniquePackageName( $base )
    {
        $base = preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $base );
        $name = $base;
        $suffix = 1;
        while ( eZPackage::fetch( $name, false, 'local', false ) )
        {
            $suffix++;
            $name = $base . '_' . $suffix;
        }
        return $name;
    }

    /** Creates $count real content objects of $class below $scratchNodeID, with a value for every importable attribute. Returns false if none could be created. */
    protected static function createSampleObjects( eZContentClass $class, $count, array $languages, $scratchNodeID, array &$errors )
    {
        $classID = (int)$class->attribute( 'id' );
        $classIdentifier = $class->attribute( 'identifier' );
        $classAttributes = eZContentClassAttribute::fetchListByClassID( $classID, eZContentClass::VERSION_STATUS_DEFINED, true );
        $primaryLanguage = $languages[0];
        $stamp = date( 'YmdHis' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );

        $remoteIDs = array();
        for ( $i = 1; $i <= $count; $i++ )
            $remoteIDs[$i] = 'xrowextract-pkgtpl-' . $classIdentifier . '-' . $stamp . '-' . $i;

        $objectIDs = array();
        for ( $i = 1; $i <= $count; $i++ )
        {
            list( $row, $mapping ) = self::sampleRow( $classAttributes, $classIdentifier, $i, $remoteIDs, $scratchNodeID, $primaryLanguage );
            $runResult = XrowExtractImport::run( array(
                'rows'         => array( $row ),
                'mapping'      => $mapping,
                'classID'      => $classID,
                'match'        => 'remote_id',
                'language'     => $primaryLanguage,
                'parentNodeID' => $scratchNodeID,
                'apply'        => true,
            ) );
            $rowResult = $runResult['rows'][0];
            if ( $rowResult['action'] === 'error' || !$rowResult['object_id'] )
            {
                $errors[] = "sample object $i: " . $rowResult['reason'];
                continue;
            }
            $objectIDs[] = (int)$rowResult['object_id'];

            foreach ( array_slice( $languages, 1 ) as $extraLanguage )
            {
                list( $row2, $mapping2 ) = self::sampleRow( $classAttributes, $classIdentifier, $i, $remoteIDs, $scratchNodeID, $extraLanguage );
                $runResult2 = XrowExtractImport::run( array(
                    'rows'         => array( $row2 ),
                    'mapping'      => $mapping2,
                    'classID'      => $classID,
                    'match'        => 'remote_id',
                    'language'     => $extraLanguage,
                    'parentNodeID' => $scratchNodeID,
                    'apply'        => true,
                ) );
                if ( $runResult2['rows'][0]['action'] === 'error' )
                    $errors[] = "sample object $i, translation $extraLanguage: " . $runResult2['rows'][0]['reason'];
            }
        }

        $nodeIDs = array();
        foreach ( $objectIDs as $objectID )
        {
            eZContentObject::clearCache( array( $objectID ) );
            $object = eZContentObject::fetch( $objectID );
            if ( $object instanceof eZContentObject )
            {
                $mainNode = $object->attribute( 'main_node' );
                if ( $mainNode instanceof eZContentObjectTreeNode )
                    $nodeIDs[] = (int)$mainNode->attribute( 'node_id' );
            }
        }

        if ( !$objectIDs )
            return false;
        return array( 'object_ids' => $objectIDs, 'node_ids' => $nodeIDs );
    }

    /** One row + mapping (as XrowExtractImport::run() expects) with a real sample value for every attribute this importer can write. */
    protected static function sampleRow( array $classAttributes, $classIdentifier, $index, array $remoteIDs, $scratchNodeID, $language )
    {
        $row = array();
        $mapping = array();
        $column = 0;
        $add = function ( $target, $value ) use ( &$row, &$mapping, &$column )
        {
            $key = 'c' . $column++;
            $row[$key] = $value;
            $mapping[] = array( 'column' => $key, 'target' => $target );
        };

        $add( 'special:ezcontentobject.remote_id', $remoteIDs[$index] );
        $add( 'special:ezcontentobject.class_identifier', $classIdentifier );
        $add( 'special:ezcontentobject.language', $language );
        $add( 'special:ezcontentobject.main_parent_node_id', (string)$scratchNodeID );

        foreach ( $classAttributes as $classAttribute )
        {
            $identifier = $classAttribute->attribute( 'identifier' );
            $datatype = $classAttribute->attribute( 'data_type_string' );
            $sample = self::sampleAttributeValue( $classAttribute, $datatype, $index, $classIdentifier, $remoteIDs, $language );
            if ( $sample === null )
                continue;
            $target = $sample['kind'] === 'attrfmt' ? ( 'attrfmt:' . $identifier . ':' . $sample['format'] ) : ( 'attr:' . $identifier );
            $add( $target, $sample['value'] );
        }

        return array( $row, $mapping );
    }

    /** A real, valid value for one class attribute's datatype, or null to leave the class default (no import handler, or not applicable for this row). */
    protected static function sampleAttributeValue( eZContentClassAttribute $classAttribute, $datatype, $index, $classIdentifier, array $remoteIDs, $language )
    {
        $label = $classAttribute->attribute( 'name' );
        switch ( $datatype )
        {
            case 'ezstring':
                return array( 'kind' => 'attr', 'value' => "Sample $label $index ($language)" );
            case 'eztext':
                return array( 'kind' => 'attr', 'value' => "Sample body text for $label, item $index ($language). Generated by the xrowextract Package template builder to show a real, valid value for this datatype." );
            case 'ezinteger':
                return array( 'kind' => 'attr', 'value' => (string)( 10 * $index ) );
            case 'ezfloat':
                return array( 'kind' => 'attr', 'value' => sprintf( '%.2f', 3.5 * $index ) );
            case 'ezboolean':
                return array( 'kind' => 'attr', 'value' => (string)( $index % 2 ) );
            case 'ezemail':
                return array( 'kind' => 'attr', 'value' => "sample{$index}-{$language}@example.com" );
            case 'ezidentifier':
                return array( 'kind' => 'attr', 'value' => "sample-{$classIdentifier}-{$index}" );
            case 'ezurl':
                return array( 'kind' => 'attr', 'value' => "https://example.com/sample-{$index}-{$language}" );
            case 'ezdate':
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d', strtotime( "+{$index} day" ) ) );
            case 'ezdatetime':
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d H:i:s', strtotime( "+{$index} hour" ) ) );
            case 'ezselection':
                $content = $classAttribute->content();
                $options = isset( $content['options'] ) ? $content['options'] : array();
                if ( !$options )
                    return null;
                $keys = array_keys( $options );
                $pick = $keys[( $index - 1 ) % count( $keys )];
                return array( 'kind' => 'attrfmt', 'format' => 'ids', 'value' => (string)$pick );
            case 'ezkeyword':
                return array( 'kind' => 'attr', 'value' => "sample, keyword $index $language" );
            case 'eztags':
                return array( 'kind' => 'attr', 'value' => "Sample tag A $language, Sample tag $index $language" );
            case 'ezxmltext':
                return array( 'kind' => 'attr', 'value' => "<p>Sample rich text paragraph $index for $label ($language).</p>" );
            case 'ezimage':
                return array( 'kind' => 'attr', 'value' => self::sampleAssetPath( 'sample-image.jpg' ) );
            case 'ezbinaryfile':
            case 'ezmedia':
                return array( 'kind' => 'attr', 'value' => self::sampleAssetPath( 'sample-document.txt' ) );
            case 'ezobjectrelation':
            case 'ezobjectrelationlist':
                if ( $index <= 1 )
                    return null;
                if ( !self::classAllowsRelation( $classAttribute, $classIdentifier ) )
                    return null;
                return array( 'kind' => 'attrfmt', 'format' => 'remote_ids', 'value' => $remoteIDs[1] );
        }
        return null;
    }

    /** Whether an ezobjectrelation(list) attribute's class restriction (if any) allows relating to $classIdentifier itself. */
    protected static function classAllowsRelation( eZContentClassAttribute $classAttribute, $classIdentifier )
    {
        $content = $classAttribute->content();
        $list = isset( $content['class_constraint_list'] ) ? (array)$content['class_constraint_list'] : array();
        return !$list || in_array( $classIdentifier, $list, true );
    }

    /**
     * The bundled sample file as a resolved path: the importer refuses any path containing "..", so
     * "classes/../share/..." made every image and file sample fail ("not an importable path").
     */
    protected static function sampleAssetPath( $name )
    {
        $path = dirname( dirname( __FILE__ ) ) . '/share/sample/' . $name;
        $real = realpath( $path );
        return $real !== false ? $real : $path;
    }
}
