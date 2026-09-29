<?php

/**
 * The other direction of the export: reads a CSV or JSON file written by
 * XrowExtractWriter (any column set, in particular "migration") back into
 * content objects.
 *
 * The flow is always: map file columns to class attributes / special columns
 * / attribute formats, then run() in dry-run mode to get a preview (action
 * and old -> new per row, never touching the database), then run() again
 * with apply=true to write it. Both the view and the CLI use exactly this
 * class so their behaviour cannot drift apart.
 *
 * A converter's job is to turn the text a column holds into the string the
 * datatype's own fromString() expects (the same string toString() would
 * give back), so the value is then always stored the same way eZ stores it
 * itself. Where the export already writes that exact string (ezstring,
 * ezinteger, ezurl, ezkeyword, ezxmltext's stored XML is never exported so
 * that one is genuinely converted from HTML ...) the raw cell is passed
 * through unchanged.
 */
class XrowExtractImport
{
    /** Datatypes importable from their plain (no format) column. */
    public static function baseImportableDatatypes()
    {
        return array(
            'ezstring', 'eztext', 'ezinteger', 'ezfloat', 'ezboolean', 'ezemail', 'ezidentifier',
            'ezurl', 'ezdate', 'ezdatetime', 'ezselection', 'ezkeyword', 'eztags', 'ezxmltext',
            'ezimage', 'ezbinaryfile', 'ezmedia',
        );
    }

    /** Attribute formats (identifier:format columns) this importer understands, by datatype. */
    public static function importableFormats( $datatype )
    {
        switch ( $datatype )
        {
            case 'ezobjectrelation':
            case 'ezobjectrelationlist':
                return array( 'ids', 'remote_ids' );
            case 'ezselection':
                return array( 'ids' );
            case 'ezdate':
            case 'ezdatetime':
                return array( 'iso', 'timestamp' );
            case 'xrowmetadata':
                return array( 'json' );
        }
        return array();
    }

    /** Whether a datatype can be imported at all, in some form (base column or a format column). */
    public static function isImportable( $datatype )
    {
        return in_array( $datatype, self::baseImportableDatatypes(), true ) || count( self::importableFormats( $datatype ) ) > 0;
    }

    /** Why a datatype cannot be imported, for the mapping screen and --list-columns-like reporting. */
    public static function unsupportedReason( $datatype )
    {
        $known = array(
            'ezenhancedobjectrelation' => 'not supported yet: map the class to use ezobjectrelationlist, or edit these by hand',
            'ezenhancedselection'      => 'not supported yet',
            'ezenum'                   => 'not supported yet',
            'ezcountry'                => 'not supported yet',
            'ezmatrix'                 => 'not supported yet',
            'ezprice'                  => 'not supported yet',
            'ezuser'                   => 'the user account is not created by this importer',
            'eztime'                   => 'not supported yet',
            'hmregexpline'             => 'not supported yet',
        );
        return isset( $known[$datatype] ) ? $known[$datatype] : 'no import handler for this datatype';
    }

    // ---------------------------------------------------------------- files

    /** The private upload folder: 0700, outside every path the web server answers directly. */
    public static function uploadDir()
    {
        $dir = eZSys::cacheDirectory() . '/xrowextract_import';
        if ( !is_dir( $dir ) )
        {
            mkdir( $dir, 0700, true );
        }
        @chmod( $dir, 0700 );
        $htaccess = $dir . '/.htaccess';
        if ( !is_file( $htaccess ) )
        {
            file_put_contents( $htaccess, "Deny from all\n" );
        }
        return $dir;
    }

    /** Removes uploaded files older than a day; called opportunistically from the view and the CLI. */
    public static function cleanupOldUploads( $maxAgeSeconds = 86400 )
    {
        $dir = self::uploadDir();
        foreach ( (array)@scandir( $dir ) as $name )
        {
            if ( $name === '.' || $name === '..' || $name === '.htaccess' )
                continue;
            $path = $dir . '/' . $name;
            if ( is_file( $path ) && ( time() - filemtime( $path ) ) > $maxAgeSeconds )
                @unlink( $path );
        }
    }

    /** A random file name inside the upload dir, keeping the original extension. */
    public static function storeUpload( $sourcePath, $originalName )
    {
        $dir = self::uploadDir();
        $ext = preg_match( '/\.([A-Za-z0-9]{1,8})$/', (string)$originalName, $m ) ? '.' . strtolower( $m[1] ) : '';
        $name = 'import_' . date( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . $ext;
        $target = $dir . '/' . $name;
        if ( !@move_uploaded_file( $sourcePath, $target ) && !@copy( $sourcePath, $target ) )
            return false;
        @chmod( $target, 0600 );
        return $target;
    }

    /** A generated file (a sample) written into the upload dir as if it had been uploaded. */
    public static function storeGenerated( $text, $suggestedName )
    {
        $dir = self::uploadDir();
        $ext = preg_match( '/\.([A-Za-z0-9]{1,8})$/', (string)$suggestedName, $m ) ? '.' . strtolower( $m[1] ) : '';
        $name = 'sample_' . date( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . $ext;
        $target = $dir . '/' . $name;
        if ( file_put_contents( $target, $text ) === false )
            return false;
        @chmod( $target, 0600 );
        return $target;
    }

    /** Streams a built eZPackage as a .ezpkg download and exits; the temporary archive file is removed again straight after. */
    public static function streamPackageDownload( eZPackage $package, $downloadName )
    {
        $dir = realpath( self::uploadDir() ) ?: self::uploadDir(); // compress.zlib:// needs an absolute path
        $archivePath = $dir . '/pkgdl_' . date( 'Ymd_His' ) . '_' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '.ezpkg';
        XrowExtractPackage::withNativeFileStreams( function () use ( $package, $archivePath ) {
            return $package->exportToArchive( $archivePath );
        } );
        $data = (string)@file_get_contents( $archivePath );
        @unlink( $archivePath );
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/gzip' );
        header( 'Content-Length: ' . strlen( $data ) );
        header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $downloadName ) . '"' );
        while ( @ob_end_clean() );
        echo $data;
        eZExecution::cleanExit();
    }

    /** Streams a raw content-class or content-object XML file as a download and exits. */
    public static function streamXMLDownload( $xmlBytes, $downloadName )
    {
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Content-Length: ' . strlen( $xmlBytes ) );
        header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9_.-]+/', '_', $downloadName ) . '"' );
        while ( @ob_end_clean() );
        echo $xmlBytes;
        eZExecution::cleanExit();
    }

    // --------------------------------------------------------------- parse

    /** UTF-8 text with a leading BOM removed. */
    public static function stripBOM( $text )
    {
        if ( substr( $text, 0, 3 ) === "\xEF\xBB\xBF" )
            return substr( $text, 3 );
        return $text;
    }

    /** 'xml' for a '<', 'json' for a '[' or '{', else 'csv' - the first non-blank byte. */
    public static function detectFormat( $text )
    {
        $trimmed = ltrim( self::stripBOM( (string)$text ) );
        if ( $trimmed === '' )
            return 'csv';
        if ( $trimmed[0] === '<' )
            return 'xml';
        if ( $trimmed[0] === '[' || $trimmed[0] === '{' )
            return 'json';
        return 'csv';
    }

    /** The separator most likely used in a CSV sample: whichever of , ; tab | appears most in the header line. */
    public static function detectSeparator( $text )
    {
        $firstLine = strtok( (string)$text, "\r\n" );
        $firstLine = $firstLine === false ? '' : $firstLine;
        $best = ',';
        $bestCount = -1;
        foreach ( array( ',', ';', "\t", '|' ) as $candidate )
        {
            $count = substr_count( $firstLine, $candidate );
            if ( $count > $bestCount )
            {
                $bestCount = $count;
                $best = $candidate;
            }
        }
        return $best;
    }

    /** header (array of names) and rows (array of header=>value maps), from CSV text. */
    public static function parseCSV( $text, $separator )
    {
        $text = self::stripBOM( (string)$text );
        $fh = fopen( 'php://temp', 'r+' );
        fwrite( $fh, $text );
        rewind( $fh );
        $header = fgetcsv( $fh, 0, $separator, '"', '' );
        $header = is_array( $header ) ? array_map( function ( $h ) { return trim( (string)$h ); }, $header ) : array();
        $rows = array();
        while ( ( $values = fgetcsv( $fh, 0, $separator, '"', '' ) ) !== false )
        {
            if ( $values === array( null ) )
                continue;
            $row = array();
            foreach ( $header as $i => $name )
                $row[$name] = isset( $values[$i] ) ? (string)$values[$i] : '';
            $rows[] = $row;
        }
        fclose( $fh );
        return array( 'header' => $header, 'rows' => $rows );
    }

    /** header and rows from JSON text (an array of flat objects, as XrowExtractWriter writes). */
    public static function parseJSON( $text )
    {
        $data = json_decode( self::stripBOM( (string)$text ), true );
        if ( !is_array( $data ) )
            return array( 'header' => array(), 'rows' => array(), 'error' => 'Not a JSON array of objects.' );
        $header = array();
        $rows = array();
        foreach ( $data as $item )
        {
            if ( !is_array( $item ) )
                continue;
            foreach ( array_keys( $item ) as $key )
            {
                if ( !in_array( $key, $header, true ) )
                    $header[] = $key;
            }
            $row = array();
            foreach ( $item as $k => $v )
                $row[$k] = is_scalar( $v ) || $v === null ? (string)$v : json_encode( $v, JSON_UNESCAPED_UNICODE );
            $rows[] = $row;
        }
        return array( 'header' => $header, 'rows' => $rows );
    }

    /**
     * header, rows, and columnIDs (the file column key => the export's column id, e.g.
     * "authors-ids" => "authors:ids") from the XML XrowExtractWriter writes:
     *   <export class="..." created="..."><columns><column name="key" id="id">Name</column>...</columns>
     *   <object><field name="key">value</field>...</object>...</export>
     * Refuses a DOCTYPE outright (never written by this project, and the classic XXE vector) before
     * the parser ever sees it, and parses with LIBXML_NONET and no DTD loading regardless.
     */
    public static function parseXML( $text )
    {
        $text = self::stripBOM( (string)$text );
        if ( preg_match( '/<!DOCTYPE/i', $text ) )
            return array( 'header' => array(), 'rows' => array(),
                          'error' => 'This file declares a DOCTYPE. That is refused: XrowExtractWriter never writes one, and a DOCTYPE can smuggle in external entities.' );
        $useErrors = libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $ok = $text !== '' && @$doc->loadXML( $text, LIBXML_NONET | LIBXML_NOBLANKS );
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $useErrors );
        if ( !$ok || !$doc->documentElement || $doc->documentElement->nodeName !== 'export' )
        {
            $detail = $xmlErrors ? trim( $xmlErrors[0]->message ) : 'not a well-formed <export> document';
            return array( 'header' => array(), 'rows' => array(), 'error' => "Malformed XML: $detail" );
        }
        $root = $doc->documentElement;
        $classIdentifier = $root->hasAttribute( 'class' ) ? $root->getAttribute( 'class' ) : null;
        $columnIDs = array();
        $header = array();
        foreach ( $root->childNodes as $child )
        {
            if ( $child->nodeType !== XML_ELEMENT_NODE || $child->nodeName !== 'columns' )
                continue;
            foreach ( $child->childNodes as $columnNode )
            {
                if ( $columnNode->nodeType !== XML_ELEMENT_NODE || $columnNode->nodeName !== 'column' )
                    continue;
                $key = $columnNode->getAttribute( 'name' );
                if ( $key === '' )
                    continue;
                $header[] = $key;
                $columnIDs[$key] = $columnNode->hasAttribute( 'id' ) && $columnNode->getAttribute( 'id' ) !== '' ? $columnNode->getAttribute( 'id' ) : $key;
            }
            break;
        }
        $rows = array();
        foreach ( $root->childNodes as $child )
        {
            if ( $child->nodeType !== XML_ELEMENT_NODE || $child->nodeName !== 'object' )
                continue;
            $row = array();
            foreach ( $child->childNodes as $fieldNode )
            {
                if ( $fieldNode->nodeType !== XML_ELEMENT_NODE || $fieldNode->nodeName !== 'field' )
                    continue;
                $key = $fieldNode->getAttribute( 'name' );
                if ( $key === '' )
                    continue;
                if ( !in_array( $key, $header, true ) )
                    $header[] = $key; // tolerant: a field the <columns> block did not list
                $row[$key] = $fieldNode->textContent;
            }
            $rows[] = $row;
        }
        return array( 'header' => $header, 'rows' => $rows, 'columnIDs' => $columnIDs, 'class' => $classIdentifier );
    }

    /** The first $bytes bytes of a file, UTF-8 - enough to sniff its format/separator without reading it whole. */
    public static function sniff( $path, $bytes = 65536 )
    {
        $fh = @fopen( $path, 'rb' );
        if ( !$fh )
            return '';
        $chunk = fread( $fh, $bytes );
        fclose( $fh );
        return XrowBaseHandler::utf8( (string)$chunk );
    }

    /** How many rows a JSON file is read in one go for, above which memory use grows with the file (see streamJSON()). */
    public static function jsonOneShotThresholdBytes()
    {
        $ini = eZINI::instance( 'csv.ini' );
        if ( $ini->hasVariable( 'Uploads', 'JsonOneShotThresholdMB' ) )
        {
            $mb = (int)$ini->variable( 'Uploads', 'JsonOneShotThresholdMB' );
            if ( $mb > 0 )
                return $mb * 1024 * 1024;
        }
        return 20 * 1024 * 1024;
    }

    /**
     * Streams every row of a file without loading it into memory (CSV via fgetcsv() on the open stream,
     * XML via XMLReader element by element). This is not "no limit at all": JSON above
     * jsonOneShotThresholdBytes() is still decoded in one go (json_decode() has no public streaming
     * API and a hand-rolled incremental JSON parser was judged too risky to get right in the time this
     * had - CSV and XML never have this limitation, and a large JSON file logs a clear memory estimate
     * when it takes this path). Throws RuntimeException with a human message on a DOCTYPE or malformed
     * input, exactly the errors parseFile() used to return - the caller decides how to show them.
     */
    public static function streamRows( $path, $format, $separator )
    {
        if ( $format === 'xml' )
            return self::streamXML( $path );
        if ( $format === 'json' )
            return self::streamJSON( $path );
        return self::streamCSV( $path, $separator );
    }

    protected static function streamCSV( $path, $separator )
    {
        $fh = fopen( $path, 'rb' );
        if ( !$fh )
            throw new RuntimeException( "Cannot read $path." );
        $bom = fread( $fh, 3 );
        if ( $bom !== "\xEF\xBB\xBF" )
            rewind( $fh );
        $header = fgetcsv( $fh, 0, $separator, '"', '' );
        $header = is_array( $header ) ? array_map( function ( $h ) { return trim( (string)$h ); }, $header ) : array();
        while ( ( $values = fgetcsv( $fh, 0, $separator, '"', '' ) ) !== false )
        {
            if ( $values === array( null ) )
                continue;
            $row = array();
            foreach ( $header as $i => $name )
                $row[$name] = isset( $values[$i] ) ? XrowBaseHandler::utf8( (string)$values[$i] ) : '';
            yield $row;
        }
        fclose( $fh );
    }

    /** A DOCTYPE anywhere in an XML file, checked by streaming (XMLReader::DOC_TYPE), not just near the top. */
    protected static function checkXMLReaderForDoctype( XMLReader $reader )
    {
        if ( $reader->nodeType === XMLReader::DOC_TYPE )
        {
            $reader->close();
            throw new RuntimeException( 'This file declares a DOCTYPE. That is refused: XrowExtractWriter never writes one, and a DOCTYPE can smuggle in external entities.' );
        }
    }

    protected static function streamXML( $path )
    {
        // Fast, cheap defense in depth: a DOCTYPE is required by the XML spec to precede the document
        // element, so it is always within the first few KB - reject it before XMLReader even opens the
        // file. The authoritative check (XMLReader::DOC_TYPE, below) covers the rest of the document too.
        if ( preg_match( '/<!DOCTYPE/i', self::sniff( $path, 65536 ) ) )
            throw new RuntimeException( 'This file declares a DOCTYPE. That is refused: XrowExtractWriter never writes one, and a DOCTYPE can smuggle in external entities.' );

        $reader = new XMLReader();
        $ok = @$reader->open( $path, null, LIBXML_NONET );
        if ( !$ok )
            throw new RuntimeException( 'Malformed XML: could not open the file.' );

        $header = array();
        $inColumns = false;
        while ( true )
        {
            $advanced = @$reader->read();
            if ( !$advanced )
                break;
            self::checkXMLReaderForDoctype( $reader );
            if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'columns' )
            {
                $inColumns = true;
            }
            elseif ( $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'columns' )
            {
                $inColumns = false;
            }
            elseif ( $inColumns && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'column' )
            {
                $key = $reader->getAttribute( 'name' );
                if ( $key !== null && $key !== '' && !in_array( $key, $header, true ) )
                    $header[] = $key;
            }
            elseif ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'object' )
            {
                $objectXml = $reader->readOuterXML();
                $objectDoc = new DOMDocument();
                $useErrors = libxml_use_internal_errors( true );
                $objectOk = @$objectDoc->loadXML( $objectXml, LIBXML_NONET | LIBXML_NOBLANKS );
                libxml_clear_errors();
                libxml_use_internal_errors( $useErrors );
                $row = array();
                if ( $objectOk && $objectDoc->documentElement )
                {
                    foreach ( $objectDoc->documentElement->childNodes as $fieldNode )
                    {
                        if ( $fieldNode->nodeType !== XML_ELEMENT_NODE || $fieldNode->nodeName !== 'field' )
                            continue;
                        $key = $fieldNode->getAttribute( 'name' );
                        if ( $key === '' )
                            continue;
                        if ( !in_array( $key, $header, true ) )
                            $header[] = $key;
                        $row[$key] = $fieldNode->textContent;
                    }
                }
                yield $row;
            }
        }
        $reader->close();
    }

    /**
     * JSON is decoded in one go when the file is at or below jsonOneShotThresholdBytes() (the common
     * case: JSON exports of this size are already fine in memory); above it, it is *still* decoded in
     * one go (see streamRows()'s note) but a clear memory estimate is written to the debug log first,
     * so a slow or memory-heavy run has an explanation on record instead of looking unexplained.
     */
    protected static function streamJSON( $path )
    {
        $size = @filesize( $path );
        if ( $size !== false && $size > self::jsonOneShotThresholdBytes() )
        {
            eZDebug::writeNotice( sprintf(
                'Reading a %s JSON import file in one go (no streaming JSON parser): ~%s of memory expected at peak. CSV and XML import files of any size do not have this limitation.',
                XrowExtractUpload::humanSize( $size ), XrowExtractUpload::humanSize( $size * 4 )
            ), 'XrowExtractImport' );
        }
        $data = json_decode( self::stripBOM( (string)file_get_contents( $path ) ), true );
        if ( !is_array( $data ) )
            throw new RuntimeException( 'Not a JSON array of objects.' );
        foreach ( $data as $item )
        {
            if ( !is_array( $item ) )
                continue;
            $row = array();
            foreach ( $item as $k => $v )
                $row[$k] = is_scalar( $v ) || $v === null ? (string)$v : json_encode( $v, JSON_UNESCAPED_UNICODE );
            yield $row;
        }
    }

    /** The header, and (xml) columnIDs/class, without materialising every row - a cheap first look at a file. */
    public static function fileHeader( $path, $format, $separator )
    {
        if ( $format === 'xml' )
        {
            if ( preg_match( '/<!DOCTYPE/i', self::sniff( $path, 65536 ) ) )
                throw new RuntimeException( 'This file declares a DOCTYPE. That is refused: XrowExtractWriter never writes one, and a DOCTYPE can smuggle in external entities.' );
            $useErrors = libxml_use_internal_errors( true );
            libxml_clear_errors();
            $reader = new XMLReader();
            if ( !@$reader->open( $path, null, LIBXML_NONET ) )
            {
                libxml_use_internal_errors( $useErrors );
                throw new RuntimeException( 'Malformed XML: could not open the file.' );
            }
            $header = array();
            $columnIDs = array();
            $class = null;
            $inColumns = false;
            $doneColumns = false;
            $sawColumnsEnd = false;
            while ( !$doneColumns && @$reader->read() )
            {
                self::checkXMLReaderForDoctype( $reader );
                if ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'export' && $class === null )
                    $class = $reader->getAttribute( 'class' );
                elseif ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'columns' )
                    $inColumns = true;
                elseif ( $reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'columns' )
                {
                    $doneColumns = true;
                    $sawColumnsEnd = true;
                }
                elseif ( $inColumns && $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'column' )
                {
                    $key = $reader->getAttribute( 'name' );
                    $id = $reader->getAttribute( 'id' );
                    if ( $key !== null && $key !== '' )
                    {
                        $header[] = $key;
                        $columnIDs[$key] = ( $id !== null && $id !== '' ) ? $id : $key;
                    }
                }
                elseif ( $reader->nodeType === XMLReader::ELEMENT && $reader->name === 'object' )
                {
                    // An <object> reached while still "inside" <columns> (its closing tag was never
                    // seen) is not a file this importer, or XrowExtractWriter, would ever produce -
                    // malformed structure, not just a missing header.
                    if ( $inColumns )
                    {
                        $reader->close();
                        libxml_use_internal_errors( $useErrors );
                        throw new RuntimeException( 'Malformed XML: <columns> is never closed before the first <object>.' );
                    }
                    break; // no <columns> block at all (an odd but not malformed file): stream the fields instead
                }
            }
            $readErrors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors( $useErrors );
            if ( $readErrors )
            {
                $reader->close();
                throw new RuntimeException( 'Malformed XML: ' . trim( $readErrors[0]->message ) );
            }
            $reader->close();
            if ( $header )
                return array( 'header' => $header, 'columnIDs' => $columnIDs, 'class' => $class );
            // No <columns> header found before the first <object>: collect field names from a stream pass
            foreach ( self::streamXML( $path ) as $row )
            {
                foreach ( array_keys( $row ) as $key )
                {
                    if ( !in_array( $key, $header, true ) )
                        $header[] = $key;
                }
            }
            return array( 'header' => $header, 'columnIDs' => array(), 'class' => $class );
        }
        if ( $format === 'json' )
        {
            $header = array();
            foreach ( self::streamJSON( $path ) as $row )
            {
                foreach ( array_keys( $row ) as $key )
                {
                    if ( !in_array( $key, $header, true ) )
                        $header[] = $key;
                }
                break; // the writer gives every object the same keys; one is enough
            }
            return array( 'header' => $header, 'columnIDs' => array(), 'class' => null );
        }
        $fh = fopen( $path, 'rb' );
        if ( !$fh )
            throw new RuntimeException( "Cannot read $path." );
        $bom = fread( $fh, 3 );
        if ( $bom !== "\xEF\xBB\xBF" )
            rewind( $fh );
        $header = fgetcsv( $fh, 0, $separator, '"', '' );
        fclose( $fh );
        $header = is_array( $header ) ? array_map( function ( $h ) { return trim( (string)$h ); }, $header ) : array();
        return array( 'header' => $header, 'columnIDs' => array(), 'class' => null );
    }

    /** An exact row count, streamed (constant memory regardless of file size - JSON's one-shot exception aside). */
    public static function countRows( $path, $format, $separator )
    {
        $n = 0;
        foreach ( self::streamRows( $path, $format, $separator ) as $row )
            $n++;
        return $n;
    }

    /**
     * Parses an uploaded file for the mapping/preview screen: the header, up to $previewLimit rows (a
     * large file is never read past that many, so building the preview is always fast), and the exact
     * total row count (one full streaming pass - still constant memory for CSV/XML). Format and
     * separator are auto-detected unless given.
     */
    public static function parseFile( $path, $format = null, $separator = null, $previewLimit = 200 )
    {
        $format = $format ?: self::detectFormat( self::sniff( $path ) );
        $separator = ( $format === 'csv' ) ? ( $separator ?: self::detectSeparator( self::sniff( $path ) ) ) : ',';
        try
        {
            $info = self::fileHeader( $path, $format, $separator );
            $rows = array();
            $total = 0;
            foreach ( self::streamRows( $path, $format, $separator ) as $row )
            {
                if ( $total < $previewLimit )
                    $rows[] = $row;
                $total++;
            }
            return array(
                'header' => $info['header'], 'rows' => $rows, 'total_rows' => $total,
                'columnIDs' => $info['columnIDs'], 'class' => $info['class'],
                'format' => $format, 'separator' => $separator,
            );
        }
        catch ( RuntimeException $e )
        {
            return array( 'header' => array(), 'rows' => array(), 'total_rows' => 0, 'format' => $format, 'separator' => $separator, 'error' => $e->getMessage() );
        }
    }

    // ------------------------------------------------------------- mapping

    /** exportname (as written in a header: '_' turned to '-') => special column id, for the columns matching() can use plus a few more useful ones. */
    protected static function specialColumnsByExportName( $allowPasswordHash = false )
    {
        $byName = array();
        foreach ( XrowExtractColumns::extraAttributes( $allowPasswordHash ) as $id => $column )
            $byName[str_replace( '_', '-', $column['exportname'] )] = $id;
        return $byName;
    }

    /**
     * A best guess at what a file column maps to: an attribute (with an optional
     * format), a special column, or 'ignore' when nothing matches. $classID may
     * be 0 when the class is not chosen yet (a 'class' column decides it row by
     * row); attribute columns are then left unmapped until it is. $columnIDs (a
     * file column name => the export's column id, e.g. "authors-ids" =>
     * "authors:ids") comes from an XML file's own <columns> block - when given,
     * it settles a column's target exactly, without guessing from its name.
     */
    public static function suggestMapping( array $header, $classID, array $columnIDs = null )
    {
        $specials = self::specialColumnsByExportName( false );
        $knownSpecialIDs = array_flip( $specials );
        $attrByID = array();
        $datatypeByID = array();
        $formatColumns = array();
        if ( $classID )
        {
            $info = self::classInfo( $classID );
            $attrByID = $info ? $info['attributes'] : array();
            foreach ( $attrByID as $identifier => $attrInfo )
                $datatypeByID[$identifier] = $attrInfo['datatype'];
            foreach ( XrowExtractCatalogue::formatColumns( $classID ) as $id => $column )
                $formatColumns[$id] = $column;
        }
        $mapping = array();
        foreach ( $header as $name )
        {
            $id = $columnIDs !== null && isset( $columnIDs[$name] ) ? $columnIDs[$name] : null;
            if ( $id !== null )
            {
                // Exact, from the file's own column id - no name guessing
                $target = 'ignore';
                $reason = '';
                if ( isset( $knownSpecialIDs[$id] ) )
                {
                    $target = 'special:' . $id;
                }
                elseif ( strpos( $id, ':' ) !== false )
                {
                    $target = ( $classID && isset( $formatColumns[$id] ) ) ? 'attrfmt:' . $id : 'ignore';
                    $reason = $target === 'ignore' ? ( $classID ? "no attribute format $id on this class" : 'choose a class first' ) : '';
                }
                elseif ( $classID && isset( $attrByID[$id] ) && self::columnIsImportable( $datatypeByID[$id], null ) )
                {
                    $target = 'attr:' . $id;
                }
                elseif ( $classID && isset( $attrByID[$id] ) )
                {
                    $reason = "the $id attribute is a {$datatypeByID[$id]}: " . self::unsupportedReason( $datatypeByID[$id] );
                }
                else
                {
                    $reason = $classID ? "no attribute $id on this class" : 'choose a class first, or map a "class" column';
                }
                $mapping[] = array( 'column' => $name, 'target' => $target, 'reason' => $reason );
                continue;
            }
            $key = str_replace( '-', '_', trim( $name ) );
            $target = 'ignore';
            $reason = '';
            if ( isset( $specials[str_replace( '_', '-', $key )] ) )
            {
                $target = 'special:' . $specials[str_replace( '_', '-', $key )];
            }
            elseif ( $classID && isset( $attrByID[$key] ) && self::columnIsImportable( $datatypeByID[$key], null ) )
            {
                $target = 'attr:' . $key;
            }
            elseif ( $classID && isset( $attrByID[$key] ) )
            {
                $reason = "the $key attribute is a {$datatypeByID[$key]}: " . self::unsupportedReason( $datatypeByID[$key] );
            }
            elseif ( $classID )
            {
                // identifier_format, longest identifier first so "main_image_url" prefers "main_image":"url" over "main":"image_url"
                $bestLen = -1;
                foreach ( $attrByID as $identifier => $column )
                {
                    if ( strpos( $key, $identifier . '_' ) !== 0 )
                        continue;
                    $format = substr( $key, strlen( $identifier ) + 1 );
                    $formatID = $identifier . ':' . $format;
                    if ( isset( $formatColumns[$formatID] ) && strlen( $identifier ) > $bestLen )
                    {
                        $bestLen = strlen( $identifier );
                        $target = 'attrfmt:' . $identifier . ':' . $format;
                    }
                }
                if ( $target === 'ignore' && isset( $attrByID[$key] ) === false )
                {
                    $reason = $classID ? 'no attribute, format or special column matches this header' : '';
                }
            }
            else
            {
                $reason = 'choose a class first, or map a "class" column';
            }
            $mapping[] = array( 'column' => $name, 'target' => $target, 'reason' => $reason );
        }
        return $mapping;
    }

    /** 'ignore' | array('kind'=>'special'|'attr'|'attrfmt', 'id'=>..., 'format'=>...|null) */
    public static function parseTarget( $value )
    {
        $value = (string)$value;
        if ( $value === '' || $value === 'ignore' )
            return array( 'kind' => 'ignore' );
        if ( strpos( $value, 'special:' ) === 0 )
            return array( 'kind' => 'special', 'id' => substr( $value, 8 ), 'format' => null );
        if ( strpos( $value, 'attrfmt:' ) === 0 )
        {
            $rest = substr( $value, 8 );
            $pos = strrpos( $rest, ':' );
            return $pos === false ? array( 'kind' => 'ignore' )
                                   : array( 'kind' => 'attrfmt', 'id' => substr( $rest, 0, $pos ), 'format' => substr( $rest, $pos + 1 ) );
        }
        if ( strpos( $value, 'attr:' ) === 0 )
            return array( 'kind' => 'attr', 'id' => substr( $value, 5 ), 'format' => null );
        return array( 'kind' => 'ignore' );
    }

    // ------------------------------------------------------------ convert

    /**
     * 1/0 from many spellings; unrecognised text is 0, same as the kernel's own
     * default. The export handler for ezboolean encloses the cell in single
     * quotes (its own convention, unlike every other datatype's double
     * quotes), so a CSV round trip reads e.g. 'autoplay' back as "'0'" -
     * strip a matching pair before comparing.
     */
    public static function normalizeBoolean( $raw )
    {
        $value = trim( (string)$raw );
        if ( strlen( $value ) >= 2 && $value[0] === "'" && substr( $value, -1 ) === "'" )
            $value = substr( $value, 1, -1 );
        $value = strtolower( trim( $value ) );
        if ( in_array( $value, array( '1', 'ja', 'yes', 'true', 'on', 'wahr' ), true ) )
            return '1';
        if ( in_array( $value, array( '0', 'nein', 'no', 'false', 'off', 'falsch', '' ), true ) )
            return '0';
        if ( is_numeric( $value ) )
            return ( (float)$value != 0 ) ? '1' : '0';
        return '0';
    }

    /**
     * A date/time column (Y-m-d, Y-m-d H:i:s, ISO 8601, or a Unix timestamp) as
     * the timestamp string ezdate/ezdatetime's fromString() expects.
     */
    public static function convertDate( $raw, $withTime )
    {
        $raw = trim( (string)$raw );
        if ( $raw === '' )
            return array( true, '' , null );
        if ( ctype_digit( $raw ) || ( $raw[0] === '-' && ctype_digit( substr( $raw, 1 ) ) ) )
            return array( true, (string)(int)$raw , null );
        $time = strtotime( $raw );
        if ( $time === false )
            return array( false, null, "not a date: $raw" );
        return array( true, (string)$time , null );
    }

    /** Option names (as fromString expects, '|' separated) from names or numeric option ids. */
    public static function convertSelection( array $options, $raw )
    {
        $raw = trim( (string)$raw );
        if ( $raw === '' )
            return array( true, '' , null );
        $byID = array();
        $byName = array();
        foreach ( $options as $option )
        {
            $byID[(string)$option['id']] = $option['name'];
            $byName[$option['name']] = true;
        }
        $names = array();
        foreach ( preg_split( '/\s*[|,]\s*/', $raw ) as $piece )
        {
            if ( $piece === '' )
                continue;
            if ( isset( $byID[$piece] ) )
                $names[] = $byID[$piece];
            elseif ( isset( $byName[$piece] ) )
                $names[] = $piece;
            else
                return array( false, null, "unknown option: $piece" );
        }
        return array( true, implode( '|', $names ) , null );
    }

    /**
     * The "|#ids|#keywords|#parents|#locales" string eztags' fromString() expects,
     * from a comma list of tag names: each name must resolve to exactly one
     * existing tag (unambiguous, already created via the tags admin). Anything
     * else (no match, several matches, a "/" path) is too complex here and is
     * refused with a warning instead of guessing.
     */
    public static function convertTags( $raw, $language )
    {
        $raw = trim( (string)$raw );
        if ( $raw === '' )
            return array( true, '' , null );
        $ids = $keywords = $parents = $locales = array();
        foreach ( preg_split( '/\s*,\s*/', $raw ) as $name )
        {
            if ( $name === '' )
                continue;
            if ( strpos( $name, '/' ) !== false )
                return array( false, null, "tag path \"$name\" is too complex to import; use the tags admin to create it and import the plain name" );
            $matches = class_exists( 'eZTagsObject' ) ? eZTagsObject::fetchByKeyword( $name ) : array();
            if ( count( $matches ) !== 1 )
                return array( false, null, count( $matches ) === 0 ? "no tag named \"$name\"" : "several tags are named \"$name\": too ambiguous to import" );
            $tag = $matches[0];
            $ids[] = $tag->attribute( 'id' );
            $keywords[] = $tag->attribute( 'keyword' );
            $parents[] = $tag->attribute( 'parent_id' );
            $tagLanguage = eZContentLanguage::fetch( $tag->attribute( 'main_language_id' ) );
            $locales[] = ( $tagLanguage instanceof eZContentLanguage ? $tagLanguage->attribute( 'locale' ) : '' ) ?: $language;
        }
        if ( !$ids )
            return array( true, '' , null );
        return array( true, implode( '|#', $ids ) . '|#' . implode( '|#', $keywords ) . '|#' . implode( '|#', $parents ) . '|#' . implode( '|#', $locales ), null );
    }

    /**
     * The stored eZXMLText XML for HTML text, using the ezoe input parser when
     * that extension is active (its parser is what the editor itself uses to
     * turn typed/pasted HTML into the stored format), else one paragraph per
     * non-empty line of the plain text stripped from the HTML.
     */
    public static function convertXmlText( $raw, &$usedEzoe )
    {
        $raw = (string)$raw;
        if ( trim( $raw ) === '' )
        {
            $usedEzoe = false;
            return array( true, '' , null );
        }
        if ( class_exists( 'eZOEInputParser' ) )
        {
            $parser = new eZOEInputParser();
            $doc = $parser->process( $raw );
            if ( $doc instanceof DOMDocument )
            {
                $usedEzoe = true;
                return array( true, $doc->saveXML() , null );
            }
        }
        $usedEzoe = false;
        $text = html_entity_decode( strip_tags( preg_replace( '#<(br|/p|/li|/h\d|/div)[^>]*>#i', "\n", $raw ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $text ) ), function ( $l ) { return $l !== ''; } );
        $xml = '<?xml version="1.0" encoding="utf-8"?><section xmlns:image="http://ez.no/namespaces/ezpublish3/image/" xmlns:xhtml="http://ez.no/namespaces/ezpublish3/xhtml/" xmlns:custom="http://ez.no/namespaces/ezpublish3/custom/">';
        foreach ( $lines as $line )
            $xml .= '<paragraph>' . htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' ) . '</paragraph>';
        if ( !$lines )
            $xml .= '<paragraph/>';
        $xml .= '</section>';
        return array( true, $xml , null );
    }

    /**
     * A path fromString() can import for ezimage/ezbinaryfile/ezmedia: a path
     * already inside var/storage (as the export writes it) is used as is; an
     * absolute http(s) URL of this installation is downloaded into the private
     * upload folder first. Anything else is refused.
     */
    public static function convertFile( $raw, $datatype, $importDir )
    {
        $raw = trim( (string)$raw );
        if ( $raw === '' )
            return array( true, '' , null );
        $publicHost = XrowExtractColumns::publicHostURL();
        $path = $raw;
        if ( preg_match( '#^https?://#i', $raw ) )
        {
            if ( strpos( $raw, $publicHost ) !== 0 )
                return array( false, null, "not a URL of this site: $raw" );
            $data = @file_get_contents( $raw );
            if ( $data === false )
                return array( false, null, "could not download $raw" );
            $name = basename( parse_url( $raw, PHP_URL_PATH ) );
            $name = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name !== '' ? $name : 'file' );
            $target = rtrim( $importDir, '/' ) . '/' . uniqid( 'dl_', true ) . '_' . $name;
            file_put_contents( $target, $data );
            $path = $target;
        }
        elseif ( strpos( $raw, '..' ) !== false || preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*://#', $raw ) )
        {
            return array( false, null, "not an importable path: $raw" );
        }
        // No "|original name" suffix: the export writes the plain path (filePath()) for
        // ezbinaryfile/ezmedia too, so an unchanged value round-trips to the same string;
        // fromString() derives the original file name from the path itself in that case.
        return array( true, $path , null );
    }

    /** ids or remote_ids (comma/pipe separated) as the '-'-joined id string fromString() expects. */
    public static function convertRelation( $raw, $format, $isList )
    {
        $raw = trim( (string)$raw );
        if ( $format !== 'ids' && $format !== 'remote_ids' )
            return array( false, null, 'names are ambiguous; map an :ids or :remote_ids column instead' );
        if ( $raw === '' )
            return array( true, '' , null );
        $ids = array();
        foreach ( preg_split( '/\s*[,|]\s*/', $raw ) as $piece )
        {
            if ( $piece === '' )
                continue;
            $object = $format === 'ids' ? ( ctype_digit( $piece ) ? eZContentObject::fetch( (int)$piece ) : null )
                                        : eZContentObject::fetchByRemoteID( $piece );
            if ( !$object instanceof eZContentObject )
                return array( false, null, "no object for $format \"$piece\"" );
            $ids[] = $object->attribute( 'id' );
            if ( !$isList )
                break;
        }
        return array( true, implode( '-', $ids ) , null );
    }

    /** The stored xrowmetadata XML from the :json format's fields. */
    public static function convertMetadata( $raw, $format )
    {
        if ( $format !== 'json' )
            return array( false, null, 'only the :json column of xrowmetadata can be imported' );
        $raw = trim( (string)$raw );
        if ( $raw === '' )
            return array( true, '' , null );
        $data = json_decode( $raw, true );
        if ( !is_array( $data ) )
            return array( false, null, 'not valid JSON' );
        $get = function ( $key, $default = '' ) use ( $data ) { return isset( $data[$key] ) ? $data[$key] : $default; };
        $keywords = $get( 'keywords', array() );
        if ( is_string( $keywords ) )
            $keywords = array_filter( array_map( 'trim', explode( ',', $keywords ) ) );
        $dom = new DOMDocument( '1.0', 'UTF-8' );
        $root = $dom->createElement( 'MetaData' );
        $dom->appendChild( $root );
        $fields = array(
            'title' => $get( 'title' ), 'keywords' => implode( ',', (array)$keywords ), 'description' => $get( 'description' ),
            'canonical_url' => $get( 'canonical', $get( 'canonical_url' ) ), 'priority' => $get( 'priority' ), 'change' => $get( 'change' ),
            'sitemap_use' => $get( 'sitemap', $get( 'sitemap_use' ) ) ? '1' : '0', 'og_image' => $get( 'og_image' ),
            'og_image_width' => $get( 'og_image_width' ), 'og_image_height' => $get( 'og_image_height' ),
            'og_image_alt' => $get( 'og_image_alt' ), 'og_image_type' => $get( 'og_image_type' ),
        );
        foreach ( $fields as $name => $value )
            $root->appendChild( $dom->createElement( $name, htmlspecialchars( (string)$value, ENT_QUOTES, 'UTF-8' ) ) );
        return array( true, $dom->saveXML() , null );
    }

    // -------------------------------------------------------------- match

    /** The object a row's identity columns point to, or null when none match (a create). */
    public static function findMatch( $matchMode, $remoteID, $objectID )
    {
        if ( $matchMode === 'remote_id' && $remoteID !== '' )
        {
            $object = eZContentObject::fetchByRemoteID( $remoteID );
            return $object instanceof eZContentObject ? $object : null;
        }
        if ( $matchMode === 'object_id' && $objectID !== '' && ctype_digit( (string)$objectID ) )
        {
            $object = eZContentObject::fetch( (int)$objectID );
            return $object instanceof eZContentObject ? $object : null;
        }
        return null;
    }

    /** The parent node id for a new object: a remote id column, a node id column, or the fallback node. */
    public static function resolveParent( $parentRemoteID, $parentNodeID, $fallbackNodeID )
    {
        if ( $parentRemoteID !== '' )
        {
            $node = eZContentObjectTreeNode::fetchByRemoteID( $parentRemoteID );
            if ( $node instanceof eZContentObjectTreeNode )
                return (int)$node->attribute( 'node_id' );
            return false;
        }
        if ( $parentNodeID !== '' && ctype_digit( (string)$parentNodeID ) )
            return (int)$parentNodeID;
        return (int)$fallbackNodeID;
    }

    /**
     * A section id from a numeric id, or the section name as the "section" special
     * column is exported (XrowExtractColumns::extraValue() writes the name, not the
     * id). array(ok, id, warning).
     */
    public static function resolveSectionID( $raw )
    {
        $raw = trim( (string)$raw );
        if ( ctype_digit( $raw ) )
            return array( true, (int)$raw, null );
        $sections = eZPersistentObject::fetchObjectList( eZSection::definition(), null, array( 'name' => $raw ) );
        if ( count( $sections ) === 1 )
            return array( true, (int)$sections[0]->attribute( 'id' ), null );
        if ( count( $sections ) > 1 )
            return array( false, null, "several sections are named \"$raw\": too ambiguous to import" );
        return array( false, null, "no section named \"$raw\"" );
    }

    // ---------------------------------------------------------------- run

    /**
     * Runs a parsed file through the mapping: dry run (default) builds the
     * preview only, apply=true also writes. Options:
     *   rows, mapping (from suggestMapping()/parseTarget(), one per file column,
     *   in file column order), classID (fallback), match ('remote_id'|'object_id'|'none'),
     *   language (fallback locale), parentNodeID (fallback), apply (bool).
     * Returns array('rows'=>[...], 'counts'=>[...], 'ezoe'=>bool|null).
     */
    public static function run( array $options )
    {
        $rows = $options['rows'];
        $mapping = $options['mapping'];
        $fallbackClassID = (int)$options['classID'];
        $matchMode = in_array( $options['match'], array( 'remote_id', 'object_id', 'none' ), true ) ? $options['match'] : 'remote_id';
        $fallbackLanguage = $options['language'];
        $fallbackParent = (int)$options['parentNodeID'];
        $apply = !empty( $options['apply'] );
        $importDir = self::uploadDir();

        $counts = array( 'create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0, 'error' => 0 );
        $result = array();
        $classCache = array();
        $usedEzoe = null;
        // Raw (unescaped) parser: builds the exact cell text the export handlers would
        // write for the object's current value, so "changed?" and the CSV cell it came
        // from are compared like for like instead of against toString()'s own format
        // (which differs from the export for several datatypes: ezboolean's quoting,
        // ezselection's ids vs names, ezxmltext's exact serialisation, ezimage's "|alt").
        $exportParser = new ParserInterface( ',', true, true );

        // Resume: rows before skipRows are not looked at at all (cheap for a generator - it just
        // parses and discards them, no attribute conversion or database work happens for them).
        // Progress: onProgress( doneCount, totalRows|null, counts ) every progressEvery rows, for a
        // background job to write progress.json from without run() knowing anything about jobs.
        $skip = (int)( isset( $options['skipRows'] ) ? $options['skipRows'] : 0 );
        $totalRows = isset( $options['totalRows'] ) ? (int)$options['totalRows'] : null;
        $onProgress = isset( $options['onProgress'] ) && is_callable( $options['onProgress'] ) ? $options['onProgress'] : null;
        $progressEvery = max( 1, (int)( isset( $options['progressEvery'] ) ? $options['progressEvery'] : 25 ) );
        // A huge streamed run must never hold every row's result in memory just to return it at the
        // end; collectRows=false (a background job) calls onRow() for each and keeps only the counts.
        $collectRows = !array_key_exists( 'collectRows', $options ) || $options['collectRows'];
        $onRow = isset( $options['onRow'] ) && is_callable( $options['onRow'] ) ? $options['onRow'] : null;
        // Every one of this row's exit points (an early error, "unchanged", or falling through to the
        // very end after an apply) calls this instead of appending to $result directly, so collectRows
        // and onRow are honoured everywhere alike, not only on the row's one "happy path".
        $finishRow = function ( $rowResult, $row ) use ( &$result, $collectRows, $onRow )
        {
            if ( $collectRows )
                $result[] = $rowResult;
            if ( $onRow )
                call_user_func( $onRow, $rowResult, $row );
        };

        $number = (int)( isset( $options['startNumber'] ) ? $options['startNumber'] : 1 );
        $index = 0;
        foreach ( $rows as $row )
        {
            $index++;
            if ( $index <= $skip )
            {
                $number++;
                continue;
            }
            if ( $onProgress && ( $index - $skip ) % $progressEvery === 0 )
                call_user_func( $onProgress, $index, $totalRows, $counts );

            $rowResult = array( 'number' => $number++, 'action' => 'skip', 'reason' => '', 'object_id' => null, 'node_id' => null, 'changes' => array() );

            // Which class, from a "class" column if mapped, else the fallback
            $classIdentifier = null;
            foreach ( $mapping as $i => $map )
            {
                $target = self::parseTarget( $map['target'] );
                if ( $target['kind'] === 'special' && $target['id'] === 'ezcontentobject.class_identifier' )
                    $classIdentifier = trim( (string)( isset( $row[$map['column']] ) ? $row[$map['column']] : '' ) );
            }
            $classID = $fallbackClassID;
            if ( $classIdentifier )
            {
                $class = ctype_digit( $classIdentifier ) ? eZContentClass::fetch( (int)$classIdentifier ) : eZContentClass::fetchByIdentifier( $classIdentifier );
                if ( !$class instanceof eZContentClass )
                {
                    $rowResult['action'] = 'error';
                    $rowResult['reason'] = "unknown class \"$classIdentifier\"";
                    $finishRow( $rowResult, $row );
                    $counts['error']++;
                    continue;
                }
                $classID = (int)$class->attribute( 'id' );
            }
            if ( !$classID )
            {
                $rowResult['action'] = 'error';
                $rowResult['reason'] = 'no class (choose one, or map a "class" column)';
                $finishRow( $rowResult, $row );
                $counts['error']++;
                continue;
            }
            if ( !isset( $classCache[$classID] ) )
                $classCache[$classID] = self::classInfo( $classID );
            $info = $classCache[$classID];
            if ( !$info )
            {
                $rowResult['action'] = 'error';
                $rowResult['reason'] = "class $classID does not exist";
                $finishRow( $rowResult, $row );
                $counts['error']++;
                continue;
            }
            // The result panel names the class of every row (a "class" column can mix several)
            $rowResult['class_id'] = $classID;
            $rowResult['class_identifier'] = $info['identifier'];
            $rowResult['class_name'] = isset( $info['name'] ) ? $info['name'] : $info['identifier'];

            // Identity, language and placement columns
            $remoteID = $objectID = $language = $parentRemoteID = $parentNodeID = $published = $modified = $sectionID = '';
            $attributeValues = array(); // identifier => array( raw, format )
            $unknownColumn = null;
            foreach ( $mapping as $map )
            {
                $target = self::parseTarget( $map['target'] );
                $raw = isset( $row[$map['column']] ) ? $row[$map['column']] : '';
                if ( $target['kind'] === 'special' )
                {
                    switch ( $target['id'] )
                    {
                        case 'ezcontentobject.remote_id':        $remoteID = trim( (string)$raw ); break;
                        case 'ezcontentobject.id':                $objectID = trim( (string)$raw ); break;
                        case 'ezcontentobject.language':          $language = trim( (string)$raw ); break;
                        case 'node.parent_remote_id':             $parentRemoteID = trim( (string)$raw ); break;
                        case 'ezcontentobject.main_parent_node_id': $parentNodeID = trim( (string)$raw ); break;
                        case 'ezcontentobject.published':
                        case 'ezcontentobject.published_timestamp': $published = trim( (string)$raw ); break;
                        case 'ezcontentobject.modified':
                        case 'ezcontentobject.modified_timestamp':  $modified = trim( (string)$raw ); break;
                        case 'ezcontentobject.section':           $sectionID = trim( (string)$raw ); break;
                    }
                }
                elseif ( $target['kind'] === 'attr' || $target['kind'] === 'attrfmt' )
                {
                    if ( !isset( $info['attributes'][$target['id']] ) )
                    {
                        // A mapping (typically --map on the CLI, or a stale mapping after
                        // switching class) names an attribute this class does not have.
                        $unknownColumn = "unknown attribute \"{$target['id']}\" for class {$info['identifier']} (column \"{$map['column']}\")";
                        break;
                    }
                    $attributeValues[$target['id']] = array( 'raw' => $raw, 'format' => $target['kind'] === 'attrfmt' ? $target['format'] : null );
                }
            }
            if ( $unknownColumn !== null )
            {
                $rowResult['action'] = 'error';
                $rowResult['reason'] = $unknownColumn;
                $finishRow( $rowResult, $row );
                $counts['error']++;
                continue;
            }
            $language = $language ?: $fallbackLanguage;

            $match = self::findMatch( $matchMode, $remoteID, $objectID );
            $isUpdate = $match instanceof eZContentObject;
            $dataMap = array();
            if ( $isUpdate )
            {
                $dataMap = $language && in_array( $language, $match->availableLanguages(), true )
                         ? $match->fetchDataMap( false, $language ) : $match->fetchDataMap();
            }

            // Convert every mapped attribute value, and (for an update) the same attribute's
            // current value re-rendered the way the export would write it, so the two are
            // comparable and the preview can show "old -> new" in the file's own terms.
            $converted = array(); // identifier => dataString
            $exportedOld = array(); // identifier => the current value as a CSV cell would hold it
            $rowError = null;
            foreach ( $attributeValues as $identifier => $entry )
            {
                $attrInfo = $info['attributes'][$identifier];
                if ( !self::columnIsImportable( $attrInfo['datatype'], $entry['format'] ) )
                {
                    $rowError = "$identifier ({$attrInfo['datatype']}): " . self::unsupportedReason( $attrInfo['datatype'] );
                    break;
                }
                list( $ok, $value, $warning ) = self::convertOne( $attrInfo, $entry['raw'], $entry['format'], $language, $importDir, $usedEzoe );
                if ( !$ok )
                {
                    $rowError = "$identifier: $warning";
                    break;
                }
                $converted[$identifier] = $value;
                if ( $isUpdate && isset( $dataMap[$identifier] ) && is_object( $dataMap[$identifier] ) )
                {
                    $exportedOld[$identifier] = $entry['format']
                        ? XrowExtractCatalogue::formatValue( $dataMap[$identifier], $entry['format'] )
                        : $exportParser->exportValue( $dataMap[$identifier] );
                }
                else
                {
                    $exportedOld[$identifier] = '';
                }
            }

            if ( $rowError === null && $isUpdate && !$match->canEdit() )
                $rowError = 'no permission to edit object ' . $match->attribute( 'id' );

            $parentID = 0;
            if ( $rowError === null && !$isUpdate )
            {
                $parentID = self::resolveParent( $parentRemoteID, $parentNodeID, $fallbackParent );
                if ( !$parentID )
                {
                    $rowError = 'no parent (parent remote id / main parent node id column, or a chosen node)';
                }
                else
                {
                    $parentNode = eZContentObjectTreeNode::fetch( $parentID );
                    if ( !$parentNode instanceof eZContentObjectTreeNode )
                        $rowError = "parent node $parentID does not exist";
                    elseif ( !$parentNode->checkAccess( 'create', $classID ) )
                        $rowError = "no permission to create {$info['identifier']} under node $parentID";
                }
            }

            if ( $rowError !== null )
            {
                $rowResult['action'] = 'error';
                $rowResult['reason'] = $rowError;
                $finishRow( $rowResult, $row );
                $counts['error']++;
                continue;
            }

            // Build the diff (old -> new), comparing both sides in the same "as fromString()
            // will see it" space (converting the old exported text the same way as the new
            // file cell) so a value that is really unchanged never shows up as a difference
            // merely because the internal storage format differs from the export's. What is
            // shown to the user is the readable export-space text on both sides.
            $changes = array();
            foreach ( $converted as $identifier => $newValue )
            {
                $oldDisplay = isset( $exportedOld[$identifier] ) ? (string)$exportedOld[$identifier] : '';
                if ( !$isUpdate )
                {
                    if ( (string)$newValue !== '' )
                        $changes[] = array( 'field' => $identifier, 'old' => '', 'new' => $attributeValues[$identifier]['raw'] );
                    continue;
                }
                $format = $attributeValues[$identifier]['format'];
                $attrInfo = $info['attributes'][$identifier];
                list( $okOld, $oldConverted ) = self::convertOne( $attrInfo, $oldDisplay, $format, $language, $importDir, $usedEzoe );
                if ( !$okOld || (string)$oldConverted !== (string)$newValue )
                    $changes[] = array( 'field' => $identifier, 'old' => $oldDisplay, 'new' => $attributeValues[$identifier]['raw'] );
            }
            if ( $sectionID !== '' )
            {
                list( $sectionOk, $resolvedSectionID, $sectionWarning ) = self::resolveSectionID( $sectionID );
                if ( !$sectionOk )
                {
                    $rowResult['action'] = 'error';
                    $rowResult['reason'] = "section: $sectionWarning";
                    $finishRow( $rowResult, $row );
                    $counts['error']++;
                    continue;
                }
                if ( !$isUpdate || (int)$match->attribute( 'section_id' ) !== $resolvedSectionID )
                    $changes[] = array( 'field' => 'section', 'old' => $isUpdate ? (string)$match->attribute( 'section_id' ) : '', 'new' => $sectionID );
            }
            else
            {
                $resolvedSectionID = null;
            }

            $rowResult['object_id'] = $isUpdate ? (int)$match->attribute( 'id' ) : null;
            // The matched object's current name and node, so the preview links it by name
            $rowResult['object_name'] = $isUpdate ? (string)$match->attribute( 'name' ) : '';
            if ( $isUpdate && (int)$match->attribute( 'main_node_id' ) )
                $rowResult['node_id'] = (int)$match->attribute( 'main_node_id' );
            $rowResult['changes'] = $changes;

            if ( $isUpdate && !$changes )
            {
                $rowResult['action'] = 'unchanged';
                $finishRow( $rowResult, $row );
                $counts['unchanged']++;
                continue;
            }
            $rowResult['action'] = $isUpdate ? 'update' : 'create';
            $counts[$isUpdate ? 'update' : 'create']++;

            // Only the attributes that are really changing (as $changes above found) are
            // written: an ezimage/ezbinaryfile fromString() re-imports the file and bumps its
            // stored version even when given the exact same path, so re-sending every mapped
            // column on every update would make "no other field changed" false on a re-export.
            $changedAttributes = array();
            foreach ( $changes as $change )
            {
                if ( $change['field'] !== 'section' && array_key_exists( $change['field'], $converted ) )
                    $changedAttributes[$change['field']] = $converted[$change['field']];
            }

            if ( $apply )
            {
                // No transaction wrapper here: createAndPublishObject() and the publish
                // operation manage their own (and the publish operation's finishing steps -
                // setting current_version, indexing - turned out to be skipped when it found
                // itself already inside an open outer transaction). Every value was already
                // validated above, before any of this runs, so a row that reaches here either
                // writes cleanly or throws before touching the object further.
                try
                {
                    if ( $isUpdate )
                    {
                        $params = array( 'attributes' => $changedAttributes );
                        if ( $language )
                            $params['language'] = $language;
                        if ( $resolvedSectionID !== null )
                            $params['section_id'] = $resolvedSectionID;
                        $ok = eZContentFunctions::updateAndPublishObject( $match, $params );
                        if ( !$ok )
                            throw new Exception( 'updateAndPublishObject failed' );
                        $objectID = (int)$match->attribute( 'id' );
                        $object = $match;
                    }
                    else
                    {
                        $params = array(
                            'parent_node_id' => $parentID,
                            'class_identifier' => $info['identifier'],
                            'attributes' => $changedAttributes,
                        );
                        if ( $remoteID !== '' )
                            $params['remote_id'] = $remoteID;
                        if ( $language )
                            $params['language'] = $language;
                        if ( $resolvedSectionID !== null )
                            $params['section_id'] = $resolvedSectionID;
                        $object = eZContentFunctions::createAndPublishObject( $params );
                        if ( !$object instanceof eZContentObject )
                            throw new Exception( 'createAndPublishObject failed' );
                        $objectID = (int)$object->attribute( 'id' );
                        $rowResult['object_id'] = $objectID;
                    }
                    if ( $published !== '' || $modified !== '' )
                    {
                        list( $okP, $tsP ) = $published !== '' ? self::convertDate( $published, true ) : array( true, null );
                        list( $okM, $tsM ) = $modified !== '' ? self::convertDate( $modified, true ) : array( true, null );
                        if ( ( $okP && $tsP !== null && $tsP !== '' ) || ( $okM && $tsM !== null && $tsM !== '' ) )
                        {
                            // A fresh instance: $object (whether $match or createAndPublishObject()'s
                            // return value) was fetched before the publish operation ran and still
                            // carries the pre-publish current_version/status/name in memory. Storing
                            // it as is would silently undo what publishing just did.
                            eZContentObject::clearCache( array( $objectID ) );
                            $fresh = eZContentObject::fetch( $objectID );
                            if ( $fresh instanceof eZContentObject )
                            {
                                if ( $okP && $tsP !== null && $tsP !== '' )
                                    $fresh->setAttribute( 'published', (int)$tsP );
                                if ( $okM && $tsM !== null && $tsM !== '' )
                                    $fresh->setAttribute( 'modified', (int)$tsM );
                                $fresh->store();
                                eZContentObject::clearCache( array( $objectID ) );
                            }
                        }
                    }
                    // The main node, for the result panel's "open it" link - fetched fresh (see above)
                    eZContentObject::clearCache( array( $objectID ) );
                    $forNode = eZContentObject::fetch( $objectID );
                    $rowResult['node_id'] = ( $forNode instanceof eZContentObject && (int)$forNode->attribute( 'main_node_id' ) )
                        ? (int)$forNode->attribute( 'main_node_id' ) : null;
                }
                catch ( Exception $e )
                {
                    $rowResult['action'] = 'error';
                    $rowResult['reason'] = $e->getMessage();
                    $counts[$isUpdate ? 'update' : 'create']--;
                    $counts['error']++;
                }
            }
            $finishRow( $rowResult, $row );
        }
        if ( $onProgress )
            call_user_func( $onProgress, $index, $totalRows, $counts );

        return array( 'rows' => $result, 'counts' => $counts, 'ezoe' => $usedEzoe );
    }

    // ------------------------------------------------------- sample & docs

    /** Up to $limit real, readable, published objects of a class - for the sample and the documentation examples. */
    public static function realObjects( $classID, $limit = 2 )
    {
        $objects = array();
        foreach ( (array)eZContentObject::fetchSameClassList( (int)$classID, true, 0, max( 10, $limit * 3 ) ) as $object )
        {
            if ( !$object instanceof eZContentObject || (int)$object->attribute( 'status' ) !== eZContentObject::STATUS_PUBLISHED || !$object->canRead() )
                continue;
            $objects[] = $object;
            if ( count( $objects ) >= $limit )
                break;
        }
        return $objects;
    }

    /** The Migration column set of a class, resolved - the same columns "Download a template" writes. */
    public static function migrationColumns( $classID )
    {
        return XrowExtractCatalogue::resolveColumns( XrowExtractCatalogue::setColumnIDs( 'migration', $classID ), $classID, XrowExtractColumns::extraAttributes( false ) );
    }

    /** The first base (no format) ezstring/eztext attribute of a class: what the sample edits and titles. */
    public static function titleColumn( $classID )
    {
        $info = self::classInfo( $classID );
        if ( !$info )
            return null;
        foreach ( $info['attributes'] as $identifier => $attribute )
        {
            if ( in_array( $attribute['datatype'], array( 'ezstring', 'eztext' ), true ) )
                return $identifier;
        }
        return null;
    }

    /** A small, valid placeholder for a required attribute the sample's new object fills in. */
    public static function placeholderValue( $datatype, eZContentClassAttribute $classAttribute = null )
    {
        switch ( $datatype )
        {
            case 'ezstring':
            case 'eztext':      return 'Sample text';
            case 'ezinteger':   return '1';
            case 'ezfloat':     return '1';
            case 'ezboolean':   return '0';
            case 'ezdate':      return date( 'Y-m-d' );
            case 'ezdatetime':  return date( 'Y-m-d H:i:s' );
            case 'ezemail':     return 'sample@example.com';
            case 'ezurl':       return 'https://example.com';
            case 'ezidentifier':
            case 'ezkeyword':   return 'sample';
            case 'ezxmltext':   return '<p>Sample text.</p>';
            case 'ezselection':
                $content = $classAttribute instanceof eZContentClassAttribute ? $classAttribute->content() : array();
                return isset( $content['options'][0]['name'] ) ? $content['options'][0]['name'] : '';
        }
        return '';
    }

    /**
     * A CSV/JSON/XML sample file built from the site's own content, to try the importer without first
     * having to build a file: one existing object of the class with a visible edit (an "update" row), a
     * second existing object unchanged, one brand new object (a "create" row, placed under $parentNodeID),
     * and - when the class has an importable date attribute - one row with a deliberately bad date (an
     * "error" row). Uses the real export code (XrowExtractColumns/XrowExtractCatalogue/XrowExtractWriter)
     * throughout, so every value is exactly what a real export of this class would write.
     */
    public static function buildSample( $classID, $parentNodeID, $language, $format )
    {
        $classID = (int)$classID;
        $class = eZContentClass::fetch( $classID );
        if ( !$class instanceof eZContentClass )
            return array( 'ok' => false, 'error' => 'no such class' );
        $columns = self::migrationColumns( $classID );
        if ( !$columns )
            return array( 'ok' => false, 'error' => 'this class has no columns to sample' );
        $info = self::classInfo( $classID );

        $extras = XrowExtractColumns::extraAttributes( false );
        $allowHash = XrowExtractColumns::allowPasswordHash();
        $writer = new XrowExtractWriter( $format, $columns, ',', true, "\n", array( 'class' => $class->attribute( 'identifier' ), 'created' => date( 'c' ), 'sample' => '1' ) );
        $parser = $writer->parser();

        $titleIdentifier = self::titleColumn( $classID );
        $objects = self::realObjects( $classID, 2 );
        $rows = array();
        XrowExtractColumns::$language = null;
        foreach ( $objects as $i => $object )
        {
            $locale = in_array( $language, $object->availableLanguages(), true ) ? $language : $object->attribute( 'initial_language_code' );
            XrowExtractColumns::$language = $locale;
            $cells = XrowExtractColumns::rowCells( $columns, $object, $parser, $extras, $allowHash );
            if ( $i === 0 && $titleIdentifier )
            {
                foreach ( $columns as $ci => $column )
                {
                    if ( $column['id'] === $titleIdentifier )
                        $cells[$ci] = trim( (string)$cells[$ci] ) !== '' ? $cells[$ci] . ' (edited)' : 'Sample edit (edited)';
                }
            }
            $rows[] = array( 'cells' => $cells, 'kind' => $i === 0 ? 'update' : 'unchanged' );
        }
        XrowExtractColumns::$language = null;

        // A brand new object: every special column filled sensibly, the title-ish attribute named "Sample
        // <class>", every other required base-importable attribute given a small placeholder
        $parentNode = $parentNodeID ? eZContentObjectTreeNode::fetch( (int)$parentNodeID ) : null;
        $sampleSuffix = substr( md5( uniqid( '', true ) ), 0, 6 );
        $newRemoteID = 'xrowextract-sample-' . $class->attribute( 'identifier' ) . '-' . $sampleSuffix;
        $classAttributesByID = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( $classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $ca )
            $classAttributesByID[$ca->attribute( 'identifier' )] = $ca;
        $createCells = array();
        foreach ( $columns as $column )
        {
            $id = $column['id'];
            $value = '';
            switch ( $id )
            {
                case 'ezcontentobject.remote_id':        $value = $newRemoteID; break;
                case 'ezcontentobject.class_identifier':  $value = $class->attribute( 'identifier' ); break;
                case 'ezcontentobject.language':
                case 'ezcontentobject.initial_language':  $value = $language; break;
                case 'ezcontentobject.always_available':  $value = '1'; break;
                case 'node.parent_remote_id':
                    // node.parent_remote_id is the parent NODE's own remote id (what
                    // eZContentObjectTreeNode::fetchByRemoteID() looks up), not its object's
                    $value = $parentNode instanceof eZContentObjectTreeNode ? $parentNode->attribute( 'remote_id' ) : '';
                    break;
            }
            if ( $value === '' && strpos( $id, ':' ) === false && strpos( $id, '.' ) === false && isset( $info['attributes'][$id] ) )
            {
                if ( $id === $titleIdentifier )
                    $value = 'Sample ' . $class->attribute( 'name' );
                elseif ( isset( $classAttributesByID[$id] ) && (bool)$classAttributesByID[$id]->attribute( 'is_required' )
                        && self::columnIsImportable( $info['attributes'][$id]['datatype'], null ) )
                    $value = self::placeholderValue( $info['attributes'][$id]['datatype'], $classAttributesByID[$id] );
            }
            $createCells[] = $parser->escape( $value );
        }
        $rows[] = array( 'cells' => $createCells, 'kind' => 'create' );

        // An error row (only when there is a date attribute to break): the same shape, a fresh remote id,
        // an unparsable date - the dry run then shows exactly how an error looks
        $errorColumnIndex = null;
        foreach ( $columns as $ci => $column )
        {
            if ( isset( $info['attributes'][$column['id']] ) && in_array( $info['attributes'][$column['id']]['datatype'], array( 'ezdate', 'ezdatetime' ), true ) )
            {
                $errorColumnIndex = $ci;
                break;
            }
        }
        if ( $errorColumnIndex !== null )
        {
            $errorCells = $createCells;
            foreach ( $columns as $ci => $column )
            {
                if ( $column['id'] === 'ezcontentobject.remote_id' )
                    $errorCells[$ci] = $parser->escape( $newRemoteID . '-error' );
            }
            $errorCells[$errorColumnIndex] = $parser->escape( 'not-a-date' );
            $rows[] = array( 'cells' => $errorCells, 'kind' => 'error' );
        }

        $text = $writer->begin();
        foreach ( $rows as $row )
            $text .= $writer->row( $row['cells'] );
        $text .= $writer->end();

        $kinds = array();
        foreach ( $rows as $row )
            $kinds[] = $row['kind'];

        return array(
            'ok' => true,
            'text' => $text,
            'filename' => XrowExtractColumns::fileName( $class->attribute( 'identifier' ), '_sample.' . $writer->extension(), 'sample' ),
            'format' => $format,
            'classIdentifier' => $class->attribute( 'identifier' ),
            'kinds' => $kinds,
        );
    }

    /**
     * A small file of real rows for the file format reference (no synthetic rows - genuine site content,
     * exactly as a real export would write it). Null when the class has no readable published objects.
     */
    public static function referenceExampleRows( $classID, $format, $limit = 2 )
    {
        $classID = (int)$classID;
        $class = eZContentClass::fetch( $classID );
        if ( !$class instanceof eZContentClass )
            return null;
        $columns = self::migrationColumns( $classID );
        if ( !$columns )
            return null;
        $objects = self::realObjects( $classID, $limit );
        if ( !$objects )
            return array( 'ok' => true, 'text' => '', 'filename' => '', 'format' => $format, 'columns' => $columns, 'rowCount' => 0 );
        $extras = XrowExtractColumns::extraAttributes( false );
        $allowHash = XrowExtractColumns::allowPasswordHash();
        $writer = new XrowExtractWriter( $format, $columns, ',', true, "\n", array( 'class' => $class->attribute( 'identifier' ), 'created' => date( 'c' ) ) );
        $parser = $writer->parser();
        $text = $writer->begin();
        XrowExtractColumns::$language = null;
        foreach ( $objects as $object )
        {
            XrowExtractColumns::$language = $object->attribute( 'initial_language_code' );
            $text .= $writer->row( XrowExtractColumns::rowCells( $columns, $object, $parser, $extras, $allowHash ) );
        }
        XrowExtractColumns::$language = null;
        $text .= $writer->end();
        return array(
            'ok' => true, 'text' => $text, 'format' => $format, 'columns' => $columns, 'rowCount' => count( $objects ),
            'filename' => XrowExtractColumns::fileName( $class->attribute( 'identifier' ), '_example.' . $writer->extension(), 'example' ),
        );
    }

    /**
     * For a class, one real example value per datatype it actually uses (its first object, first
     * matching attribute) - the file format reference shows these instead of made-up values wherever it
     * can. datatype => array('identifier' => the attribute, 'value' => the real cell text, or null when
     * the class has the datatype but the value is empty).
     */
    public static function datatypeExamples( $classID )
    {
        $classID = (int)$classID;
        $info = self::classInfo( $classID );
        $examples = array();
        if ( !$info )
            return $examples;
        $columns = self::migrationColumns( $classID );
        $objects = self::realObjects( $classID, 1 );
        $cellsByColumnID = array();
        if ( $objects && $columns )
        {
            $extras = XrowExtractColumns::extraAttributes( false );
            $parser = new ParserInterface( ',', true, true );
            $object = $objects[0];
            XrowExtractColumns::$language = $object->attribute( 'initial_language_code' );
            $cells = XrowExtractColumns::rowCells( $columns, $object, $parser, $extras, XrowExtractColumns::allowPasswordHash() );
            XrowExtractColumns::$language = null;
            foreach ( $columns as $i => $column )
                $cellsByColumnID[$column['id']] = isset( $cells[$i] ) ? $cells[$i] : '';
        }
        foreach ( $info['attributes'] as $identifier => $attribute )
        {
            $datatype = $attribute['datatype'];
            if ( isset( $examples[$datatype] ) )
                continue;
            $value = isset( $cellsByColumnID[$identifier] ) ? trim( (string)$cellsByColumnID[$identifier] ) : '';
            $examples[$datatype] = array( 'identifier' => $identifier, 'value' => $value !== '' ? $value : null );
        }
        return $examples;
    }

    /** Class info cached per run: identifier and attribute meta by identifier (datatype, class attribute id, selection options). */
    public static function classInfo( $classID )
    {
        $class = eZContentClass::fetch( $classID );
        if ( !$class instanceof eZContentClass )
            return null;
        $attributes = array();
        foreach ( eZContentClassAttribute::fetchListByClassID( $classID, eZContentClass::VERSION_STATUS_DEFINED, true ) as $classAttribute )
        {
            $identifier = $classAttribute->attribute( 'identifier' );
            $datatype = $classAttribute->attribute( 'data_type_string' );
            $options = array();
            if ( $datatype === 'ezselection' )
            {
                $content = $classAttribute->content();
                $options = isset( $content['options'] ) ? $content['options'] : array();
            }
            $attributes[$identifier] = array( 'datatype' => $datatype, 'options' => $options );
        }
        return array( 'identifier' => $class->attribute( 'identifier' ), 'name' => $class->attribute( 'name' ), 'attributes' => $attributes );
    }

    public static function columnIsImportable( $datatype, $format )
    {
        if ( $format === null )
            return in_array( $datatype, self::baseImportableDatatypes(), true );
        return in_array( $format, self::importableFormats( $datatype ), true );
    }

    /** Dispatches one attribute value to its converter; returns array(ok, value, warning). */
    protected static function convertOne( array $attrInfo, $raw, $format, $language, $importDir, &$usedEzoe )
    {
        $datatype = $attrInfo['datatype'];
        switch ( $datatype )
        {
            case 'ezstring':
            case 'eztext':
            case 'ezemail':
            case 'ezidentifier':
            case 'ezurl':
            case 'ezkeyword':
            case 'ezinteger':
            case 'ezfloat':
                return array( true, (string)$raw, null );
            case 'ezboolean':
                return array( true, self::normalizeBoolean( $raw ), null );
            case 'ezdate':
                return self::convertDate( $raw, false );
            case 'ezdatetime':
                return self::convertDate( $raw, true );
            case 'ezselection':
                return self::convertSelection( $attrInfo['options'], $raw );
            case 'eztags':
                return self::convertTags( $raw, $language );
            case 'ezxmltext':
                return self::convertXmlText( $raw, $usedEzoe );
            case 'ezimage':
            case 'ezbinaryfile':
            case 'ezmedia':
                return self::convertFile( $raw, $datatype, $importDir );
            case 'ezobjectrelation':
                return self::convertRelation( $raw, $format, false );
            case 'ezobjectrelationlist':
                return self::convertRelation( $raw, $format, true );
            case 'xrowmetadata':
                return self::convertMetadata( $raw, $format );
        }
        return array( false, null, self::unsupportedReason( $datatype ) );
    }
}

?>
