<?php

/**
 * Writes export rows as CSV, JSON or XML. The rows come from XrowExtractColumns::rowCells() with the
 * parser this writer hands out: for CSV the cells are escaped by the handlers as always (quoting, the
 * formula guard); for JSON and XML the parser is raw and the writer escapes.
 *
 *   $writer = new XrowExtractWriter( 'json', $columns, ',', true, "\n", array( 'class' => 'ng_article' ) );
 *   $parser = $writer->parser();
 *   $out = $writer->begin(); foreach ( ... ) $out .= $writer->row( XrowExtractColumns::rowCells( $columns, $obj, $parser, ... ) );
 *   $out .= $writer->end();
 */
class XrowExtractWriter
{
    /** @var string csv, json, xml or ezpkg */
    protected $format;
    /** @var list<array<string, mixed>> */
    protected $columns;
    /** @var list<string> the column names in the file */
    protected $keys;
    /** @var string */
    protected $separator;
    /** @var bool */
    protected $escape;
    /** @var string */
    protected $newLine;
    /** @var array<string, mixed> */
    protected $meta;
    protected int $rows = 0;
    /** @var ParserInterface */
    protected $parser;
    /**
     * The typed column manifest to embed (XML: a <manifest> element; JSON: an envelope), or null.
     *
     * @var array<string, mixed>|null
     */
    protected $manifest = null;

    /**
     * The output formats: id => (name, extension, content type). 'ezpkg' is not a row format this class
     * itself ever writes (a package has no columns) - it is handled as an early special case, before a
     * writer is ever constructed, in both bin/php/csv.php and modules/xrowextract/csv.php (see
     * XrowExtractPackage::exportNodeIDsIntoPackage()). It is listed here only so it validates and shows
     * up in the File format choice exactly like the row formats do, from the same one list.
     *
     * @return array<string, array{id: string, name: string, extension: string, type: string, is_package?: bool}>
     */
    public static function formats(): array
    {
        return array(
            'csv'   => array( 'id' => 'csv',   'name' => 'CSV',   'extension' => 'csv',   'type' => 'text/csv' ),
            'json'  => array( 'id' => 'json',  'name' => 'JSON',  'extension' => 'json',  'type' => 'application/json' ),
            'xml'   => array( 'id' => 'xml',   'name' => 'XML',   'extension' => 'xml',   'type' => 'application/xml' ),
            'ezpkg' => array( 'id' => 'ezpkg', 'name' => 'Content package (.ezpkg)', 'extension' => 'ezpkg', 'type' => 'application/gzip', 'is_package' => true ),
        );
    }

    /** @param mixed $format */
    public static function isFormat( $format ): bool
    {
        return is_string( $format ) && array_key_exists( $format, self::formats() );
    }

    /**
     * The formats written a row at a time (everything but the content package): what the files inside a site
     * archive can be. A package of the archive's nodes is its own action ("Export as package").
     *
     * @return array<string, array{id: string, name: string, extension: string, type: string, is_package?: bool}>
     */
    public static function rowFormats(): array
    {
        return array_filter( self::formats(), function ( $format ) { return empty( $format['is_package'] ); } );
    }

    /** @param mixed $format */
    public static function isRowFormat( $format ): bool
    {
        return is_string( $format ) && array_key_exists( $format, self::rowFormats() );
    }

    /**
     * @param mixed $format one of formats() (else csv)
     * @param array<array<string, mixed>> $columns
     * @param string $separator
     * @param bool $escape
     * @param string $newLine
     * @param array<string, mixed> $meta attributes of the XML root; 'manifest': the manifest to embed
     */
    public function __construct( $format, array $columns, $separator = ',', $escape = true, $newLine = "\n", array $meta = array() )
    {
        $this->format = self::isFormat( $format ) ? $format : 'csv';
        $this->columns = array_values( $columns );
        $this->separator = $separator;
        $this->escape = $escape;
        $this->newLine = $newLine;
        if ( isset( $meta['manifest'] ) && is_array( $meta['manifest'] ) )
        {
            $embed = ( $this->format === 'json' && XrowExtractManifest::embedInJSON() )
                  || ( $this->format === 'xml' && XrowExtractManifest::embedInXML() );
            $this->manifest = $embed ? XrowExtractManifest::headerCopy( $meta['manifest'] ) : null;
        }
        unset( $meta['manifest'] );
        $this->meta = $meta;
        // The names in the file, as in the CSV header; a name used twice gets a number
        $this->keys = array();
        $used = array();
        foreach ( $this->columns as $column )
        {
            $key = str_replace( '_', '-', $column['exportname'] );
            if ( isset( $used[$key] ) )
                $key .= '-' . ( ++$used[$key] );
            else
                $used[$key] = 1;
            $this->keys[] = $key;
        }
        $this->parser = new ParserInterface( $separator, $escape, $this->format !== 'csv' );
    }

    /** How many rows have been written so far. */
    public function rowCount(): int
    {
        return $this->rows;
    }

    /** Whether a manifest is embedded in this file. */
    public function embedsManifest(): bool
    {
        return $this->manifest !== null;
    }

    /** The parser to build rows with (raw for JSON and XML). */
    public function parser(): ParserInterface
    {
        return $this->parser;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function extension(): string
    {
        $formats = self::formats();
        return $formats[$this->format]['extension'];
    }

    /** @param string $charset */
    public function contentType( $charset = 'utf-8' ): string
    {
        $formats = self::formats();
        return $formats[$this->format]['type'] . '; charset=' . $charset;
    }

    /** The start of the file: the CSV header, the JSON array, the XML root with a column list. */
    public function begin(): string
    {
        switch ( $this->format )
        {
            case 'json':
                if ( $this->manifest !== null )
                    return "{\n\"manifest\": " . json_encode( $this->manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE )
                         . ",\n\"rows\": [\n";
                return "[\n";
            case 'xml':
                $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<export';
                foreach ( $this->meta as $name => $value )
                {
                    if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) && is_scalar( $value ) )
                        $out .= ' ' . $name . '="' . self::xml( (string)$value ) . '"';
                }
                $out .= ">\n  <columns>\n";
                $typed = array();
                if ( $this->manifest !== null )
                {
                    foreach ( $this->manifest['columns'] as $entry )
                        $typed[$entry['key']] = $entry;
                }
                foreach ( $this->columns as $i => $column )
                {
                    $extra = '';
                    if ( isset( $typed[$this->keys[$i]] ) )
                    {
                        $entry = $typed[$this->keys[$i]];
                        foreach ( array( 'kind', 'datatype', 'format' ) as $name )
                        {
                            if ( isset( $entry[$name] ) && is_scalar( $entry[$name] ) && $entry[$name] !== '' )
                                $extra .= ' ' . $name . '="' . self::xml( (string)$entry[$name] ) . '"';
                        }
                        if ( isset( $entry['language'] ) && is_string( $entry['language'] ) )
                            $extra .= ' language="' . self::xml( $entry['language'] ) . '"';
                        elseif ( isset( $entry['language']['per_row'] ) )
                            $extra .= ' language="per-row"';
                    }
                    $out .= '    <column name="' . self::xml( $this->keys[$i] ) . '" id="' . self::xml( $column['id'] ) . '"' . $extra . '>' . self::xml( $column['name'] ) . "</column>\n";
                }
                $out .= "  </columns>\n";
                if ( $this->manifest !== null )
                    $out .= '  <manifest type="application/json">' . self::xml( json_encode( $this->manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) ) . "</manifest>\n";
                return $out;
        }
        $cells = array();
        foreach ( $this->keys as $key )
            $cells[] = $this->parser->escape( $key );
        return implode( $this->separator, $cells ) . $this->newLine;
    }

    /**
     * One row from the cells rowCells() built with parser().
     *
     * @param array<int, string> $cells
     */
    public function row( array $cells ): string
    {
        $this->rows++;
        switch ( $this->format )
        {
            case 'json':
                $object = array();
                foreach ( $this->keys as $i => $key )
                    $object[$key] = isset( $cells[$i] ) ? (string)$cells[$i] : '';
                return ( $this->rows > 1 ? ",\n" : '' ) . '  ' . json_encode( $object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
            case 'xml':
                $out = "  <object>\n";
                foreach ( $this->keys as $i => $key )
                    $out .= '    <field name="' . self::xml( $key ) . '">' . self::xml( isset( $cells[$i] ) ? (string)$cells[$i] : '' ) . "</field>\n";
                return $out . "  </object>\n";
        }
        return implode( $this->separator, $cells ) . $this->newLine;
    }

    /** The end of the file. */
    public function end(): string
    {
        switch ( $this->format )
        {
            case 'json':
                if ( $this->manifest !== null )
                    return ( $this->rows ? "\n" : '' ) . "],\n\"summary\": " . json_encode( array( 'rows' => $this->rows ) ) . "\n}\n";
                return ( $this->rows ? "\n" : '' ) . "]\n";
            case 'xml':
                if ( $this->manifest !== null )
                    return '  <summary rows="' . (int)$this->rows . "\" />\n</export>\n";
                return "</export>\n";
        }
        return '';
    }

    /**
     * Text for XML: escaped, and without characters XML 1.0 does not allow.
     *
     * @param mixed $text
     */
    protected static function xml( $text ): string
    {
        $text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$text );
        return htmlspecialchars( $text === null ? '' : $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}

?>
