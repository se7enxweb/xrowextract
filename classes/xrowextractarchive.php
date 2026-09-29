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
        $root  = self::topLevelNodeID( (int)$content->variable( 'NodeSettings', 'RootNode' ) );
        $media = self::topLevelNodeID( (int)$content->variable( 'NodeSettings', 'MediaRootNode' ) );
        $users = self::topLevelNodeID( (int)$content->variable( 'NodeSettings', 'UserRootNode' ) );
        return array(
            'sites'         => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Sites' ),                  'nodes' => self::defaultSiteNodeIDs() ),
            'content_media' => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content and media' ),      'nodes' => array( $root, $media ) ),
            'content'       => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content structure' ),      'nodes' => array( $root ) ),
            'media'         => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Media library' ),          'nodes' => array( $media ) ),
            'users'         => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'User accounts' ),          'nodes' => array( $users ) ),
            'everything'    => array( 'name' => ezpI18n::tr( 'design/standard/extract', 'Content, media and users' ), 'nodes' => array( $root, $media, $users ) ),
        );
    }

    /** export.ini [SiteArchive] SitesParentNodeID: the node whose children are the sites (default: the content structure). */
    public static function sitesParentNodeID()
    {
        $ini = eZINI::instance( 'export.ini' );
        $parent = $ini->hasVariable( 'SiteArchive', 'SitesParentNodeID' ) ? (int)$ini->variable( 'SiteArchive', 'SitesParentNodeID' ) : 0;
        return $parent > 0 ? $parent : self::topLevelNodeID( (int)eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) );
    }

    /**
     * The sites the "Sites" set selects: export.ini [SiteArchive] DefaultSiteNodeIDs[], else the root node of
     * the default (public) siteaccess, else every site.
     */
    public static function defaultSiteNodeIDs()
    {
        $ini = eZINI::instance( 'export.ini' );
        $ids = $ini->hasVariable( 'SiteArchive', 'DefaultSiteNodeIDs' ) ? array_filter( array_map( 'intval', (array)$ini->variable( 'SiteArchive', 'DefaultSiteNodeIDs' ) ) ) : array();
        if ( !$ids )
        {
            $site = eZINI::instance();
            $public = eZSiteAccess::getIni( $site->variable( 'SiteSettings', 'DefaultAccess' ), 'content.ini' );
            $root = (int)$public->variable( 'NodeSettings', 'RootNode' );
            if ( $root > 0 && $root !== self::sitesParentNodeID() )
                $ids = array( $root );
        }
        if ( !$ids )
        {
            foreach ( self::siteList() as $siteNode )
                $ids[] = $siteNode['node_id'];
        }
        return array_values( array_unique( $ids ) );
    }

    /** The sites: the children of SitesParentNodeID the user may read, with object counts. */
    public static function siteList()
    {
        $parent = eZContentObjectTreeNode::fetch( self::sitesParentNodeID() );
        $sites = array();
        if ( !$parent instanceof eZContentObjectTreeNode )
            return $sites;
        foreach ( (array)$parent->subTree( array( 'Depth' => 1, 'SortBy' => $parent->sortArray(), 'Limit' => 50 ) ) as $child )
        {
            if ( !$child->canRead() )
                continue;
            $sites[] = array( 'node_id' => (int)$child->attribute( 'node_id' ), 'name' => $child->attribute( 'name' ),
                              'class_name' => $child->attribute( 'class_name' ), 'count' => self::subtreeCount( $child ) );
        }
        return $sites;
    }

    /**
     * The top-level node (a child of the tree's top node 1) a node is in: a siteaccess may set RootNode to a
     * site below the content structure, the sets mean the whole tree.
     */
    public static function topLevelNodeID( $nodeID )
    {
        $node = $nodeID > 0 ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
        if ( !$node instanceof eZContentObjectTreeNode )
            return $nodeID;
        $path = array_values( array_filter( explode( '/', trim( $node->attribute( 'path_string' ), '/' ) ) ) );
        return isset( $path[1] ) ? (int)$path[1] : $nodeID;
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

    protected static function treeParams( $classID, $offset = 0, $limit = null, $language = null )
    {
        $params = array(
            'ClassFilterType' => 'include',
            'ClassFilterArray' => array( (int)$classID ),
            'MainNodeOnly' => true,
            'IgnoreVisibility' => true,
        );
        if ( $language )
        {
            // Only the objects translated into this language, read in it
            $params['Language'] = $language;
            $params['ExtendedAttributeFilter'] = XrowExtractTranslationFilter::params( $language );
        }
        if ( $limit !== null )
        {
            $params['Offset'] = $offset;
            $params['Limit'] = $limit;
            $params['SortBy'] = array( 'node_id', true );
            $params['LoadDataMap'] = true;
        }
        return $params;
    }

    /**
     * How many rows every class has below the roots: class id => count (classes with rows only). With
     * languages, a row is one object in one of them (a translation); without, one object.
     */
    public static function classCounts( array $roots, $languages = null )
    {
        $counts = array();
        if ( !$roots )
            return $counts;
        $languages = $languages === null ? array( null ) : (array)$languages;
        foreach ( eZContentClass::fetchList( eZContentClass::VERSION_STATUS_DEFINED, false, false ) as $class )
        {
            $total = 0;
            foreach ( $roots as $root )
                foreach ( $languages as $language )
                    $total += (int)eZContentObjectTreeNode::subTreeCountByNodeID( self::treeParams( $class['id'], 0, null, $language ), $root->attribute( 'node_id' ) );
            if ( $total > 0 )
                $counts[(int)$class['id']] = $total;
        }
        return $counts;
    }

    /** Translations per language below the roots (any class, main locations): locale => count. */
    public static function languageCounts( array $roots )
    {
        $counts = array();
        foreach ( XrowExtractColumns::contentLanguages() as $locale => $language )
        {
            $counts[$locale] = 0;
            foreach ( $roots as $root )
                $counts[$locale] += (int)eZContentObjectTreeNode::subTreeCountByNodeID(
                    array( 'MainNodeOnly' => true, 'IgnoreVisibility' => true, 'Language' => $locale,
                           'ExtendedAttributeFilter' => XrowExtractTranslationFilter::params( $locale ) ), $root->attribute( 'node_id' ) );
        }
        return $counts;
    }

    /** The column choices of an archive: id => (name, description). */
    public static function columnChoices()
    {
        $t = function ( $text ) { return ezpI18n::tr( 'design/standard/extract', $text ); };
        return array(
            'standard'   => array( $t( 'Standard' ), $t( 'Object id, remote id, main node, parent node, URL alias and dates, then every attribute' ) ),
            'migration'  => array( $t( 'Migration' ), $t( 'Everything to rebuild the content elsewhere: identity, parent, languages, dates, every attribute' ) ),
            'attributes' => array( $t( 'Attributes only' ), $t( 'Every attribute of the class and nothing else' ) ),
        );
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
    public static function build( array $roots, array $classIDs, $format, $separator, $escape, $newLine, $passwordHashes = false, array $options = array() )
    {
        // Languages (default: every content language), columns (standard, migration, attributes), plain text of rich text
        $languages = isset( $options['languages'] ) && is_array( $options['languages'] ) ? array_values( $options['languages'] )
                                                                                          : array_keys( XrowExtractColumns::contentLanguages() );
        $columnChoice = isset( $options['columns'] ) && array_key_exists( $options['columns'], self::columnChoices() ) ? $options['columns'] : 'standard';
        $plainText = !empty( $options['plain_text'] );
        $progress = isset( $options['progress'] ) && is_callable( $options['progress'] ) ? $options['progress'] : null;
        $output = isset( $options['output'] ) && XrowExtractWriter::isFormat( $options['output'] ) ? $options['output'] : 'csv';
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
                if ( $columnChoice === 'migration' )
                    $columns = XrowExtractCatalogue::resolveColumns( XrowExtractCatalogue::setColumnIDs( 'migration', $classID ), $classID, $extras );
                elseif ( $columnChoice === 'attributes' )
                    $columns = XrowExtractColumns::classColumns( $classID );
                else
                    $columns = array_merge( $identity, XrowExtractColumns::classColumns( $classID ) );
                if ( $plainText )
                {
                    // The plain text of every rich text attribute, right after it
                    $withText = array();
                    $formatColumns = XrowExtractCatalogue::formatColumns( $classID );
                    foreach ( $columns as $column )
                    {
                        $withText[] = $column;
                        if ( isset( $formatColumns[$column['id'] . ':text'] ) && $formatColumns[$column['id'] . ':text']['datatype'] === 'ezxmltext' )
                            $withText[] = $formatColumns[$column['id'] . ':text'];
                    }
                    $columns = $withText;
                }
                $ids = array_map( function ( $c ) { return $c['id']; }, $columns );
                if ( count( $languages ) > 1 && !in_array( 'ezcontentobject.language', $ids, true ) )
                    array_unshift( $columns, $extras['ezcontentobject.language'] );
                if ( $passwordHashes && self::hasUserAccount( $class ) )
                {
                    $columns[] = $extras['ezuser.password_hash'];
                    $columns[] = $extras['ezuser.password_hash_type'];
                }
                $writer = new XrowExtractWriter( $output, $columns, $separator, $escape, $newLine,
                                                 array( 'class' => $class->attribute( 'identifier' ), 'site' => $siteName, 'created' => date( 'c' ) ) );
                $parser = $writer->parser();
                $name = XrowExtractColumns::fileName( $class->attribute( 'identifier' ), '.' . $writer->extension(), 'class_' . (int)$classID );
                $fh = fopen( $dir . '/' . $name, 'w' );
                fwrite( $fh, $writer->begin() );
                $rows = 0;
                $rowsPerLanguage = array();
                foreach ( $languages as $locale )
                {
                    // Each object once per language, even when the roots overlap
                    $seen = array();
                    $rowsPerLanguage[$locale] = 0;
                    XrowExtractColumns::$language = $locale;
                    foreach ( $roots as $root )
                    {
                        for ( $offset = 0; ; $offset += self::BATCH )
                        {
                            $batch = eZContentObjectTreeNode::subTreeByNodeID( self::treeParams( $classID, $offset, self::BATCH, $locale ), $root->attribute( 'node_id' ) );
                            if ( !$batch )
                                break;
                            foreach ( $batch as $treeNode )
                            {
                                $obj = $treeNode->attribute( 'object' );
                                if ( !$obj instanceof eZContentObject || isset( $seen[$obj->attribute( 'id' )] ) )
                                    continue;
                                $seen[$obj->attribute( 'id' )] = true;
                                fwrite( $fh, $writer->row( XrowExtractColumns::rowCells( $columns, $obj, $parser, $extras, $passwordHashes ) ) );
                                $rows++;
                                $rowsPerLanguage[$locale]++;
                            }
                            // Keep memory flat on large sites
                            eZContentObject::clearCache();
                            if ( $progress )
                                call_user_func( $progress, $class->attribute( 'identifier' ), $rows );
                            if ( count( $batch ) < self::BATCH )
                                break;
                        }
                    }
                }
                XrowExtractColumns::$language = null;
                fwrite( $fh, $writer->end() );
                fclose( $fh );
                $files[] = $name;
                $manifestClasses[] = array( 'file' => $name, 'class' => $class->attribute( 'identifier' ),
                                            'name' => $class->attribute( 'name' ), 'rows' => $rows, 'columns' => count( $columns ),
                                            'rows_per_language' => $rowsPerLanguage );
            }

            $manifest = array(
                'site' => $siteName,
                'created' => date( 'c' ),
                'format' => array( 'archive' => $format, 'files' => $output, 'separator' => $separator === "\t" ? 'tab' : $separator,
                                   'quoted' => (bool)$escape, 'line_endings' => $newLine === "\r\n" ? 'CRLF' : ( $newLine === "\r" ? 'CR' : 'LF' ),
                                   'encoding' => 'UTF-8' ),
                'nodes' => array_map( function ( $root ) {
                    return array( 'node_id' => (int)$root->attribute( 'node_id' ), 'name' => $root->attribute( 'name' ),
                                  'path' => $root->attribute( 'path_identification_string' ) );
                }, $roots ),
                'classes' => $manifestClasses,
                'password_hashes' => $passwordHashes,
                'languages' => $languages,
                'columns' => $columnChoice,
                'plain_text' => $plainText,
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
            $manifest['format']['files'] === 'csv'
                ? 'One CSV file per class (UTF-8, separator "' . $manifest['format']['separator'] . '", '
                  . ( $manifest['format']['quoted'] ? 'quoted cells' : 'unquoted cells' ) . ', ' . $manifest['format']['line_endings'] . ' line endings).'
                : 'One ' . strtoupper( $manifest['format']['files'] ) . ' file per class (UTF-8): '
                  . ( $manifest['format']['files'] === 'json' ? 'an array of objects keyed by column name.' : '<export> with <columns>, then an <object> of <field name="..."> per row.' ),
            'Each row is one object at its main location in one language (' . implode( ', ', $manifest['languages'] ) . '); the first columns identify it',
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
