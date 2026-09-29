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
    protected $format;
    protected $columns;
    protected $keys;
    protected $separator;
    protected $escape;
    protected $newLine;
    protected $meta;
    protected $rows = 0;
    protected $parser;

    /** The output formats: id => (name, extension, content type). */
    public static function formats()
    {
        return array(
            'csv'  => array( 'id' => 'csv',  'name' => 'CSV',  'extension' => 'csv',  'type' => 'text/csv' ),
            'json' => array( 'id' => 'json', 'name' => 'JSON', 'extension' => 'json', 'type' => 'application/json' ),
            'xml'  => array( 'id' => 'xml',  'name' => 'XML',  'extension' => 'xml',  'type' => 'application/xml' ),
        );
    }

    public static function isFormat( $format )
    {
        return is_string( $format ) && array_key_exists( $format, self::formats() );
    }

    public function __construct( $format, array $columns, $separator = ',', $escape = true, $newLine = "\n", array $meta = array() )
    {
        $this->format = self::isFormat( $format ) ? $format : 'csv';
        $this->columns = array_values( $columns );
        $this->separator = $separator;
        $this->escape = $escape;
        $this->newLine = $newLine;
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

    /** The parser to build rows with (raw for JSON and XML). */
    public function parser()
    {
        return $this->parser;
    }

    public function format()
    {
        return $this->format;
    }

    public function extension()
    {
        $formats = self::formats();
        return $formats[$this->format]['extension'];
    }

    public function contentType( $charset = 'utf-8' )
    {
        $formats = self::formats();
        return $formats[$this->format]['type'] . '; charset=' . $charset;
    }

    /** The start of the file: the CSV header, the JSON array, the XML root with a column list. */
    public function begin()
    {
        switch ( $this->format )
        {
            case 'json':
                return "[\n";
            case 'xml':
                $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<export';
                foreach ( $this->meta as $name => $value )
                {
                    if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $name ) && is_scalar( $value ) )
                        $out .= ' ' . $name . '="' . self::xml( (string)$value ) . '"';
                }
                $out .= ">\n  <columns>\n";
                foreach ( $this->columns as $i => $column )
                    $out .= '    <column name="' . self::xml( $this->keys[$i] ) . '" id="' . self::xml( $column['id'] ) . '">' . self::xml( $column['name'] ) . "</column>\n";
                return $out . "  </columns>\n";
        }
        $cells = array();
        foreach ( $this->keys as $key )
            $cells[] = $this->parser->escape( $key );
        return implode( $this->separator, $cells ) . $this->newLine;
    }

    /** One row from the cells rowCells() built with parser(). */
    public function row( array $cells )
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
    public function end()
    {
        switch ( $this->format )
        {
            case 'json': return ( $this->rows ? "\n" : '' ) . "]\n";
            case 'xml':  return "</export>\n";
        }
        return '';
    }

    /** Text for XML: escaped, and without characters XML 1.0 does not allow. */
    protected static function xml( $text )
    {
        $text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$text );
        return htmlspecialchars( $text === null ? '' : $text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }
}

?>
