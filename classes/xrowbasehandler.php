<?php

class XrowBaseHandler
{
    /**
     * One attribute as one CSV cell; the datatype handlers override it. No native types: handlers in
     * other extensions (e.g. birthday) extend this class with untyped signatures.
     *
     * @param eZContentObjectAttribute $attribute
     * @return string|null
     */
    function exportAttribute( &$attribute )
    {
        return null;
    }

    /**
     * A value as one CSV cell.
     *
     * With escaping on, the cell is enclosed in quotes and quotes in it are
     * doubled; that is what the old checks (strpos() >= 0, true for every
     * value) did for every cell. With escaping off, line breaks are taken out
     * so the value stays one cell. When $neutralizeFormulas is on, a cell a
     * spreadsheet would treat as a formula gets a leading apostrophe, unless
     * it is a plain number.
     *
     * @param mixed $stringtoescape
     * @return string
     */
    public function escape( $stringtoescape )
    {
        $stringtoescape = (string)$stringtoescape;
        // Raw: the value as it is, for writers that do their own escaping (JSON, XML)
        if ( $this->raw )
        {
            return $stringtoescape;
        }
        if ( $this->neutralizeFormulas && self::looksLikeFormula( $stringtoescape ) )
        {
            $stringtoescape = "'" . $stringtoescape;
        }
        if ( $this->escape )
        {
            return $this->encloseChar . str_replace( $this->encloseChar, $this->encloseChar . $this->encloseChar, $stringtoescape ) . $this->encloseChar;
        }
        return str_replace( array( chr( 13 ), chr( 10 ) ), '', $stringtoescape );
    }

    /**
     * Text as UTF-8. mb_detect_encoding() returns false for input it cannot
     * place, which mb_convert_encoding() refuses on PHP 8; such text is kept.
     *
     * @param mixed $text
     * @return string
     */
    public static function utf8( $text )
    {
        $text = (string)$text;
        if ( $text === '' || mb_check_encoding( $text, 'UTF-8' ) )
        {
            return $text;
        }
        $from = mb_detect_encoding( $text, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true );
        return $from ? mb_convert_encoding( $text, 'UTF-8', $from ) : $text;
    }

    /**
     * Starts with a character spreadsheets read as the start of a formula, and is not a number.
     *
     * @param string $value
     * @return bool
     */
    public static function looksLikeFormula( $value )
    {
        return $value !== '' && strpos( "=+-@\t\r", $value[0] ) !== false && !is_numeric( $value );
    }

    /** @var string */
    public $encloseChar  = '"';
    /** @var string */
    public $separationChar = ",";
    /** @var bool */
    public $escape = false;
    /** @var bool */
    public $neutralizeFormulas = true;
    /** @var bool */
    public $raw = false;
}
?>
