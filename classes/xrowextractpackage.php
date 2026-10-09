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
    use XrowExtractFileModes;

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
     *
     * @param string $storedPath
     * @param string|null $originalName
     * @return 'package'|'contentclass'|'contentobject'|false
     */
    public static function detectUploadKind( $storedPath, $originalName ): string|false
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
                $sample = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', $sample ) ?? $sample );
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

    protected static function endsWith( string $haystack, string $needle ): bool
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
     *
     * @param string $path
     * @return array{ok: true, entries: list<string>, error: null}|array{ok: false, entries: list<string>, error: string}
     */
    public static function scanArchiveEntries( $path ): array
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
        // A disabled function does not exist in PHP 8: calling it would be an Error, not a false
        if ( !function_exists( 'proc_open' ) )
            return array( 'ok' => false, 'entries' => array(), 'error' => 'proc_open() is disabled on this server, so the archive cannot be checked' );
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

        // Fail closed: a listing that cannot be split refuses the archive rather than passing it as empty
        $lines = preg_split( '/\r?\n/', trim( $out ) );
        if ( $lines === false )
            return array( 'ok' => false, 'entries' => array(), 'error' => 'unreadable archive listing refused' );
        $entries = array();
        $error = null;
        foreach ( $lines as $line )
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

        return $error === null ? array( 'ok' => true, 'entries' => $entries, 'error' => null )
                               : array( 'ok' => false, 'entries' => $entries, 'error' => $error );
    }

    /**
     * Imports an uploaded .ezpkg/.tar.gz into the local package repository,
     * after scanArchiveEntries() has refused a path-traversal/symlink
     * archive. Returns array( 'ok', 'package' => eZPackage|null, 'error',
     * 'renamed' => bool, 'renamed_from', 'renamed_to' ) - the last three
     * from renamedArchiveCopyIfNeeded() (see there): an older or hand-built
     * .ezpkg whose own package.xml name is not a valid kernel identifier
     * (capitals, spaces...) is imported anyway, under a corrected name, with
     * enough returned for the caller to say so, instead of being refused.
     *
     * @param string $storedPath
     * @return array{ok: true, package: eZPackage, error: null, renamed: bool, renamed_from: string|null, renamed_to: string|null}|array{ok: false, package: null, error: string, renamed: false, renamed_from: null, renamed_to: null}
     */
    public static function importUploadedArchive( $storedPath ): array
    {
        // An absolute path: the kernel opens the archive as "compress.zlib://<path>", and a relative
        // one (var/site/cache/...) cannot be opened that way ("can not be opened for reading")
        $real = realpath( (string)$storedPath );
        if ( $real !== false )
            $storedPath = $real;
        $scan = self::scanArchiveEntries( $storedPath );
        if ( !$scan['ok'] )
            return array( 'ok' => false, 'package' => null, 'error' => $scan['error'], 'renamed' => false, 'renamed_from' => null, 'renamed_to' => null );

        $renameInfo = self::renamedArchiveCopyIfNeeded( $storedPath );
        $importPath = $renameInfo['path'];

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
            $imported = self::withNativeFileStreams( function () use ( $importPath, &$packageName ) {
                return eZPackage::import( $importPath, $packageName, true, 'local', false );
            } );
        }
        catch ( \Throwable $e )
        {
            if ( $renameInfo['renamed'] )
                @unlink( $importPath );
            return array( 'ok' => false, 'package' => null, 'error' => 'not a valid Exponential package (.ezpkg): ' . $e->getMessage(), 'renamed' => false, 'renamed_from' => null, 'renamed_to' => null );
        }
        if ( $renameInfo['renamed'] )
            @unlink( $importPath ); // the corrected copy has done its job either way, imported or not
        if ( $imported instanceof eZPackage )
            return array( 'ok' => true, 'package' => $imported, 'error' => null, 'renamed' => $renameInfo['renamed'], 'renamed_from' => $renameInfo['from'], 'renamed_to' => $renameInfo['to'] );
        if ( $imported === eZPackage::STATUS_ALREADY_EXISTS )
            return array( 'ok' => false, 'package' => null, 'error' => "a package named '$packageName' already exists in the repository", 'renamed' => false, 'renamed_from' => null, 'renamed_to' => null );
        if ( $imported === eZPackage::STATUS_INVALID_NAME )
            return array( 'ok' => false, 'package' => null, 'error' => "the package name '$packageName' is invalid", 'renamed' => false, 'renamed_from' => null, 'renamed_to' => null );
        return array( 'ok' => false, 'package' => null, 'error' => 'not a valid Exponential package (.ezpkg)', 'renamed' => false, 'renamed_from' => null, 'renamed_to' => null );
    }

    /**
     * If the uploaded archive's own package name (its package.xml <name>) is not a valid kernel
     * identifier - eZPackage::isValidName() refuses capitals, spaces and punctuation, true of some
     * hand-built or older .ezpkg files - returns a private corrected copy of the archive with only
     * that <name> rewritten to validPackageName()'s transform, every other byte untouched, so
     * eZPackage::import() (which otherwise answers STATUS_INVALID_NAME and refuses the whole
     * upload) accepts it. Mirrors exactly what eZPackage::exportToArchive() itself does to build an
     * archive (ezcArchive, TAR_USTAR, through the compress.zlib:// wrapper) - no re-implementation
     * of archive writing.
     *
     * Returns array( 'path' => original or corrected path, 'renamed' => bool, 'from' => the name
     * found in package.xml (or null if it could not be read at all), 'to' => the corrected name, or
     * null ). A 'path' equal to $storedPath (renamed false) is the normal, unmodified case; the
     * caller only has to @unlink() the result when 'renamed' is true.
     *
     * @return array{path: string, renamed: bool, from: string|null, to: string|null}
     */
    protected static function renamedArchiveCopyIfNeeded( string $storedPath ): array
    {
        $result = array( 'path' => $storedPath, 'renamed' => false, 'from' => null, 'to' => null );
        try
        {
            $archive = ezcArchive::open( "compress.zlib://$storedPath", ezcArchive::TAR_GNU, new ezcArchiveOptions( array( 'readOnly' => true ) ) );
        }
        catch ( \Throwable $e )
        {
            return $result; // not a readable archive at all; eZPackage::import() will say so itself
        }

        $peekDir = eZPackage::temporaryImportPath() . '/rename-peek-' . getmypid() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        eZDir::mkdir( $peekDir, false, true );
        foreach ( $archive as $entry )
        {
            if ( $entry->getPath() === eZPackage::definitionFilename() )
            {
                $archive->extractCurrent( $peekDir );
                break;
            }
        }
        $name = self::readPackageNameFromXML( $peekDir . '/' . eZPackage::definitionFilename() );
        eZDir::recursiveDelete( $peekDir );

        if ( $name === null || eZPackage::isValidName( $name ) )
        {
            $result['from'] = $name;
            return $result;
        }
        $result['from'] = $name;
        $newName = self::validPackageName( $name );

        $extractDir = eZPackage::temporaryImportPath() . '/rename-' . getmypid() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
        eZDir::mkdir( $extractDir, false, true );
        try
        {
            $archive->extract( $extractDir );
            $defPath = $extractDir . '/' . eZPackage::definitionFilename();
            $dom = new DOMDocument();
            if ( !is_file( $defPath ) || !@$dom->load( $defPath ) )
            {
                eZDir::recursiveDelete( $extractDir );
                return $result;
            }
            $nameNode = $dom->getElementsByTagName( 'name' )->item( 0 );
            if ( !$nameNode )
            {
                eZDir::recursiveDelete( $extractDir );
                return $result;
            }
            while ( $nameNode->firstChild )
                $nameNode->removeChild( $nameNode->firstChild );
            $nameNode->appendChild( $dom->createTextNode( $newName ) );
            $dom->save( $defPath );

            $tempArchiveFile = eZPackage::temporaryExportPath() . '/rename-archive-' . getmypid() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.tmp';
            eZDir::mkdir( dirname( $tempArchiveFile ), false, true );
            $writer = ezcArchive::open( $tempArchiveFile, ezcArchive::TAR_USTAR );
            $writer->truncate();
            $fileList = array();
            eZDir::recursiveList( $extractDir, $extractDir, $fileList );
            $prefix = $extractDir . '/';
            foreach ( $fileList as $fileInfo )
            {
                $entryPath = $fileInfo['type'] === 'dir' ? $fileInfo['path'] . '/' . $fileInfo['name'] . '/' : $fileInfo['path'] . '/' . $fileInfo['name'];
                $writer->append( array( $entryPath ), $prefix );
            }
            $writer->close();

            $correctedPath = XrowExtractImport::uploadDir() . '/renamed_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.ezpkg';
            copy( $tempArchiveFile, "compress.zlib://$correctedPath" );
            @unlink( $tempArchiveFile );
            eZDir::recursiveDelete( $extractDir );

            $result['path'] = $correctedPath;
            $result['renamed'] = true;
            $result['to'] = $newName;
            return $result;
        }
        catch ( \Throwable $e )
        {
            eZDir::recursiveDelete( $extractDir );
            return array( 'path' => $storedPath, 'renamed' => false, 'from' => $name, 'to' => null ); // fall through; import() will refuse it as before
        }
    }

    /** The <name> package.xml's root element carries, or null if the file cannot be read as one. */
    protected static function readPackageNameFromXML( string $path ): ?string
    {
        if ( !is_file( $path ) )
            return null;
        $dom = new DOMDocument();
        if ( !@$dom->load( $path ) )
            return null;
        $nameNode = $dom->getElementsByTagName( 'name' )->item( 0 );
        return $nameNode ? trim( $nameNode->textContent ) : null;
    }

    /**
     * Builds a transient, local package wrapping one standalone content-class
     * or content-object XML file (as detectUploadKind() found), so it can be
     * inspected and installed through the exact same eZPackage/
     * XrowExtractPackage::inspect()/install() code as a full .ezpkg - "build
     * a transient package around it", per the class/content XML upload
     * requirement. Returns array( 'ok', 'package', 'error' ).
     *
     * @param string $storedPath
     * @param string $kind
     * @param string $originalName
     * @return array{ok: true, package: eZPackage, error: null}|array{ok: false, package: null, error: string}
     */
    public static function wrapStandaloneXML( $storedPath, $kind, $originalName = '' ): array
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

    /**
     * Packages in the repository that carry a content class or content object install item.
     *
     * @return list<array{name: string, summary: string, version: string, is_installed: bool, install_type: string, has_classes: bool, has_objects: bool, class_count: int, object_item_count: int}>
     */
    public static function repositoryPackages(): array
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

    /** @return list<string> */
    protected static function installItemTypes( eZPackage $package ): array
    {
        $types = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) )
                $types[$item['type']] = true;
        return array_keys( $types );
    }

    protected static function countItems( eZPackage $package, string $type ): int
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
     *
     * @return list<array<string, mixed>>
     */
    protected static function installItemsOfType( eZPackage $package, string $type ): array
    {
        $matches = array();
        foreach ( $package->installItemsList() as $item )
            if ( isset( $item['type'] ) && $item['type'] === $type )
                $matches[] = $item;
        return $matches;
    }

    // ------------------------------------------------------------ metadata

    /**
     * Package-level metadata for the inspect screen: name, summary, version, licence, dependencies, changelog.
     *
     * @return array<string, mixed>
     */
    public static function packageMeta( eZPackage $package ): array
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

    /** How long a cached dry run is used before it is worked out again (seconds). */
    const INSPECTION_CACHE_TTL = 900;
    /** Raised whenever inspect()'s result gains or changes a key, so a dry run cached by older code is not reused. */
    const INSPECTION_CACHE_VERSION = 2;

    /**
     * The dry run of inspect(), cached per package, package version on disk and parent: a large package takes
     * seconds to compare with the site (4339 objects: about 9 s and 200 MB), and the package views page through
     * the same result, so it is worked out once and reused for INSPECTION_CACHE_TTL seconds, until $refresh
     * ("Check again"), or until an install of that package clears it (forgetInspections()).
     * Adds 'checked_at' (when it was worked out) and 'cached' (whether this call reused it).
     *
     * @param int|string|false|null $parentNodeID
     * @param bool $refresh
     * @return array<string, mixed>
     */
    public static function cachedInspection( eZPackage $package, $parentNodeID = false, $refresh = false ): array
    {
        $dir = eZSys::cacheDirectory() . '/xrowextract/inspect';
        $definition = rtrim( (string)$package->path(), '/' ) . '/package.xml';
        $version = is_file( $definition ) ? (int)@filemtime( $definition ) : 0;
        $file = $dir . '/' . self::inspectionCachePrefix( $package->attribute( 'name' ) )
              . md5( self::INSPECTION_CACHE_VERSION . '|' . $version . '|' . (int)$parentNodeID ) . '.json';
        if ( !$refresh && is_file( $file ) && ( time() - (int)@filemtime( $file ) ) < self::INSPECTION_CACHE_TTL )
        {
            $cached = json_decode( (string)@file_get_contents( $file ), true );
            if ( is_array( $cached ) && isset( $cached['objects'] ) )
            {
                $cached['cached'] = true;
                return $cached;
            }
        }
        $inspection = self::inspect( $package, $parentNodeID );
        $inspection['checked_at'] = time();
        self::ensureCacheDir( $dir );
        $tmp = $file . '.' . getmypid() . '.tmp';
        if ( @file_put_contents( $tmp, json_encode( $inspection, JSON_INVALID_UTF8_SUBSTITUTE ) ) !== false )
        {
            @rename( $tmp, $file );
            if ( class_exists( 'XrowExtractJob' ) )
                XrowExtractJob::fixOwnership( $file );
        }
        $inspection['cached'] = false;
        return $inspection;
    }

    /**
     * Forgets every cached dry run of a package (after it was installed, the site no longer matches it).
     *
     * @param string $packageName
     */
    public static function forgetInspections( $packageName ): void
    {
        foreach ( glob( eZSys::cacheDirectory() . '/xrowextract/inspect/' . self::inspectionCachePrefix( $packageName ) . '*.json' ) ?: array() as $file )
            @unlink( $file );
    }

    /**
     * Creates a cache folder below var/<site>/cache/xrowextract and, when this process is root (Velocity,
     * a root command line), hands it and its parent back to the var directory's owner: a folder root
     * created first would otherwise keep PHP-FPM from ever writing a cached dry run or comparison there.
     */
    protected static function ensureCacheDir( string $dir ): void
    {
        if ( !is_dir( $dir ) )
            eZDir::mkdir( $dir, false, true );
        if ( class_exists( 'XrowExtractJob' ) )
        {
            XrowExtractJob::fixOwnership( dirname( $dir ) );
            XrowExtractJob::fixOwnership( $dir );
        }
    }

    /** @param string|null $packageName */
    protected static function inspectionCachePrefix( $packageName ): string
    {
        return preg_replace( '/[^a-z0-9_]+/', '_', strtolower( (string)$packageName ) ) . '__';
    }

    /**
     * The dry run: every content class and content object install item, matched
     * against what already exists on this site by remote id (classes fall back
     * to identifier). Nothing is written. $parentNodeID (the parent chosen in
     * the install form, when known) is only used to describe where a new
     * top-level object would land - it changes nothing about matching.
     *
     * @param int|string|false|null $parentNodeID
     * @return array<string, mixed>
     */
    public static function inspect( eZPackage $package, $parentNodeID = false ): array
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

        // Which datatype the package's classes and objects use, and where: the datatype check
        $datatypeUsage = array();
        foreach ( $classes as $classRow )
            self::addClassDatatypes( $datatypeUsage, $classRow['identifier'], $classRow['attributes'] );

        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
        {
            foreach ( self::inspectObjectItem( $package, $item, $classRemoteIDsInPackage, $classIdentifiersInPackage, $parentNodeID, $datatypeUsage ) as $row )
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
            'datatypes' => self::datatypeUsageList( $datatypeUsage ),
            'missing_datatypes' => self::missingDatatypes( $datatypeUsage ),
        );
    }

    // ------------------------------------------------------------ datatype check
    //
    // Every datatype a package's classes (their attribute definitions) and objects (the type of every
    // attribute they carry) use, against the datatypes this site has: content.ini [DataTypeSettings]
    // AvailableDataTypes, each one loadable (an extension that registers a datatype but is not active, or
    // whose class file is gone, does not count). A missing one does not stop an install - the kernel skips
    // what it cannot read - but the page, the Import review and the install job's log say so clearly.

    /**
     * $usage[datatype] = array( 'classes' => identifier => true, 'objects' => n, 'object_classes' => identifier => true ).
     *
     * @param array<string, array{classes: array<string, true>, objects: int, object_classes: array<string, true>}> $usage
     * @param string $classIdentifier
     * @param array<array<string, mixed>> $attributeRows
     */
    public static function addClassDatatypes( array &$usage, $classIdentifier, array $attributeRows ): void
    {
        foreach ( $attributeRows as $attribute )
        {
            $datatype = isset( $attribute['datatype'] ) ? (string)$attribute['datatype'] : '';
            if ( $datatype === '' )
                continue;
            if ( !isset( $usage[$datatype] ) )
                $usage[$datatype] = array( 'classes' => array(), 'objects' => 0, 'object_classes' => array() );
            if ( $classIdentifier !== '' )
                $usage[$datatype]['classes'][$classIdentifier] = true;
        }
    }

    /**
     * Counts the datatypes one object's XML carries (each once per object, whatever its languages).
     *
     * @param array<string, array{classes: array<string, true>, objects: int, object_classes: array<string, true>}> $usage
     * @param string $classIdentifier
     */
    public static function addObjectDatatypes( array &$usage, DOMElement $objectNode, $classIdentifier ): void
    {
        $seen = array();
        foreach ( $objectNode->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' ) as $attrNode )
        {
            $datatype = (string)$attrNode->getAttribute( 'type' );
            if ( $datatype !== '' )
                $seen[$datatype] = true;
        }
        foreach ( array_keys( $seen ) as $datatype )
        {
            if ( !isset( $usage[$datatype] ) )
                $usage[$datatype] = array( 'classes' => array(), 'objects' => 0, 'object_classes' => array() );
            $usage[$datatype]['objects']++;
            if ( $classIdentifier !== '' )
                $usage[$datatype]['object_classes'][$classIdentifier] = true;
        }
    }

    /**
     * The datatypes this site can use (see above), cached per request.
     *
     * @return array<string, true>
     */
    public static function siteDatatypes(): array
    {
        // Per request, as the comment says (a static was per Velocity worker): the datatypes are the
        // siteaccess's, and one worker serves several
        $cache =& XrowExtractColumns::requestCache( 'site_datatypes' );
        if ( !isset( $cache['available'] ) )
        {
            // Registered, not instantiated: eZDataType::create() would construct every datatype, and one
            // with a constructor that needs arguments throws (seen with an extension datatype).
            $available = array();
            foreach ( (array)eZDataType::allowedTypes() as $datatype )
            {
                if ( !isset( $GLOBALS['eZDataTypes'][$datatype] ) )
                    eZDataType::loadAndRegisterType( $datatype );
                if ( isset( $GLOBALS['eZDataTypes'][$datatype] ) )
                    $available[$datatype] = true;
            }
            $cache['available'] = $available;
        }
        return $cache['available'];
    }

    /**
     * $usage as a sorted list: datatype, classes (identifiers), objects (count), object_classes, available.
     *
     * @param array<string, array{classes: array<string, true>, objects: int, object_classes: array<string, true>}> $usage
     * @return list<array{datatype: string, classes: list<string>, objects: int, object_classes: list<string>, available: bool}>
     */
    public static function datatypeUsageList( array $usage ): array
    {
        $site = self::siteDatatypes();
        $list = array();
        foreach ( $usage as $datatype => $where )
        {
            $list[] = array(
                'datatype' => (string)$datatype,
                'classes' => array_keys( $where['classes'] ),
                'objects' => (int)$where['objects'],
                'object_classes' => array_keys( $where['object_classes'] ),
                'available' => isset( $site[$datatype] ),
            );
        }
        usort( $list, function ( $a, $b ) { return strcmp( $a['datatype'], $b['datatype'] ); } );
        return $list;
    }

    /**
     * Only the rows of datatypeUsageList() this site does not have.
     *
     * @param array<string, array{classes: array<string, true>, objects: int, object_classes: array<string, true>}> $usage
     * @return list<array{datatype: string, classes: list<string>, objects: int, object_classes: list<string>, available: bool}>
     */
    public static function missingDatatypes( array $usage ): array
    {
        return array_values( array_filter( self::datatypeUsageList( $usage ), function ( $row ) { return !$row['available']; } ) );
    }

    /**
     * One line per missing datatype, for the command line and the install job's log (without the "WARNING: " prefix).
     *
     * @param array<array<string, mixed>> $missing rows of missingDatatypes()
     * @return list<string>
     */
    public static function missingDatatypeLines( array $missing ): array
    {
        $lines = array();
        foreach ( $missing as $row )
        {
            $where = array();
            if ( $row['classes'] )
                $where[] = 'class ' . implode( ', ', $row['classes'] );
            if ( $row['objects'] )
                $where[] = $row['objects'] . ' object(s)' . ( $row['object_classes'] ? ' of ' . implode( ', ', $row['object_classes'] ) : '' );
            $lines[] = 'The package uses the datatype ' . $row['datatype'] . ', which this site does not have (' . implode( '; ', $where )
                     . '). Its values are not installed; install or enable the extension that provides it first.';
        }
        return $lines;
    }

    // ------------------------------------------------------------ inspection object list: filter

    /**
     * The inspection's objects narrowed to what the Package tab's filters ask for: $state (create, update,
     * unchanged, class_missing or '' for all), $classIdentifier ('' for all) and $text (a case-insensitive
     * part of the name or remote id). Works on the cached dry run alone; the order is kept.
     *
     * @param array<array<string, mixed>> $objects
     * @param string $state
     * @param string $classIdentifier
     * @param string|null $text
     * @return list<array<string, mixed>>
     */
    public static function filterInspectionObjects( array $objects, $state = '', $classIdentifier = '', $text = '' ): array
    {
        $text = trim( (string)$text );
        if ( $state === '' && $classIdentifier === '' && $text === '' )
            return array_values( $objects );
        $out = array();
        foreach ( $objects as $object )
        {
            if ( $state !== '' && $object['state'] !== $state )
                continue;
            if ( $classIdentifier !== '' && $object['class_identifier'] !== $classIdentifier )
                continue;
            if ( $text !== '' && mb_stripos( (string)$object['name'], $text ) === false && mb_stripos( (string)$object['remote_id'], $text ) === false )
                continue;
            $out[] = $object;
        }
        return $out;
    }

    /**
     * Per class identifier: how many of the inspection's objects are of it, sorted by identifier (the class filter's choices).
     *
     * @param array<array<string, mixed>> $objects
     * @return list<array{identifier: string, count: int}>
     */
    public static function inspectionObjectClasses( array $objects ): array
    {
        $counts = array();
        foreach ( $objects as $object )
        {
            $identifier = (string)$object['class_identifier'];
            $counts[$identifier] = isset( $counts[$identifier] ) ? $counts[$identifier] + 1 : 1;
        }
        ksort( $counts );
        $out = array();
        foreach ( $counts as $identifier => $count )
            $out[] = array( 'identifier' => $identifier, 'count' => $count );
        return $out;
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
     *
     * @param array<string, mixed> $inspection inspect()'s result
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, ezoe: bool}
     */
    public static function inspectionToResultRows( array $inspection ): array
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

    /**
     * Every file a package carries outside its XML install items (simplefiles/, images/), with sizes.
     *
     * @return list<array{path: string, size: int|false}>
     */
    public static function packageFiles( eZPackage $package ): array
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

    // ------------------------------------------------------------ package contents browser (#26)
    //
    // Every file the package's own directory carries, recursively - not only the attached binaries
    // packageFiles() above lists, but package.xml itself, every ezcontentclass/ezcontentobject item
    // file, documents/*.txt, settings/* and so on. The list itself carries no limit; xrowextract/
    // browse paginates over it. Shared by the Import page, the Package tab (a short preview, "browse
    // all N files" beyond it) and xrowextract/browse (the full, paginated page a link from the
    // kernel's own package/view/full reaches).

    /**
     * Every regular file under the package's own directory, recursively, sorted by path. No limit: paginate the result, do not slice it here.
     *
     * @return list<array{path: string, size: int|false, size_human: string, kind: string}>
     */
    public static function allPackageFiles( eZPackage $package ): array
    {
        $out = array();
        $base = rtrim( (string)$package->path(), '/' );
        if ( !is_dir( $base ) )
            return $out;
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file )
        {
            if ( !$file->isFile() )
                continue;
            $relative = ltrim( str_replace( $base, '', $file->getPathname() ), '/' );
            $out[] = array(
                'path' => $relative, 'size' => $file->getSize(),
                'size_human' => XrowExtractUpload::humanSize( $file->getSize() ),
                'kind' => self::fileKind( $relative ),
            );
        }
        usort( $out, function ( $a, $b ) { return strcasecmp( $a['path'], $b['path'] ); } );
        return $out;
    }

    /**
     * The first $limit rows of allPackageFiles(), each with its 'index' into the whole (not just
     * this slice's own position - see modules/xrowextract/browse.php's own comment on why an index,
     * not a path, identifies a file in a URL), for the short preview embedded on the Import page and
     * the Package tab (design:xrowextract/package_files_preview.tpl). Returns array( 'files',
     * 'total' ) - 'total' is allPackageFiles()'s own count, always, not count( 'files' ).
     *
     * @param int $limit
     * @return array{files: list<array{path: string, size: int|false, size_human: string, kind: string, index: int}>, total: int}
     */
    public static function packageFilesPreview( eZPackage $package, $limit = 10 ): array
    {
        $all = self::allPackageFiles( $package );
        $slice = array_slice( $all, 0, $limit );
        foreach ( $slice as $i => &$row )
            $row['index'] = $i;
        unset( $row );
        return array( 'files' => $slice, 'total' => count( $all ) );
    }

    /**
     * 'xml', 'image', 'text' or 'binary' by extension - what the browser shows inline, and how.
     *
     * @param string $relativePath
     */
    public static function fileKind( $relativePath ): string
    {
        $ext = strtolower( (string)pathinfo( $relativePath, PATHINFO_EXTENSION ) );
        if ( $ext === 'xml' )
            return 'xml';
        if ( in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ), true ) )
            return 'image';
        if ( in_array( $ext, array( 'txt', 'md', 'csv', 'json', 'ini' ), true ) )
            return 'text';
        return 'binary';
    }

    /**
     * The content type xrowextract/browse_file answers a file with. An image's own real type; every
     * other kind (including xml) as text/plain - an .xml or .txt item is shown as data here, never
     * served as a type a browser would try to render as markup or execute.
     *
     * @param string $relativePath
     */
    public static function fileMimeType( $relativePath ): string
    {
        $ext = strtolower( (string)pathinfo( $relativePath, PATHINFO_EXTENSION ) );
        $map = array(
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        );
        return isset( $map[$ext] ) ? $map[$ext] : 'text/plain; charset=utf-8';
    }

    /**
     * Resolves $relativePath against $package's own directory, refusing an absolute path, a ".."
     * component, or anything realpath() follows (a symlink included) outside it. Returns the real,
     * safe, absolute path to an existing regular file, or false - the one gate both the inline
     * viewer and the raw-byte view (xrowextract/browse_file) read a package file through.
     *
     * @param string|null $relativePath
     */
    public static function packageFilePath( eZPackage $package, $relativePath ): string|false
    {
        $relativePath = ltrim( (string)$relativePath, '/' );
        if ( $relativePath === '' || strpos( $relativePath, "\0" ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $relativePath ) )
            return false;
        $base = realpath( (string)$package->path() );
        if ( $base === false )
            return false;
        $real = realpath( $base . '/' . $relativePath );
        // Containment by the real, resolved paths, not a string prefix check ("/x/pkg2" starting
        // with the characters "/x/pkg" would wrongly pass a bare substr/strpos-at-0 test)
        if ( $real === false || !is_file( $real ) || strpos( $real . '/', $base . '/' ) !== 0 )
            return false;
        return $real;
    }

    /**
     * Pretty-printed XML (indented, no run-together text nodes), or the original bytes unchanged if they do not parse as XML.
     *
     * @param string|false|null $bytes
     */
    public static function prettyPrintXML( $bytes ): string
    {
        $bytes = (string)$bytes;
        // DOMDocument::loadXML() throws a ValueError for an empty string (the @ does not silence it)
        if ( trim( $bytes ) === '' )
            return $bytes;
        $dom = new DOMDocument( '1.0', 'utf-8' );
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        if ( !@$dom->loadXML( $bytes ) )
            return $bytes;
        $pretty = $dom->saveXML();
        return $pretty !== false ? $pretty : $bytes;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    protected static function inspectClassItem( eZPackage $package, array $item ): ?array
    {
        $row = self::readClassItem( $package, $item );
        if ( $row === null )
            return null;
        $existing = $row['remote_id'] ? eZContentClass::fetchByRemoteID( $row['remote_id'] ) : null;
        if ( !$existing && $row['identifier'] )
            $existing = eZContentClass::fetchByIdentifier( $row['identifier'] );
        $row['state'] = $existing instanceof eZContentClass ? 'update' : 'create';
        $row['existing_id'] = $existing instanceof eZContentClass ? (int)$existing->attribute( 'id' ) : null;
        $row['diff'] = $existing instanceof eZContentClass ? self::classAttributeDiff( $row['attributes'], $existing ) : null;
        return $row;
    }

    /**
     * A content class install item as the package's own XML states it - identifier, remote id, name and
     * attributes (identifier, datatype, required) - without looking at the site at all: what inspectClassItem()
     * then matches, and what comparePackages() compares between two packages.
     *
     * @param array<string, mixed> $item
     * @return array{identifier: string, remote_id: string, name: string, attributes: list<array{identifier: string, datatype: string, required: bool}>, attribute_count: int, file_path: string|null}|null
     */
    protected static function readClassItem( eZPackage $package, array $item ): ?array
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
                // The datatype is the attribute's own datatype="..." (what eZContentClassPackageHandler
                // writes); a <type> element inside it is one of the datatype's parameters (an ezmedia's
                // player type, an ezobjectrelationlist's "0"), read only when datatype="" is absent.
                $datatype = (string)$attrNode->getAttribute( 'datatype' );
                if ( $datatype === '' )
                {
                    $attrTypeNode = $attrNode->getElementsByTagName( 'type' )->item( 0 );
                    $datatype = $attrTypeNode ? (string)$attrTypeNode->textContent : '';
                }
                $attributeRows[] = array(
                    'identifier' => $attrIdentifierNode ? $attrIdentifierNode->textContent : '',
                    'datatype'   => $datatype,
                    'required'   => $attrNode->getAttribute( 'required' ) === 'true',
                );
            }
        }

        return array(
            'identifier'  => $identifier,
            'remote_id'   => $remoteID,
            'name'        => $name,
            'attributes'  => $attributeRows,
            'attribute_count' => count( $attributeRows ),
            'file_path'   => self::itemRelativePath( $item ),
        );
    }

    /**
     * The item's own file, relative to the package's root - what fetchItemDOM() reads, and the same path allPackageFiles() reports it under.
     *
     * @param array<string, mixed> $item
     */
    protected static function itemRelativePath( array $item ): ?string
    {
        if ( empty( $item['filename'] ) )
            return null;
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        return ( $subdirectory ? $subdirectory . '/' : '' ) . $item['filename'] . '.xml';
    }

    /**
     * Attributes added, removed or changed datatype, package vs. the installed class of the same identifier/remote id.
     *
     * @param array<array{identifier: string, datatype: string, required?: bool}> $packageAttributeRows
     * @return array{added: list<array{identifier: string, datatype: string}>, removed: list<array{identifier: string, datatype: string}>, changed: list<array{identifier: string, old_datatype: string, new_datatype: string}>, has_changes: bool}
     */
    protected static function classAttributeDiff( array $packageAttributeRows, eZContentClass $existingClass ): array
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

    /**
     * One install item can carry many content objects (inline or one XML file per object).
     *
     * @param array<string, mixed> $item
     * @param array<string, true> $classRemoteIDsInPackage
     * @param array<string, true> $classIdentifiersInPackage
     * @param int|string|false|null $parentNodeID
     * @param array<string, array{classes: array<string, true>, objects: int, object_classes: array<string, true>}> $datatypeUsage
     * @return list<array<string, mixed>>
     */
    protected static function inspectObjectItem( eZPackage $package, array $item, array $classRemoteIDsInPackage, array $classIdentifiersInPackage, $parentNodeID = false, array &$datatypeUsage = array() ): array
    {
        $rows = array();
        foreach ( self::objectDOMNodesWithPaths( $package, $item ) as $pair )
        {
            $objectNode = $pair['node'];
            $name = $objectNode->getAttribute( 'name' );
            $remoteID = $objectNode->getAttribute( 'remote_id' );
            $classRemoteID = $objectNode->getAttribute( 'class_remote_id' );
            $classIdentifier = $objectNode->getAttributeNS( 'http://ez.no/ezobject', 'class_identifier' );
            self::addObjectDatatypes( $datatypeUsage, $objectNode, $classIdentifier );
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
                'file_path'        => $pair['file_path'],
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
     *
     * @return array<string, array{source: string, xml: string, bool?: bool}>
     */
    protected static function diffableAttributeFields(): array
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

    /**
     * Old (live) -> new (package) value per attribute, for the datatypes diffableAttributeFields() covers; only attributes that actually differ are returned.
     *
     * @return list<array{identifier: string, datatype: string, language: string, old: string, new: string}>
     */
    protected static function objectFieldChanges( DOMElement $objectNode, eZContentObject $existing ): array
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
    protected static function mutateOneDiffableAttributeValue( DOMElement $objectNode ): bool
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
                // A node read from a document always has one; without it there is nothing to edit
                if ( !$childNode->ownerDocument instanceof DOMDocument )
                    return false;
                while ( $childNode->firstChild )
                    $childNode->removeChild( $childNode->firstChild );
                $childNode->appendChild( $childNode->ownerDocument->createTextNode( $new ) );
                return true;
            }
        }
        return false;
    }

    /**
     * The same as objectDOMNodes() below, paired with the file each node actually lives in
     * (relative to the package's own root, the same path allPackageFiles() reports it under) - for
     * the package contents browser's own "view this item's file" link (#26), where an inline
     * object-list's every object shares the item's own file, but an object-files-list gives each
     * object a separate one.
     *
     * @param array<string, mixed> $item
     * @return list<array{node: DOMElement, file_path: string|null}>
     */
    protected static function objectDOMNodesWithPaths( eZPackage $package, array $item ): array
    {
        if ( empty( $item['filename'] ) )
            return array();
        $dom = self::fetchItemDOM( $package, $item );
        if ( !$dom )
            return array();

        $itemPath = self::itemRelativePath( $item );
        $objectListNode = $dom->getElementsByTagName( 'object-list' )->item( 0 );
        if ( $objectListNode )
        {
            $pairs = array();
            foreach ( $objectListNode->getElementsByTagName( 'object' ) as $node )
                $pairs[] = array( 'node' => $node, 'file_path' => $itemPath );
            return $pairs;
        }

        $pairs = array();
        $objectFilesListNode = $dom->getElementsByTagName( 'object-files-list' )->item( 0 );
        if ( !$objectFilesListNode )
            return $pairs;
        $handler = eZPackage::packageHandler( 'ezcontentobject' );
        $directory = $handler ? $handler->contentObjectDirectory() : 'ezcontentobject';
        foreach ( $objectFilesListNode->getElementsByTagName( 'object-file' ) as $fileNode )
        {
            $relativePath = $directory . '/' . $fileNode->getAttribute( 'filename' );
            $objectDOM = $package->fetchDOMFromFile( $package->path() . '/' . $relativePath );
            if ( $objectDOM )
                $pairs[] = array( 'node' => $objectDOM->documentElement, 'file_path' => $relativePath );
        }
        return $pairs;
    }

    /**
     * DOM nodes for every content object an install item carries, inline or in separate files.
     *
     * @param array<string, mixed> $item
     * @return array<int, DOMElement>
     */
    protected static function objectDOMNodes( eZPackage $package, array $item ): array
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

    /**
     * The document element of an install item's own XML file (class or top-level content object file).
     *
     * @param array<string, mixed> $item
     */
    protected static function fetchItemDOM( eZPackage $package, array $item ): DOMElement|false|null
    {
        $filename = $item['filename'];
        $subdirectory = isset( $item['sub-directory'] ) ? $item['sub-directory'] : false;
        $filePath = $subdirectory ? $subdirectory . '/' . $filename . '.xml' : $filename . '.xml';
        $filePath = $package->path() . '/' . $filePath;
        $dom = $package->fetchDOMFromFile( $filePath );
        return $dom ? $dom->documentElement : false;
    }

    // ------------------------------------------------------------ compare two packages
    //
    // What changes between two packages, read from their own XML only (nothing on the site is looked at):
    // classes and objects matched by remote id (a class without one by identifier), each one only in the
    // first package, only in the second, or in both with differences - for a class its name and its
    // attributes (added, removed, datatype changed), for an object its name, class, modified date and
    // every attribute whose serialized value differs, per language. Cached like the dry run.

    /** How long a cached comparison is reused (seconds). */
    const COMPARE_CACHE_TTL = 900;

    /**
     * Classes and objects of one package as its XML states them, keyed for matching (see above).
     *
     * @return array{classes: array<string, array{identifier: string, remote_id: string, name: string, attributes: array<string, string>}>, objects: array<string, array{remote_id: string, name: string, class_identifier: string, modified: string, languages: list<string>, fields: array<string, array{identifier: string, language: string, datatype: string, hash: string, text: string}>}>}
     */
    public static function packageSnapshot( eZPackage $package ): array
    {
        $classes = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::readClassItem( $package, $item );
            if ( !$row )
                continue;
            $attributes = array();
            foreach ( $row['attributes'] as $attribute )
                if ( $attribute['identifier'] !== '' )
                    $attributes[$attribute['identifier']] = $attribute['datatype'];
            $key = $row['remote_id'] !== '' ? $row['remote_id'] : 'identifier:' . $row['identifier'];
            $classes[$key] = array( 'identifier' => $row['identifier'], 'remote_id' => $row['remote_id'], 'name' => $row['name'], 'attributes' => $attributes );
        }
        $objects = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
        {
            foreach ( self::objectDOMNodes( $package, $item ) as $node )
            {
                $remoteID = (string)$node->getAttribute( 'remote_id' );
                if ( $remoteID === '' )
                    continue;
                $fields = array();
                $languages = array();
                foreach ( $node->getElementsByTagName( 'object-translation' ) as $translationNode )
                {
                    $language = (string)$translationNode->getAttribute( 'language' );
                    $languages[$language] = true;
                    foreach ( $translationNode->getElementsByTagNameNS( 'http://ez.no/object/', 'attribute' ) as $attrNode )
                    {
                        $identifier = (string)$attrNode->getAttributeNS( 'http://ez.no/ezobject', 'identifier' );
                        if ( $identifier === '' )
                            continue;
                        $text = trim( preg_replace( '/\s+/u', ' ', (string)$attrNode->textContent ) ?? (string)$attrNode->textContent );
                        if ( $text === '' )
                        {
                            // A value held in XML attributes only (a relation's remote ids, a date's timestamp)
                            $parts = array();
                            foreach ( $attrNode->getElementsByTagName( '*' ) as $inner )
                                foreach ( $inner->attributes as $xmlAttribute )
                                    $parts[] = $xmlAttribute->nodeName . '=' . $xmlAttribute->nodeValue;
                            $text = implode( ' ', $parts );
                        }
                        $fields[$identifier . '|' . $language] = array(
                            'identifier' => $identifier, 'language' => $language, 'datatype' => (string)$attrNode->getAttribute( 'type' ),
                            'hash' => md5( (string)$attrNode->C14N() ),
                            'text' => mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 160 ) . ' …' : $text,
                        );
                    }
                }
                $objects[$remoteID] = array(
                    'remote_id' => $remoteID,
                    'name' => (string)$node->getAttribute( 'name' ),
                    'class_identifier' => (string)$node->getAttributeNS( 'http://ez.no/ezobject', 'class_identifier' ),
                    'modified' => (string)$node->getAttributeNS( 'http://ez.no/ezobject', 'modified' ),
                    'languages' => array_keys( $languages ),
                    'fields' => $fields,
                );
            }
        }
        return array( 'classes' => $classes, 'objects' => $objects );
    }

    /**
     * $from compared with $to: 'classes' and 'objects', each a list of rows with 'change' (added: only in $to,
     * removed: only in $from, changed), plus 'counts' (added/removed/changed/unchanged per kind) and the two
     * packages' names and versions.
     *
     * @return array<string, mixed>
     */
    public static function comparePackages( eZPackage $from, eZPackage $to ): array
    {
        $a = self::packageSnapshot( $from );
        $b = self::packageSnapshot( $to );
        $counts = array();
        foreach ( array( 'classes', 'objects' ) as $kind )
            $counts[$kind] = array( 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0 );

        $classRows = array();
        foreach ( $b['classes'] as $key => $class )
        {
            if ( !isset( $a['classes'][$key] ) )
            {
                $classRows[] = array_merge( self::classCompareRow( $class ), array( 'change' => 'added', 'differences' => array() ) );
                $counts['classes']['added']++;
                continue;
            }
            $old = $a['classes'][$key];
            $differences = array();
            if ( $old['name'] !== $class['name'] )
                $differences[] = array( 'field' => 'name', 'kind' => 'changed', 'old' => $old['name'], 'new' => $class['name'] );
            if ( $old['identifier'] !== $class['identifier'] )
                $differences[] = array( 'field' => 'identifier', 'kind' => 'changed', 'old' => $old['identifier'], 'new' => $class['identifier'] );
            foreach ( $class['attributes'] as $identifier => $datatype )
            {
                if ( !isset( $old['attributes'][$identifier] ) )
                    $differences[] = array( 'field' => $identifier, 'kind' => 'added', 'old' => '', 'new' => $datatype );
                elseif ( $old['attributes'][$identifier] !== $datatype )
                    $differences[] = array( 'field' => $identifier, 'kind' => 'changed', 'old' => $old['attributes'][$identifier], 'new' => $datatype );
            }
            foreach ( $old['attributes'] as $identifier => $datatype )
                if ( !isset( $class['attributes'][$identifier] ) )
                    $differences[] = array( 'field' => $identifier, 'kind' => 'removed', 'old' => $datatype, 'new' => '' );
            if ( $differences )
            {
                $classRows[] = array_merge( self::classCompareRow( $class ), array( 'change' => 'changed', 'differences' => $differences ) );
                $counts['classes']['changed']++;
            }
            else
                $counts['classes']['unchanged']++;
        }
        foreach ( $a['classes'] as $key => $class )
        {
            if ( !isset( $b['classes'][$key] ) )
            {
                $classRows[] = array_merge( self::classCompareRow( $class ), array( 'change' => 'removed', 'differences' => array() ) );
                $counts['classes']['removed']++;
            }
        }

        $objectRows = array();
        foreach ( $b['objects'] as $remoteID => $object )
        {
            if ( !isset( $a['objects'][$remoteID] ) )
            {
                $objectRows[] = array_merge( self::objectCompareRow( $object ), array( 'change' => 'added', 'differences' => array() ) );
                $counts['objects']['added']++;
                continue;
            }
            $old = $a['objects'][$remoteID];
            $differences = array();
            foreach ( array( 'name', 'class_identifier', 'modified' ) as $property )
                if ( $old[$property] !== $object[$property] )
                    $differences[] = array( 'field' => $property, 'language' => '', 'kind' => 'changed', 'old' => $old[$property], 'new' => $object[$property] );
            foreach ( $object['fields'] as $key => $field )
            {
                if ( !isset( $old['fields'][$key] ) )
                    $differences[] = array( 'field' => $field['identifier'], 'language' => $field['language'], 'kind' => 'added', 'old' => '', 'new' => $field['text'] );
                elseif ( $old['fields'][$key]['hash'] !== $field['hash'] )
                    $differences[] = array( 'field' => $field['identifier'], 'language' => $field['language'], 'kind' => 'changed', 'old' => $old['fields'][$key]['text'], 'new' => $field['text'] );
            }
            foreach ( $old['fields'] as $key => $field )
                if ( !isset( $object['fields'][$key] ) )
                    $differences[] = array( 'field' => $field['identifier'], 'language' => $field['language'], 'kind' => 'removed', 'old' => $field['text'], 'new' => '' );
            if ( $differences )
            {
                $objectRows[] = array_merge( self::objectCompareRow( $object ), array( 'change' => 'changed', 'differences' => $differences ) );
                $counts['objects']['changed']++;
            }
            else
                $counts['objects']['unchanged']++;
        }
        foreach ( $a['objects'] as $remoteID => $object )
        {
            if ( !isset( $b['objects'][$remoteID] ) )
            {
                $objectRows[] = array_merge( self::objectCompareRow( $object ), array( 'change' => 'removed', 'differences' => array() ) );
                $counts['objects']['removed']++;
            }
        }

        return array(
            'from' => array( 'name' => $from->attribute( 'name' ), 'version' => (string)$from->attribute( 'version-number' ), 'summary' => (string)$from->attribute( 'summary' ) ),
            'to' => array( 'name' => $to->attribute( 'name' ), 'version' => (string)$to->attribute( 'version-number' ), 'summary' => (string)$to->attribute( 'summary' ) ),
            'classes' => $classRows,
            'objects' => $objectRows,
            'counts' => $counts,
        );
    }

    /**
     * @param array{identifier: string, remote_id: string, name: string, attributes: array<string, string>} $class
     * @return array{identifier: string, remote_id: string, name: string, attribute_count: int}
     */
    protected static function classCompareRow( array $class ): array
    {
        return array( 'identifier' => $class['identifier'], 'remote_id' => $class['remote_id'], 'name' => $class['name'], 'attribute_count' => count( $class['attributes'] ) );
    }

    /**
     * @param array{remote_id: string, name: string, class_identifier: string, languages: list<string>} $object
     * @return array{remote_id: string, name: string, class_identifier: string, languages: list<string>}
     */
    protected static function objectCompareRow( array $object ): array
    {
        return array( 'remote_id' => $object['remote_id'], 'name' => $object['name'], 'class_identifier' => $object['class_identifier'], 'languages' => $object['languages'] );
    }

    /**
     * comparePackages(), cached per pair and both packages' versions on disk; $refresh works it out anew. Adds 'checked_at' and 'cached'.
     *
     * @param bool $refresh
     * @return array<string, mixed>
     */
    public static function cachedComparison( eZPackage $from, eZPackage $to, $refresh = false ): array
    {
        $dir = eZSys::cacheDirectory() . '/xrowextract/compare';
        $version = function ( eZPackage $package ): int
        {
            $definition = rtrim( (string)$package->path(), '/' ) . '/package.xml';
            return is_file( $definition ) ? (int)@filemtime( $definition ) : 0;
        };
        $file = $dir . '/' . self::inspectionCachePrefix( $from->attribute( 'name' ) ) . self::inspectionCachePrefix( $to->attribute( 'name' ) )
              . md5( $version( $from ) . '|' . $version( $to ) ) . '.json';
        if ( !$refresh && is_file( $file ) && ( time() - (int)@filemtime( $file ) ) < self::COMPARE_CACHE_TTL )
        {
            $cached = json_decode( (string)@file_get_contents( $file ), true );
            if ( is_array( $cached ) && isset( $cached['objects'] ) )
            {
                $cached['cached'] = true;
                return $cached;
            }
        }
        $comparison = self::comparePackages( $from, $to );
        $comparison['checked_at'] = time();
        self::ensureCacheDir( $dir );
        $tmp = $file . '.' . getmypid() . '.tmp';
        if ( @file_put_contents( $tmp, json_encode( $comparison, JSON_INVALID_UTF8_SUBSTITUTE ) ) !== false )
        {
            @rename( $tmp, $file );
            if ( class_exists( 'XrowExtractJob' ) )
                XrowExtractJob::fixOwnership( $file );
        }
        $comparison['cached'] = false;
        return $comparison;
    }

    /**
     * The rows of a comparison list narrowed by $change (added/removed/changed, '' for all), $classIdentifier and $text (name or remote id).
     *
     * @param array<array<string, mixed>> $rows
     * @param string $change
     * @param string $classIdentifier
     * @param string|null $text
     * @return list<array<string, mixed>>
     */
    public static function filterComparisonRows( array $rows, $change = '', $classIdentifier = '', $text = '' ): array
    {
        $text = trim( (string)$text );
        $out = array();
        foreach ( $rows as $row )
        {
            if ( $change !== '' && $row['change'] !== $change )
                continue;
            if ( $classIdentifier !== '' && ( isset( $row['class_identifier'] ) ? $row['class_identifier'] : $row['identifier'] ) !== $classIdentifier )
                continue;
            if ( $text !== '' && mb_stripos( (string)$row['name'], $text ) === false && mb_stripos( (string)$row['remote_id'], $text ) === false )
                continue;
            $out[] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------ install

    /**
     * The remote ids of every class and object a package carries (from its own XML, cheaply: no
     * inspection against the site), plus their names: what an install is about to write. Used by a
     * background install to record what to count its progress against (see installProgress()).
     * 'missing_datatypes': the datatype check (see missingDatatypes()), from the same read.
     *
     * @return array{classes: list<array{remote_id: string, identifier: string}>, objects: list<string>, missing_datatypes: list<array{datatype: string, classes: list<string>, objects: int, object_classes: list<string>, available: bool}>}
     */
    public static function packageContents( eZPackage $package ): array
    {
        $contents = array( 'classes' => array(), 'objects' => array() );
        $usage = array();
        foreach ( self::installItemsOfType( $package, 'ezcontentclass' ) as $item )
        {
            $row = self::readClassItem( $package, $item );
            if ( $row )
                self::addClassDatatypes( $usage, $row['identifier'], $row['attributes'] );
            if ( $row && $row['remote_id'] )
                $contents['classes'][] = array( 'remote_id' => $row['remote_id'], 'identifier' => $row['identifier'] );
        }
        foreach ( self::installItemsOfType( $package, 'ezcontentobject' ) as $item )
        {
            foreach ( self::objectDOMNodes( $package, $item ) as $node )
            {
                self::addObjectDatatypes( $usage, $node, $node->getAttributeNS( 'http://ez.no/ezobject', 'class_identifier' ) );
                if ( $node->getAttribute( 'remote_id' ) )
                    $contents['objects'][] = $node->getAttribute( 'remote_id' );
            }
        }
        $contents['missing_datatypes'] = self::missingDatatypes( $usage );
        return $contents;
    }

    /**
     * How far a running install is, read from the database: of the classes and objects the package
     * carries (a watch file written by bin/php/package.php --install: remote ids + the start time), how
     * many exist now and were written since the start, and the names of the latest ones. The kernel's
     * package installer reports nothing while it works, so this is what the Jobs page shows as the
     * job's real progress.
     * @param string $watchFile
     * @return array{done: int, total: int, classes_done: int, classes_total: int, objects_done: int, objects_total: int, recent: list<array{id: int, name: string, at: int}>}|null
     *         array( done, total, classes_done, objects_done, recent => list of names ) or null
     */
    public static function installProgress( $watchFile ): ?array
    {
        $watch = is_file( $watchFile ) ? json_decode( (string)@file_get_contents( $watchFile ), true ) : null;
        if ( !is_array( $watch ) || empty( $watch['started'] ) )
            return null;
        $db = eZDB::instance();
        $since = (int)$watch['started'];
        /** @param list<mixed> $remoteIDs */
        $count = function ( string $table, array $remoteIDs ) use ( $db, $since ): int
        {
            $done = 0;
            foreach ( array_chunk( $remoteIDs, 500 ) as $chunk )
            {
                $in = implode( ', ', array_map( function ( $id ) use ( $db ) { return "'" . $db->escapeString( (string)$id ) . "'"; }, $chunk ) );
                $rows = $db->arrayQuery( "SELECT count(*) AS n FROM $table WHERE remote_id IN ( $in ) AND modified >= $since" );
                $done += (int)$rows[0]['n'];
            }
            return $done;
        };
        $classIDs = array();
        foreach ( (array)$watch['classes'] as $class )
            $classIDs[] = $class['remote_id'];
        $objectIDs = (array)$watch['objects'];
        $classesDone = $classIDs ? $count( 'ezcontentclass', $classIDs ) : 0;
        $objectsDone = $objectIDs ? $count( 'ezcontentobject', $objectIDs ) : 0;
        $recent = array();
        if ( $objectIDs )
        {
            $in = implode( ', ', array_map( function ( $id ) use ( $db ) { return "'" . $db->escapeString( (string)$id ) . "'"; }, array_slice( $objectIDs, 0, 2000 ) ) );
            foreach ( (array)$db->arrayQuery( "SELECT id, name, modified FROM ezcontentobject WHERE remote_id IN ( $in ) AND modified >= $since ORDER BY modified DESC, id DESC", array( 'limit' => 6 ) ) as $row )
                $recent[] = array( 'id' => (int)$row['id'], 'name' => (string)$row['name'], 'at' => (int)$row['modified'] );
        }
        $total = count( $classIDs ) + count( $objectIDs );
        return array( 'done' => min( $total, $classesDone + $objectsDone ), 'total' => $total,
                      'classes_done' => $classesDone, 'classes_total' => count( $classIDs ),
                      'objects_done' => $objectsDone, 'objects_total' => count( $objectIDs ), 'recent' => $recent );
    }

    /**
     * Installs a package through eZPackage::install(), the same convenience
     * method kernel/package/install.php's per-item loop is built on. All
     * "top" nodes the package carries (a content package's own root objects)
     * are placed under $parentNodeID; existing classes/objects are handled
     * per $classMode/$objectMode.
     *
     * @param int|string $parentNodeID
     * @param string $siteAccess
     * @param string $objectMode
     * @param string $classMode
     * @param int|string|false|null $userID
     * @return array{ok: bool, errors: list<string>, created_classes: list<array{id: int, identifier: string, name: string}>, created_objects: list<array{id: int, name: string, node_id: int|null}>}
     */
    public static function install( eZPackage $package, $parentNodeID, $siteAccess, $objectMode, $classMode, $userID = false ): array
    {
        $report = array( 'ok' => false, 'errors' => array(), 'created_classes' => array(), 'created_objects' => array() );
        // Whatever a cached dry run said no longer holds once this package is (being) installed
        self::forgetInspections( $package->attribute( 'name' ) );

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
            $row = self::readClassItem( $package, $item );
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
                $report['created_classes'][] = array( 'id' => (int)$class->attribute( 'id' ), 'identifier' => (string)$class->attribute( 'identifier' ), 'name' => (string)$class->attribute( 'name' ) );
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
                    'name'    => (string)$object->name(),
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
     *
     * @param int|string $classID a class id or identifier
     * @return array{ok: bool, errors: list<string>, file: string|null, object_count: int, outcomes: list<array{outcome: string, name: string}>, note: string}
     */
    public static function buildSamplePackage( $classID ): array
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

    /**
     * Exports $package to a randomly-named .ezpkg inside XrowExtractImport::uploadDir() (the same private, 0700 folder a row sample/upload uses), never a public path. Returns the file path, or false on failure.
     *
     * @param string $baseName
     */
    public static function exportToPrivateFile( eZPackage $package, $baseName ): string|false
    {
        $dir = XrowExtractImport::uploadDir();
        $dir = realpath( $dir ) ?: $dir; // compress.zlib:// needs an absolute path
        $safeBase = preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $baseName );
        $name = 'pkg_' . $safeBase . '_' . date( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.ezpkg';
        $target = $dir . '/' . $name;
        $written = self::withNativeFileStreams( function () use ( $package, $target ) {
            return $package->exportToArchive( $target );
        } );
        if ( !$written || !is_file( $target ) )
            return false;
        @chmod( $target, self::fileMode( 0600 ) );
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
     *
     * @template T
     * @param string $ezpkgPath
     * @param callable( eZPackage|null, string|null ): T $callback
     * @return T
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
     *
     * @param array<mixed> $objects eZContentObject instances
     * @param bool $includeClasses
     */
    protected static function exportExistingObjectsIntoPackage( eZPackage $package, eZContentClass $class, array $objects, $includeClasses ): bool
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
     * Builds $package from an explicit, already-resolved list of node ids — the same class+node+filters+
     * languages selection the One class (xrowextract/csv) or Site archive view/CLI just resolved for a
     * CSV/JSON/XML export, exported as a real content package instead. Unlike exportExistingObjectsIntoPackage()
     * above (every current object of a class, all site languages, used by the sample/template builders),
     * this is: exactly these nodes (a caller-filtered set - date, visibility, conditions, sort, limit,
     * whatever XrowExtractFilters resolved), and only the languages the export itself was asked for -
     * "columns don't apply to a package" is true of the row-level output, but scope, class, filters and
     * languages still do, and this is the method that keeps that true. See bin/php/csv.php --format=ezpkg.
     *
     * @param array<int|string> $nodeIDs
     * @param list<string> $languages
     * @param bool $includeClasses
     */
    public static function exportNodeIDsIntoPackage( eZPackage $package, array $nodeIDs, array $languages, $includeClasses = true ): bool
    {
        if ( !$nodeIDs )
            return false;
        $languages = $languages ?: array_keys( XrowExtractColumns::contentLanguages() );
        if ( !$languages )
            $languages = array( 'eng-US' );

        // Same worker-reuse reset as exportExistingObjectsIntoPackage() - see its own comment above.
        $objectHandler = eZPackage::packageHandler( 'ezcontentobject' );
        $objectHandler->NodeIDArray = array();
        $objectHandler->RootNodeIDArray = array();
        $objectHandler->NodeObjectArray = array();
        $objectHandler->ObjectArray = array();
        $objectHandler->RootNodeObjectArray = array();
        foreach ( $nodeIDs as $nodeID )
            $objectHandler->addNode( (int)$nodeID, false );
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
     *
     * @param list<string> $errors
     * @return list<array{outcome: string, name: string}>
     */
    protected static function mutateSampleOutcomes( eZPackage $package, string $classIdentifier, array &$errors ): array
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
    public static function keepSamplePackage( eZPackage $package ): void
    {
        @file_put_contents( $package->path() . '/' . self::SAMPLE_PACKAGE_KEEP_MARKER, (string)time() );
    }

    /**
     * If $sessionEntry names a "Try a sample" package that was never kept, removes it from the repository right away (leaving before cleanupOldSamplePackages() gets to it).
     *
     * @param array<string, mixed> $sessionEntry
     */
    public static function forgetUnkeptSamplePackage( array $sessionEntry ): void
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
    public static function cleanupOldSamplePackages(): int
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

    /**
     * The name prefixes ext:xrowextract:package --clean and cleanupOldSamplePackages() both look for - every package this extension itself builds and might leave behind, never another extension's.
     *
     * @return list<string>
     */
    public static function leftoverPackagePrefixes(): array
    {
        return array( self::SAMPLE_PACKAGE_PREFIX, 'xrowextract_export_', 'xrowextract_template_' );
    }

    /**
     * Every repository package whose name starts with one of leftoverPackagePrefixes() - what ext:xrowextract:package --clean lists and, without --dry-run, removes. Never matches a package this extension did not build (sevenx_*, and so on).
     *
     * @return list<eZPackage>
     */
    public static function findLeftoverPackages(): array
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
     *
     * @param int|string $classID a class id or identifier
     * @param string $variant
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function buildContentPackage( $classID, $variant, array $options = array() ): array
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

    /**
     * The raw bytes of the single XML file a class-only or content-only package carries, for "Download class/object XML" on their own (no archive).
     *
     * @param string $type
     */
    public static function singleItemXMLBytes( eZPackage $package, $type ): string|false
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

    /** @return array<string, string> */
    public static function templateVariants(): array
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
     *
     * @param int|string $classID a class id or identifier
     * @param string $variant
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function buildTemplatePackage( $classID, $variant, array $options = array() ): array
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

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    protected static function sampleLanguages( array $options ): array
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
    public static function defaultScratchNodeID(): int
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

    /**
     * Node id and path of where a template build's sample objects would be created, for display before/after a build.
     *
     * @param int|string|false|null $nodeID
     * @return array{node_id: int, path: string, exists: bool}
     */
    public static function scratchLocationInfo( $nodeID = false ): array
    {
        $nodeID = $nodeID ? (int)$nodeID : self::defaultScratchNodeID();
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        $exists = $node instanceof eZContentObjectTreeNode;
        $path = $exists ? $node->attribute( 'path_identification_string' ) : false;
        return array(
            'node_id' => $nodeID,
            'path'    => $path ?: ( 'node ' . $nodeID ),
            'exists'  => $exists,
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
     *
     * @param list<string> $errors
     */
    protected static function createScratchFolder( int $parentNodeID, string $language, array &$errors ): int|false
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
     *
     * @param string $text
     */
    public static function attachAboutDocument( eZPackage $package, $text ): void
    {
        $package->appendDocument( 'about.txt', 'text/plain', false, false, false, $text );
    }

    /**
     * A package name the kernel accepts: eZPackage::import() refuses (STATUS_INVALID_NAME) any name that
     * is not already its own "identifier" transformation (lowercase, digits, underscores), so a name built
     * from a node or class name such as "xrowextract_export_Websites_2" could be written but not imported.
     *
     * @param string|null $name
     */
    public static function validPackageName( $name ): string
    {
        eZPackage::isValidName( (string)$name, $transformed );
        $transformed = trim( (string)$transformed, '_' );
        return $transformed !== '' ? $transformed : 'package';
    }

    /**
     * Runs a kernel package archive operation (eZPackage::import(), exportToArchive()) with every remembered
     * file status forgotten first. Under Velocity the file layer remembers which files exist for the length of
     * a request; a file written by a function that bypasses it (move_uploaded_file() in storeUpload()) is then
     * "not there" to the archive reader, which opens it as compress.zlib://<path>: "can not be opened for
     * reading". clearstatcache() makes that layer forget at once (and is a plain stat cache reset elsewhere).
     * Swapping the file stream wrapper out instead was tried and must not be: it kills the Velocity worker.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function withNativeFileStreams( callable $operation )
    {
        clearstatcache( true );
        return $operation();
    }

    /** $base, or $base_2, $base_3, ... the first one not already in the local package repository. */
    protected static function uniquePackageName( string $base ): string
    {
        $base = self::validPackageName( $base );
        $name = $base;
        $suffix = 1;
        while ( eZPackage::fetch( $name, false, 'local', false ) )
        {
            $suffix++;
            $name = $base . '_' . $suffix;
        }
        return $name;
    }

    /**
     * Creates $count real content objects of $class below $scratchNodeID, with a value for every importable attribute. Returns false if none could be created.
     *
     * @param list<string> $languages
     * @param list<string> $errors
     * @return array{object_ids: list<int>, node_ids: list<int>}|false
     */
    protected static function createSampleObjects( eZContentClass $class, int $count, array $languages, int $scratchNodeID, array &$errors ): array|false
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

    /**
     * One row + mapping (as XrowExtractImport::run() expects) with a real sample value for every attribute this importer can write.
     *
     * @param array<eZContentClassAttribute> $classAttributes
     * @param string $classIdentifier
     * @param array<int, string> $remoteIDs
     * @return array{0: array<string, string>, 1: list<array{column: string, target: string}>}
     */
    protected static function sampleRow( array $classAttributes, $classIdentifier, int $index, array $remoteIDs, int $scratchNodeID, string $language ): array
    {
        $row = array();
        $mapping = array();
        $column = 0;
        $add = function ( string $target, string $value ) use ( &$row, &$mapping, &$column ): void
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

    /**
     * A real, valid value for one class attribute's datatype, or null to leave the class default (no import handler, or not applicable for this row).
     *
     * @param string $datatype
     * @param string $classIdentifier
     * @param array<int, string> $remoteIDs
     * @return array{kind: 'attr', value: string}|array{kind: 'attrfmt', value: string, format: string}|null
     */
    protected static function sampleAttributeValue( eZContentClassAttribute $classAttribute, $datatype, int $index, $classIdentifier, array $remoteIDs, string $language ): ?array
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
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d', strtotime( "+{$index} day" ) ?: time() ) );
            case 'ezdatetime':
                return array( 'kind' => 'attr', 'value' => date( 'Y-m-d H:i:s', strtotime( "+{$index} hour" ) ?: time() ) );
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

    /**
     * Whether an ezobjectrelation(list) attribute's class restriction (if any) allows relating to $classIdentifier itself.
     *
     * @param string $classIdentifier
     */
    protected static function classAllowsRelation( eZContentClassAttribute $classAttribute, $classIdentifier ): bool
    {
        $content = $classAttribute->content();
        $list = isset( $content['class_constraint_list'] ) ? (array)$content['class_constraint_list'] : array();
        return !$list || in_array( $classIdentifier, $list, true );
    }

    /**
     * The bundled sample file as a resolved path: the importer refuses any path containing "..", so
     * "classes/../share/..." made every image and file sample fail ("not an importable path").
     */
    protected static function sampleAssetPath( string $name ): string
    {
        $path = dirname( dirname( __FILE__ ) ) . '/share/sample/' . $name;
        $real = realpath( $path );
        return $real !== false ? $real : $path;
    }
}
