<?php

/**
 * The typed column manifest of an export: what every column of a file is (its id, the name in the file,
 * the datatype, the chosen format and language), what the class looked like (required, translatable,
 * selection options, relation targets, identifier and version), counts and checksums, where it came from
 * (site, siteaccess, filters, preset, schedule), and how the importer maps each column back.
 *
 * It travels with an export in three ways:
 *  - a sidecar file, "<file>.manifest.json", next to a single file (a background job, the command line,
 *    or the second download / zip member of the One class view), and in a site archive per class;
 *  - inside the file for XML (a <manifest> element after <columns>) and JSON (an envelope
 *    {"manifest": ..., "rows": [...], "summary": ...}); CSV has only the sidecar;
 *  - read back by XrowExtractImport: its column ids settle the mapping exactly, no guessing from the
 *    header text. A file without a manifest imports as before.
 *
 * The embedded copy cannot know the file's own checksum or final row count before the file is written;
 * those are in the sidecar ("file" and "counts"), and the XML/JSON trailer repeats the row count.
 */
class XrowExtractManifest
{
    const VERSION = 1;
    const SIDECAR_SUFFIX = '.manifest.json';

    /** A manifest file given explicitly (bin/php/import.php --manifest), used before any sidecar or embedded one. */
    public static $explicitPath = null;

    /** true: no manifest is read at all (bin/php/import.php --no-manifest). */
    public static $disabled = false;

    /** The sidecar path of a data file. */
    public static function sidecarPath( $dataPath )
    {
        return $dataPath . self::SIDECAR_SUFFIX;
    }

    /** Whether manifests are embedded in JSON files (csv.ini [Manifest] EmbedInJSON, default enabled). */
    public static function embedInJSON()
    {
        $ini = eZINI::instance( 'csv.ini' );
        return !( $ini->hasVariable( 'Manifest', 'EmbedInJSON' ) && $ini->variable( 'Manifest', 'EmbedInJSON' ) === 'disabled' );
    }

    /** Whether manifests are embedded in XML files (csv.ini [Manifest] EmbedInXML, default enabled). */
    public static function embedInXML()
    {
        $ini = eZINI::instance( 'csv.ini' );
        return !( $ini->hasVariable( 'Manifest', 'EmbedInXML' ) && $ini->variable( 'Manifest', 'EmbedInXML' ) === 'disabled' );
    }

    /** The version of this extension, for "generator". */
    protected static function extensionVersion()
    {
        if ( class_exists( 'xrowextractInfo' ) )
        {
            $info = xrowextractInfo::info();
            return isset( $info['Version'] ) ? $info['Version'] : '';
        }
        return '';
    }

    /**
     * Where an export comes from: the public site name, the siteaccess the export ran in, the host
     * and the installation's var directory name (tells two installations on one host apart).
     */
    public static function source()
    {
        $access = eZSiteAccess::current();
        $siteName = '';
        try
        {
            $siteName = (string)XrowExtractColumns::publicSiteINI()->variable( 'SiteSettings', 'SiteName' );
        }
        catch ( Exception $e )
        {
        }
        $host = '';
        try
        {
            $host = (string)XrowExtractColumns::publicHostURL();
        }
        catch ( Exception $e )
        {
        }
        return array(
            'site' => $siteName,
            'siteaccess' => $access && !empty( $access['name'] ) ? $access['name'] : '',
            'host' => $host,
            'var_dir' => basename( eZSys::varDirectory() ),
        );
    }

    /**
     * The class part: identifier, name, id, remote id, a version (the class's modified time, and a
     * signature of its attribute identifiers and datatypes: two sites with the same signature agree on
     * every attribute), and every attribute's meta.
     */
    public static function classInfo( $classID )
    {
        $class = eZContentClass::fetch( (int)$classID );
        if ( !$class instanceof eZContentClass )
            return null;
        $attributes = array();
        $signature = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
        {
            $identifier = $attribute->attribute( 'identifier' );
            $datatype = $attribute->attribute( 'data_type_string' );
            $signature[] = $identifier . '=' . $datatype;
            $attributes[$identifier] = self::attributeInfo( $attribute );
        }
        return array(
            'identifier' => $class->attribute( 'identifier' ),
            'name' => $class->attribute( 'name' ),
            'id' => (int)$class->attribute( 'id' ),
            'remote_id' => (string)$class->attribute( 'remote_id' ),
            'version' => array(
                'modified' => (int)$class->attribute( 'modified' ),
                'modified_iso' => date( 'c', (int)$class->attribute( 'modified' ) ),
                'signature' => substr( hash( 'sha256', implode( "\n", $signature ) ), 0, 16 ),
            ),
            'attributes' => $attributes,
        );
    }

    /** One class attribute's meta: flags, selection options, relation targets. */
    public static function attributeInfo( eZContentClassAttribute $attribute )
    {
        $datatype = $attribute->attribute( 'data_type_string' );
        $info = array(
            'identifier' => $attribute->attribute( 'identifier' ),
            'name' => $attribute->attribute( 'name' ),
            'datatype' => $datatype,
            'required' => (bool)$attribute->attribute( 'is_required' ),
            'translatable' => (bool)$attribute->attribute( 'can_translate' ),
            'searchable' => (bool)$attribute->attribute( 'is_searchable' ),
            'information_collector' => (bool)$attribute->attribute( 'is_information_collector' ),
            'position' => (int)$attribute->attribute( 'placement' ),
        );
        $content = null;
        try
        {
            $content = $attribute->content();
        }
        catch ( Exception $e )
        {
            $content = null;
        }
        if ( in_array( $datatype, array( 'ezselection', 'ezenhancedselection' ), true ) && is_array( $content ) && isset( $content['options'] ) )
        {
            $options = array();
            foreach ( (array)$content['options'] as $option )
            {
                if ( !is_array( $option ) )
                    continue;
                $options[] = array( 'id' => isset( $option['id'] ) ? $option['id'] : ( isset( $option['identifier'] ) ? $option['identifier'] : '' ),
                                    'name' => isset( $option['name'] ) ? $option['name'] : '' );
            }
            $info['options'] = $options;
            if ( isset( $content['is_multiselect'] ) )
                $info['multiple'] = (bool)$content['is_multiselect'];
        }
        if ( in_array( $datatype, array( 'ezobjectrelation', 'ezobjectrelationlist', 'ezenhancedobjectrelation' ), true ) )
        {
            $targets = array( 'classes' => array(), 'default_placement' => null );
            if ( is_array( $content ) )
            {
                if ( !empty( $content['class_constraint_list'] ) )
                    $targets['classes'] = array_values( (array)$content['class_constraint_list'] );
                if ( !empty( $content['default_placement']['node_id'] ) )
                    $targets['default_placement'] = (int)$content['default_placement']['node_id'];
                if ( !empty( $content['default_selection_node'] ) )
                    $targets['default_placement'] = (int)$content['default_selection_node'];
            }
            $info['relation_targets'] = $targets;
        }
        return $info;
    }

    /**
     * The typed column list for $columns (the export's own column arrays: id, name, exportname) of a
     * class, with the keys the writer puts in the file. $languages: the locales the rows are in (one:
     * every row is that language; several: the "language" column says which, per row).
     */
    public static function columns( array $columns, $classID, array $languages, $allowPasswordHash = false )
    {
        $meta = $classID ? XrowExtractColumns::attributeMeta( $classID ) : array();
        $formatColumns = $classID ? XrowExtractCatalogue::formatColumns( $classID ) : array();
        $classAttributes = array();
        if ( $classID )
        {
            foreach ( eZContentClassAttribute::fetchListByClassID( (int)$classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $attribute )
                $classAttributes[$attribute->attribute( 'identifier' )] = $attribute;
        }
        $languageKey = null;
        $keys = self::fileKeys( $columns );
        foreach ( $columns as $i => $column )
        {
            if ( $column['id'] === 'ezcontentobject.language' )
                $languageKey = $keys[$i];
        }
        $out = array();
        foreach ( array_values( $columns ) as $i => $column )
        {
            $id = $column['id'];
            $entry = array(
                'key' => $keys[$i],
                'id' => $id,
                'name' => isset( $column['name'] ) ? (string)$column['name'] : $id,
                'kind' => 'attribute',
                'attribute' => null,
                'datatype' => null,
                'format' => null,
                'format_name' => null,
                'language' => null,
            );
            if ( strpos( $id, ':' ) !== false && isset( $formatColumns[$id] ) )
            {
                $attributeIdentifier = substr( $id, 0, strpos( $id, ':' ) );
                $format = substr( $id, strpos( $id, ':' ) + 1 );
                $entry['kind'] = 'format';
                $entry['attribute'] = $attributeIdentifier;
                $entry['datatype'] = $formatColumns[$id]['datatype'];
                $entry['format'] = $format;
                $entry['format_name'] = $formatColumns[$id]['format'];
            }
            elseif ( isset( $classAttributes[$id] ) )
            {
                $entry['attribute'] = $id;
                $entry['datatype'] = $classAttributes[$id]->attribute( 'data_type_string' );
                $entry['cell'] = isset( $meta[$id]['cell'] ) ? $meta[$id]['cell'] : '';
            }
            else
            {
                $entry['kind'] = 'special';
                $entry['datatype'] = strtok( $id, '.' );
                $entry['cell'] = isset( $meta[$id]['cell'] ) ? $meta[$id]['cell'] : '';
            }
            if ( $entry['kind'] !== 'special' || $id === 'ezcontentobject.name' )
            {
                $translatable = $entry['attribute'] !== null && isset( $classAttributes[$entry['attribute']] )
                              ? (bool)$classAttributes[$entry['attribute']]->attribute( 'can_translate' ) : true;
                if ( count( $languages ) === 1 )
                    $entry['language'] = reset( $languages );
                elseif ( count( $languages ) > 1 )
                    $entry['language'] = array( 'per_row' => true, 'column' => $languageKey, 'translatable' => $translatable );
            }
            $entry['import'] = self::importHint( $entry, $classAttributes );
            $out[] = $entry;
        }
        return $out;
    }

    /** How the importer maps a column back: its target (the same strings XrowExtractImport::parseTarget() reads) and a note. */
    protected static function importHint( array $entry, array $classAttributes )
    {
        if ( $entry['kind'] === 'special' )
        {
            $notes = array(
                'ezcontentobject.remote_id' => 'matches an existing object (--match=remote_id)',
                'ezcontentobject.id' => 'matches an existing object with --match=object_id',
                'ezcontentobject.language' => 'the language of the row',
                'ezcontentobject.class_identifier' => 'the class of the row',
                'ezcontentobject.main_parent_node_id' => 'the parent of a new object',
                'ezcontentobject.section' => 'the section of the object',
            );
            return array( 'target' => 'special:' . $entry['id'], 'note' => isset( $notes[$entry['id']] ) ? $notes[$entry['id']] : 'read only: written by the export, not imported' );
        }
        if ( $entry['kind'] === 'format' )
        {
            $ok = XrowExtractImport::columnIsImportable( $entry['datatype'], $entry['format'] );
            return array( 'target' => $ok ? 'attrfmt:' . $entry['id'] : 'ignore',
                          'note' => $ok ? 'attribute ' . $entry['attribute'] . ', read as ' . $entry['format']
                                        : 'the format ' . $entry['format'] . ' of ' . $entry['datatype'] . ' is export only' );
        }
        $ok = $entry['datatype'] !== null && XrowExtractImport::columnIsImportable( $entry['datatype'], null );
        return array( 'target' => $ok ? 'attr:' . $entry['id'] : 'ignore',
                      'note' => $ok ? 'attribute ' . $entry['id'] : ( $entry['datatype'] ? XrowExtractImport::unsupportedReason( $entry['datatype'] ) : 'unknown column' ) );
    }

    /** The keys XrowExtractWriter gives these columns in the file ('_' to '-', a repeated name numbered). */
    public static function fileKeys( array $columns )
    {
        $keys = array();
        $used = array();
        foreach ( array_values( $columns ) as $column )
        {
            $key = str_replace( '_', '-', isset( $column['exportname'] ) ? $column['exportname'] : $column['id'] );
            if ( isset( $used[$key] ) )
                $key .= '-' . ( ++$used[$key] );
            else
                $used[$key] = 1;
            $keys[] = $key;
        }
        return $keys;
    }

    /**
     * A single-class manifest. $params: type (csv|archive-class), format, separator, quoted, line_endings,
     * languages, filters (an XrowExtractFilters values array or CLI arguments), preset, schedule,
     * selection (node/scope text), columns (as for columns()), class_id, allow_password_hash.
     */
    public static function build( array $params )
    {
        $classID = isset( $params['class_id'] ) ? (int)$params['class_id'] : 0;
        $languages = isset( $params['languages'] ) ? array_values( (array)$params['languages'] ) : array();
        $separator = isset( $params['separator'] ) ? (string)$params['separator'] : ',';
        $newLine = isset( $params['line_endings'] ) ? $params['line_endings'] : "\n";
        return array(
            'manifest_version' => self::VERSION,
            'generator' => trim( 'xrowextract ' . self::extensionVersion() ),
            'created' => date( 'c' ),
            'source' => self::source(),
            'export' => array(
                'type' => isset( $params['type'] ) ? $params['type'] : 'csv',
                'format' => isset( $params['format'] ) ? $params['format'] : 'csv',
                'encoding' => 'UTF-8',
                'separator' => $separator === "\t" ? 'tab' : $separator,
                'quoted' => isset( $params['quoted'] ) ? (bool)$params['quoted'] : true,
                'line_endings' => $newLine === "\r\n" ? 'CRLF' : ( $newLine === "\r" ? 'CR' : 'LF' ),
                'languages' => $languages,
                'selection' => isset( $params['selection'] ) ? $params['selection'] : null,
                'filters' => isset( $params['filters'] ) ? $params['filters'] : null,
                'preset' => isset( $params['preset'] ) && $params['preset'] !== '' ? $params['preset'] : null,
                'schedule' => isset( $params['schedule'] ) && $params['schedule'] ? $params['schedule'] : null,
                'run_mode' => isset( $params['run_mode'] ) ? $params['run_mode'] : null,
            ),
            'class' => $classID ? self::classInfo( $classID ) : null,
            'columns' => self::columns( isset( $params['columns'] ) ? $params['columns'] : array(), $classID, $languages,
                                        !empty( $params['allow_password_hash'] ) ),
            'import' => array(
                'how' => 'Each column\'s "import.target" is the mapping the importer uses when it reads this file back '
                       . '(attr:<attribute>, attrfmt:<attribute>:<format>, special:<column id> or ignore); '
                       . 'rows are matched by remote id when a remote-id column is present.',
                'match' => 'remote_id',
                'class' => $classID ? eZContentClass::fetch( $classID )->attribute( 'identifier' ) : null,
            ),
        );
    }

    /** $manifest completed with what is known once the file is written: row counts, size and sha256. */
    public static function finish( array $manifest, $dataPath, $rows, array $rowsPerLanguage = array(), array $warnings = array() )
    {
        $manifest['counts'] = array( 'rows' => (int)$rows );
        if ( $rowsPerLanguage )
            $manifest['counts']['rows_per_language'] = $rowsPerLanguage;
        $manifest['counts']['columns'] = isset( $manifest['columns'] ) ? count( $manifest['columns'] ) : 0;
        if ( $dataPath !== null && is_file( $dataPath ) )
        {
            $manifest['file'] = array(
                'name' => basename( $dataPath ),
                'bytes' => (int)filesize( $dataPath ),
                'sha256' => hash_file( 'sha256', $dataPath ),
            );
        }
        $manifest['finished'] = date( 'c' );
        if ( $warnings )
            $manifest['warnings'] = array_values( $warnings );
        return $manifest;
    }

    /** The manifest as it is embedded in a file header: without the parts only the finished file knows. */
    public static function headerCopy( array $manifest )
    {
        unset( $manifest['file'], $manifest['counts'], $manifest['finished'] );
        return $manifest;
    }

    public static function encode( array $manifest )
    {
        return json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
    }

    /** Writes the sidecar next to $dataPath (0600, handed to the var directory's owner). Returns its path. */
    public static function writeSidecar( $dataPath, array $manifest )
    {
        $path = self::sidecarPath( $dataPath );
        if ( @file_put_contents( $path, self::encode( $manifest ) ) === false )
            return false;
        @chmod( $path, 0600 );
        XrowExtractJob::fixOwnership( $path );
        return $path;
    }

    /** A manifest read from a JSON file, or null. */
    public static function readFile( $path )
    {
        if ( !is_file( $path ) || filesize( $path ) > 16 * 1024 * 1024 )
            return null;
        $data = json_decode( (string)file_get_contents( $path ), true );
        return self::isManifest( $data ) ? $data : null;
    }

    /** A single file's manifest (with its typed "columns"), or a site archive's (with its "classes"). */
    public static function isManifest( $data )
    {
        return is_array( $data ) && isset( $data['manifest_version'] )
               && ( ( isset( $data['columns'] ) && is_array( $data['columns'] ) ) || ( isset( $data['classes'] ) && is_array( $data['classes'] ) ) );
    }

    /** A single file's manifest: one the importer can map columns from. */
    public static function isColumnManifest( $data )
    {
        return is_array( $data ) && isset( $data['manifest_version'] ) && isset( $data['columns'] ) && is_array( $data['columns'] );
    }

    /**
     * The manifest that belongs to a data file: the sidecar "<file>.manifest.json" first (it has the
     * checksum), else the one embedded in the file. null when there is none.
     */
    public static function forDataFile( $path, $format = null )
    {
        if ( self::$disabled )
            return null;
        if ( self::$explicitPath !== null )
        {
            $explicit = self::readFile( self::$explicitPath );
            if ( self::isColumnManifest( $explicit ) )
            {
                $explicit['_source'] = 'given';
                return $explicit;
            }
        }
        $sidecar = self::readFile( self::sidecarPath( $path ) );
        if ( self::isColumnManifest( $sidecar ) )
        {
            $sidecar['_source'] = 'sidecar';
            return $sidecar;
        }
        $format = $format ?: XrowExtractImport::detectFormat( XrowExtractImport::sniff( $path ) );
        $embedded = null;
        if ( $format === 'xml' )
            $embedded = self::readEmbeddedXML( $path );
        elseif ( $format === 'json' )
            $embedded = self::readEmbeddedJSON( $path );
        if ( $embedded )
            $embedded['_source'] = 'embedded';
        return $embedded;
    }

    /** The <manifest> element of an XML export (read with XMLReader, DOCTYPE refused as everywhere else). */
    public static function readEmbeddedXML( $path )
    {
        if ( preg_match( '/<!DOCTYPE/i', XrowExtractImport::sniff( $path, 65536 ) ) )
            return null;
        $reader = new XMLReader();
        if ( !@$reader->open( $path, null, LIBXML_NONET ) )
            return null;
        $useErrors = libxml_use_internal_errors( true );
        $found = null;
        while ( @$reader->read() )
        {
            if ( $reader->nodeType === XMLReader::DOC_TYPE )
                break;
            if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'object' )
                break;
            if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'manifest' )
            {
                $data = json_decode( (string)$reader->readString(), true );
                $found = self::isColumnManifest( $data ) ? $data : null;
                break;
            }
        }
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors( $useErrors );
        return $found;
    }

    /** The "manifest" of a JSON export's envelope. */
    public static function readEmbeddedJSON( $path )
    {
        $fh = @fopen( $path, 'rb' );
        if ( !$fh )
            return null;
        $head = (string)fread( $fh, 4 );
        fclose( $fh );
        if ( strpos( ltrim( XrowExtractImport::stripBOM( $head ) ), '{' ) !== 0 )
            return null;
        $data = json_decode( XrowExtractImport::stripBOM( (string)file_get_contents( $path ) ), true );
        return is_array( $data ) && isset( $data['manifest'] ) && self::isColumnManifest( $data['manifest'] ) ? $data['manifest'] : null;
    }

    /**
     * What the importer takes from a manifest for a file with header $header: array(
     *   'columnIDs' => file key => export column id (the same shape an XML <columns> block gives),
     *   'class' => class identifier or null, 'matched' => keys found, 'unknown' => header keys the
     *   manifest does not describe, 'missing' => manifest keys not in the header,
     *   'checksum' => 'ok' | 'mismatch' | null, 'source' => sidecar|embedded ).
     */
    public static function importMapping( array $manifest, array $header, $dataPath = null )
    {
        $byKey = array();
        foreach ( $manifest['columns'] as $column )
        {
            if ( isset( $column['key'] ) && isset( $column['id'] ) )
                $byKey[$column['key']] = $column;
        }
        $columnIDs = array();
        $unknown = array();
        foreach ( $header as $key )
        {
            if ( isset( $byKey[$key] ) )
                $columnIDs[$key] = $byKey[$key]['id'];
            else
                $unknown[] = $key;
        }
        $checksum = null;
        if ( $dataPath !== null && isset( $manifest['file']['sha256'] ) && is_file( $dataPath ) )
            $checksum = hash_file( 'sha256', $dataPath ) === $manifest['file']['sha256'] ? 'ok' : 'mismatch';
        $class = null;
        if ( !empty( $manifest['class']['identifier'] ) )
            $class = $manifest['class']['identifier'];
        elseif ( !empty( $manifest['import']['class'] ) )
            $class = $manifest['import']['class'];
        return array(
            'columnIDs' => $columnIDs,
            'class' => $class,
            'matched' => array_keys( $columnIDs ),
            'unknown' => $unknown,
            'missing' => array_values( array_diff( array_keys( $byKey ), $header ) ),
            'checksum' => $checksum,
            'source' => isset( $manifest['_source'] ) ? $manifest['_source'] : null,
        );
    }

    /**
     * A zip holding one data file (.csv/.json/.xml) and a manifest (manifest.json or <file>.manifest.json),
     * as the One class view's "Download with manifest" writes it: the data file is extracted to $target
     * and its manifest to "$target.manifest.json". Returns array( 'ok' => bool, 'name' => the data file's
     * name in the zip, 'error' => ... ); ok false with an empty error when the file is not such a zip.
     * Refuses anything else in the zip (a folder, a path with "..", a second data file).
     */
    public static function unpackZip( $zipPath, $target )
    {
        $fh = @fopen( $zipPath, 'rb' );
        $magic = $fh ? fread( $fh, 4 ) : '';
        if ( $fh )
            fclose( $fh );
        if ( $magic !== "PK\x03\x04" || !class_exists( 'ZipArchive' ) )
            return array( 'ok' => false, 'error' => '' );
        $zip = new ZipArchive();
        if ( $zip->open( $zipPath ) !== true )
            return array( 'ok' => false, 'error' => 'not a readable zip file' );
        $data = null;
        $manifest = null;
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $stat = $zip->statIndex( $i );
            if ( !is_array( $stat ) )
            {
                $zip->close();
                return array( 'ok' => false, 'error' => 'the zip has an entry that cannot be read (#' . $i . ')' );
            }
            $name = $stat['name'];
            if ( substr( $name, -1 ) === '/' )
                continue;
            if ( strpos( $name, '..' ) !== false || strpos( $name, '/' ) !== false || strpos( $name, '\\' ) !== false )
            {
                $zip->close();
                return array( 'ok' => false, 'error' => 'the zip holds a path, not a flat file: ' . $name );
            }
            if ( $name === 'manifest.json' || substr( $name, -strlen( self::SIDECAR_SUFFIX ) ) === self::SIDECAR_SUFFIX )
                $manifest = $name;
            elseif ( preg_match( '/\.(csv|json|xml)$/i', $name ) )
            {
                if ( $data !== null )
                {
                    $zip->close();
                    return array( 'ok' => false, 'error' => 'the zip holds more than one data file' );
                }
                $data = $name;
            }
        }
        if ( $data === null )
        {
            $zip->close();
            return array( 'ok' => false, 'error' => '' );
        }
        $in = $zip->getStream( $data );
        $out = $in ? @fopen( $target, 'wb' ) : false;
        $ok = $in && $out && stream_copy_to_stream( $in, $out ) !== false;
        if ( $in )
            fclose( $in );
        if ( $out )
            fclose( $out );
        if ( $ok && $manifest !== null )
            @file_put_contents( self::sidecarPath( $target ), (string)$zip->getFromName( $manifest ) );
        $zip->close();
        @chmod( $target, 0600 );
        return array( 'ok' => $ok, 'name' => $data, 'path' => $target, 'has_manifest' => $manifest !== null,
                      'error' => $ok ? '' : 'the data file could not be extracted' );
    }

    /** A zip of a data file and its manifest ($manifestJSON), for a single download. Returns the zip bytes. */
    public static function zipWithManifest( $dataName, $dataBytes, $manifestJSON )
    {
        $tmp = tempnam( eZSys::cacheDirectory(), 'xezip' );
        $zip = new ZipArchive();
        if ( $zip->open( $tmp, ZipArchive::OVERWRITE ) !== true )
            return false;
        $zip->addFromString( $dataName, $dataBytes );
        $zip->addFromString( $dataName . self::SIDECAR_SUFFIX, $manifestJSON );
        $zip->close();
        $bytes = (string)file_get_contents( $tmp );
        @unlink( $tmp );
        return $bytes;
    }
}

?>
