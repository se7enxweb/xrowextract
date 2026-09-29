<?php

/**
 * The site archive: the objects of many classes below many nodes, one CSV
 * file per class, with a manifest, packed as zip, tar.gz, tar.bz2, tar.xz,
 * 7z or rar (the last three when the server has the program).
 *
 * Objects are read with the user's content/read policies, at their main
 * location, and each object is written once even when selected nodes
 * overlap.
 */
class XrowExtractArchive
{
    const BATCH = 100;

    /** The archive formats, each with whether this server can write it and why not. */
    public static function formats()
    {
        $tar = self::binary( array( 'tar' ) );
        $formats = array(
            'zip'     => array( 'name' => 'ZIP',     'extension' => 'zip',     'available' => class_exists( 'ZipArchive' ),                                        'needs' => 'PHP zip' ),
            'tar.gz'  => array( 'name' => 'TAR.GZ',  'extension' => 'tar.gz',  'available' => ( $tar && self::binary( array( 'gzip' ) ) && self::canRun() ) || ( self::pharUsable() && extension_loaded( 'zlib' ) ), 'needs' => 'tar, gzip' ),
            'tar.bz2' => array( 'name' => 'TAR.BZ2', 'extension' => 'tar.bz2', 'available' => ( $tar && self::binary( array( 'bzip2' ) ) && self::canRun() ) || ( self::pharUsable() && extension_loaded( 'bz2' ) ), 'needs' => 'tar, bzip2' ),
            'tar.xz'  => array( 'name' => 'TAR.XZ',  'extension' => 'tar.xz',  'available' => $tar && self::binary( array( 'xz' ) ) && self::canRun(),           'needs' => 'tar, xz' ),
            '7z'      => array( 'name' => '7-Zip',   'extension' => '7z',      'available' => self::binary( array( '7zz', '7z', '7za' ) ) && self::canRun(),       'needs' => '7z (p7zip or 7-Zip)' ),
            'rar'     => array( 'name' => 'RAR',     'extension' => 'rar',     'available' => self::binary( array( 'rar' ) ) && self::canRun(),                    'needs' => 'rar (RARLAB)' ),
        );
        foreach ( $formats as $id => $format )
            $formats[$id]['id'] = $id;
        return $formats;
    }

    /** The first of the programs found in the usual system paths, else false. */
    public static function binary( array $names )
    {
        foreach ( $names as $name )
        {
            foreach ( array( '/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin' ) as $dir )
            {
                if ( @is_executable( $dir . '/' . $name ) )
                    return $dir . '/' . $name;
            }
        }
        return false;
    }

    /** PharData needs the phar:// stream wrapper, which an installation may switch off. */
    protected static function pharUsable()
    {
        return class_exists( 'PharData' ) && in_array( 'phar', stream_get_wrappers(), true );
    }

    protected static function canRun()
    {
        $disabled = array_map( 'trim', explode( ',', (string)ini_get( 'disable_functions' ) ) );
        return function_exists( 'exec' ) && !in_array( 'exec', $disabled, true );
    }

    /**
     * Ready-made node selections of a default installation, from content.ini:
     * id => (name, node ids).
     */
    public static function nodeSets()
    {
        $content = eZINI::instance( 'content.ini' );
        $root  = (int)$content->variable( 'NodeSettings', 'RootNode' );
        $media = (int)$content->variable( 'NodeSettings', 'MediaRootNode' );
        $users = (int)$content->variable( 'NodeSettings', 'UserRootNode' );
        return array(
            'content_media' => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content and media' ),      'nodes' => array( $root, $media ) ),
            'content'       => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content structure' ),      'nodes' => array( $root ) ),
            'media'         => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Media library' ),          'nodes' => array( $media ) ),
            'users'         => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'User accounts' ),          'nodes' => array( $users ) ),
            'everything'    => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content, media and users' ), 'nodes' => array( $root, $media, $users ) ),
        );
    }

    /**
     * The selected nodes the user may read, in the given order. A node below
     * another selected node is marked as covered by it and is not read again.
     */
    public static function resolveNodes( array $nodeIDs )
    {
        $nodes = array();
        foreach ( array_unique( array_map( 'intval', $nodeIDs ) ) as $nodeID )
        {
            $node = $nodeID > 0 ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
            $nodes[] = array(
                'id' => $nodeID,
                'node' => ( $node instanceof eZContentObjectTreeNode && $node->canRead() ) ? $node : null,
                'covered_by' => null,
            );
        }
        foreach ( $nodes as $i => $item )
        {
            if ( !$item['node'] )
                continue;
            foreach ( $nodes as $other )
            {
                if ( $other['node'] && $other['id'] !== $item['id']
                     && strpos( $item['node']->attribute( 'path_string' ), $other['node']->attribute( 'path_string' ) ) === 0 )
                {
                    $nodes[$i]['covered_by'] = $other['node'];
                    break;
                }
            }
        }
        return $nodes;
    }

    /** The nodes that are read: readable and not below another selected node. */
    public static function exportRoots( array $resolved )
    {
        $roots = array();
        foreach ( $resolved as $item )
        {
            if ( $item['node'] && !$item['covered_by'] )
                $roots[] = $item['node'];
        }
        return $roots;
    }

    protected static function treeParams( $classID, $offset = 0, $limit = null )
    {
        $params = array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array( (int)$classID ),
            'MainNodeOnly' => true,
            'IgnoreVisibility' => true,
        );
        if ( $limit !== null )
        {
            $params['Offset'] = $offset;
            $params['Limit'] = $limit;
            $params['SortBy'] = array( 'node_id', true );
            $params['LoadDataMap'] = true;
        }
        return $params;
    }

    /** How many objects of every class the roots hold: class id => count (classes with objects only). */
    public static function classCounts( array $roots )
    {
        $counts = array();
        if ( !$roots )
            return $counts;
        foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, false, false ) as $class )
        {
            $total = 0;
            foreach ( $roots as $root )
                $total += (int)eZContentObjectTreeNode::subTreeCountByNodeID( self::treeParams( $class['id'] ), $root->attribute( 'node_id' ) );
            if ( $total > 0 )
                $counts[(int)$class['id']] = $total;
        }
        return $counts;
    }

    /** How many objects (any class, main locations) a node holds below it, the node itself included. */
    public static function subtreeCount( eZContentObjectTreeNode $node )
    {
        return 1 + (int)eZContentObjectTreeNode::subTreeCountByNodeID( array( 'MainNodeOnly' => true, 'IgnoreVisibility' => true ), $node->attribute( 'node_id' ) );
    }

    /**
     * Write the archive. Returns array( 'path' => archive file, 'name' => download name,
     * 'work' => the work directory to remove afterwards, 'manifest' => ... ).
     */
    public static function build( array $roots, array $classIDs, $format, $separator, $escape, $newLine, $passwordHashes = false )
    {
        $formats = self::formats();
        if ( !isset( $formats[$format] ) || !$formats[$format]['available'] )
            throw new RuntimeException( 'Archive format not available: ' . $format );
        @set_time_limit( 0 );
        $started = microtime( true );

        $siteName = XrowExtractColumns::publicSiteINI()->variable( 'SiteSettings', 'SiteName' );
        $folder = XrowExtractColumns::fileName( $siteName, '', 'site' ) . '_export_' . date( 'Y-m-d_His' );
        // A private folder per export, directly in the cache directory: the web server and the command
        // line may run as different users, so there is no shared folder one of them would own
        $work = eZSys::cacheDirectory() . '/xrowextract-' . bin2hex( random_bytes( 8 ) );
        $dir = $work . '/' . $folder;
        $umask = umask( 077 );
        if ( !@mkdir( $work, 0700 ) || !@mkdir( $dir, 0700 ) )
        {
            umask( $umask );
            throw new RuntimeException( 'Cannot create the work directory' );
        }

        $parser = new ParserInterface( $separator, $escape );
        // Password hashes only when asked for and allowed for the current user
        $passwordHashes = $passwordHashes && XrowExtractColumns::allowPasswordHash();
        $extras = XrowExtractColumns::extraAttributes( $passwordHashes );
        $identity = XrowExtractColumns::identityColumns();
        $files = array();
        $manifestClasses = array();
        try
        {
            foreach ( $classIDs as $classID )
            {
                $class = eZContentClass::fetch( (int)$classID );
                if ( !$class instanceof eZContentClass )
                    continue;
                $columns = array_merge( $identity, XrowExtractColumns::classColumns( $classID ) );
                if ( $passwordHashes && self::hasUserAccount( $class ) )
                {
                    $columns[] = $extras['ezuser.password_hash'];
                    $columns[] = $extras['ezuser.password_hash_type'];
                }
                $name = XrowExtractColumns::fileName( $class->attribute( 'identifier' ), '.csv', 'class_' . (int)$classID );
                $fh = fopen( $dir . '/' . $name, 'w' );
                fwrite( $fh, implode( $separator, XrowExtractColumns::headerCells( $columns, $parser ) ) . $newLine );
                $rows = 0;
                $seen = array();
                foreach ( $roots as $root )
                {
                    for ( $offset = 0; ; $offset += self::BATCH )
                    {
                        $batch = eZContentObjectTreeNode::subTreeByNodeID( self::treeParams( $classID, $offset, self::BATCH ), $root->attribute( 'node_id' ) );
                        if ( !$batch )
                            break;
                        foreach ( $batch as $treeNode )
                        {
                            $obj = $treeNode->attribute( 'object' );
                            if ( !$obj instanceof eZContentObject || isset( $seen[$obj->attribute( 'id' )] ) )
                                continue;
                            $seen[$obj->attribute( 'id' )] = true;
                            fwrite( $fh, implode( $separator, XrowExtractColumns::rowCells( $columns, $obj, $parser, $extras, $passwordHashes ) ) . $newLine );
                            $rows++;
                        }
                        // Keep memory flat on large sites
                        eZContentObject::clearCache();
                        if ( count( $batch ) < self::BATCH )
                            break;
                    }
                }
                fclose( $fh );
                $files[] = $name;
                $manifestClasses[] = array( 'file' => $name, 'class' => $class->attribute( 'identifier' ),
                                            'name' => $class->attribute( 'name' ), 'rows' => $rows, 'columns' => count( $columns ) );
            }

            $manifest = array(
                'site' => $siteName,
                'created' => date( 'c' ),
                'format' => array( 'archive' => $format, 'separator' => $separator === "\t" ? 'tab' : $separator,
                                   'quoted' => (bool)$escape, 'line_endings' => $newLine === "\r\n" ? 'CRLF' : ( $newLine === "\r" ? 'CR' : 'LF' ),
                                   'encoding' => 'UTF-8' ),
                'nodes' => array_map( function ( $root ) {
                    return array( 'node_id' => (int)$root->attribute( 'node_id' ), 'name' => $root->attribute( 'name' ),
                                  'path' => $root->attribute( 'path_identification_string' ) );
                }, $roots ),
                'classes' => $manifestClasses,
                'password_hashes' => $passwordHashes,
                'rows' => array_sum( array_map( function ( $c ) { return $c['rows']; }, $manifestClasses ) ),
                'seconds' => round( microtime( true ) - $started, 2 ),
            );
            file_put_contents( $dir . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
            file_put_contents( $dir . '/README.txt', self::readme( $manifest ) );
            $files[] = 'manifest.json';
            $files[] = 'README.txt';

            $archive = $work . '/' . $folder . '.' . $formats[$format]['extension'];
            self::pack( $format, $work, $folder, $files, $archive );
        }
        catch ( Exception $e )
        {
            umask( $umask );
            self::removeWork( $work );
            throw $e;
        }
        umask( $umask );
        return array( 'path' => $archive, 'name' => basename( $archive ), 'work' => $work, 'manifest' => $manifest );
    }

    protected static function readme( array $manifest )
    {
        $lines = array(
            'Content export of ' . $manifest['site'] . ', ' . $manifest['created'],
            '',
            'One CSV file per class (UTF-8, separator "' . $manifest['format']['separator'] . '", '
                . ( $manifest['format']['quoted'] ? 'quoted cells' : 'unquoted cells' ) . ', ' . $manifest['format']['line_endings'] . ' line endings).',
            'Each row is one object at its main location; the first columns identify it',
            '(object id, remote id, main node, parent node, URL alias, published, modified),',
            'the others are the attributes of its class, named by their identifiers.',
            'manifest.json lists the nodes, the classes and the rows of each file.',
            '',
            'Nodes:',
        );
        foreach ( $manifest['nodes'] as $node )
            $lines[] = '  ' . $node['node_id'] . '  ' . $node['name'] . '  (' . $node['path'] . ')';
        $lines[] = '';
        $lines[] = 'Files:';
        foreach ( $manifest['classes'] as $class )
            $lines[] = sprintf( '  %-40s %6d rows  %s', $class['file'], $class['rows'], $class['name'] );
        $lines[] = '';
        if ( !empty( $manifest['password_hashes'] ) )
            $lines[] = 'User classes carry the password hash and its type (password-hash, password-hash-type).';
        $lines[] = 'The export can hold personal data: store and share it accordingly.';
        return implode( "\n", $lines ) . "\n";
    }

    protected static function pack( $format, $work, $folder, array $files, $archive )
    {
        switch ( $format )
        {
            case 'zip':
                $zip = new ZipArchive();
                if ( $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true )
                    throw new RuntimeException( 'Cannot create the ZIP file' );
                foreach ( $files as $file )
                    $zip->addFile( $work . '/' . $folder . '/' . $file, $folder . '/' . $file );
                $zip->close();
                break;

            case 'tar.gz':
            case 'tar.bz2':
                $packer = self::binary( array( $format === 'tar.gz' ? 'gzip' : 'bzip2' ) );
                if ( self::binary( array( 'tar' ) ) && $packer && self::canRun() )
                {
                    self::run( escapeshellarg( self::binary( array( 'tar' ) ) ) . ' -C ' . escapeshellarg( $work ) . ( $format === 'tar.gz' ? ' -czf ' : ' -cjf ' )
                               . escapeshellarg( $archive ) . ' ' . escapeshellarg( $folder ) );
                    break;
                }
                $tarPath = substr( $archive, 0, -strlen( $format === 'tar.gz' ? '.gz' : '.bz2' ) );
                $tar = new PharData( $tarPath );
                foreach ( $files as $file )
                    $tar->addFile( $work . '/' . $folder . '/' . $file, $folder . '/' . $file );
                $tar->compress( $format === 'tar.gz' ? Phar::GZ : Phar::BZ2 );
                unset( $tar );
                @unlink( $tarPath );
                break;

            case 'tar.xz':
                self::run( escapeshellarg( self::binary( array( 'tar' ) ) ) . ' -C ' . escapeshellarg( $work ) . ' -cJf ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $folder ) );
                break;

            case '7z':
                self::run( 'cd ' . escapeshellarg( $work ) . ' && ' . escapeshellarg( self::binary( array( '7zz', '7z', '7za' ) ) ) . ' a -bd -y -- ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $folder ) );
                break;

            case 'rar':
                self::run( 'cd ' . escapeshellarg( $work ) . ' && ' . escapeshellarg( self::binary( array( 'rar' ) ) ) . ' a -idq -y -- ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $folder ) );
                break;
        }
        if ( !is_file( $archive ) || filesize( $archive ) === 0 )
            throw new RuntimeException( 'The archive was not written' );
    }

    protected static function run( $command )
    {
        $output = array();
        $status = 0;
        exec( $command . ' 2>&1', $output, $status );
        if ( $status !== 0 )
            throw new RuntimeException( 'Packing failed (exit ' . $status . ')' );
    }

    /** Whether a class has a user account attribute (ezuser). */
    public static function hasUserAccount( eZContentClass $class )
    {
        foreach ( $class->dataMap() as $attribute )
        {
            if ( $attribute->attribute( 'data_type_string' ) === 'ezuser' )
                return true;
        }
        return false;
    }

    /** Remove a work folder made by build(): only an xrowextract-<hex> folder directly in the cache directory. */
    public static function removeWork( $work )
    {
        $base = realpath( eZSys::cacheDirectory() );
        $real = realpath( $work );
        if ( $base && $real && dirname( $real ) === $base && preg_match( '/^xrowextract-[0-9a-f]{16}$/', basename( $real ) ) )
            eZDir::recursiveDelete( $real );
    }
}

?>
