<?php


/*
    return the datatypes (identifiers) that can be exported
*/

class ParserInterface
{
    //holds the lookup map for the handler mapings
    public $handlerMap=array();
    public $exportableDatatypes;
    public $separationChar = ",";
    public $escape = true;
    public $neutralizeFormulas = true;
    public $raw = false;

    public function __construct( $separationChar = null, $escape = null, $raw = false )
    {
        $this->raw = (bool)$raw;
        if ( $escape === true or $escape === false )
           $this->escape = $escape;
        if ( $separationChar !== null )
           $this->separationChar = $separationChar;
        $ini = eZINI::instance( "csv.ini" );
        $this->exportableDatatypes = $ini->variable( "General", "ExportableDatatypes" );
        $this->neutralizeFormulas = !( $ini->hasVariable( 'General', 'NeutralizeFormulas' )
                                       && $ini->variable( 'General', 'NeutralizeFormulas' ) === 'disabled' );
        foreach ($this->exportableDatatypes as $typename)
        {
            if ( file_exists( $ini->variable( $typename, 'HandlerFile' ) ) )
            {
                 //#include_once("extension/extract/classes/parsers/".$ini->variable( $typename, 'HandlerFile' ) );
                 $classname = $ini->variable( $typename, 'HandlerClass' );
                 $handler = new $classname();
                 if( isset( $handler->separationChar ) )
                 {
                     $handler->separationChar = $this->separationChar;
                 }
                 if( isset( $handler->escape ) )
                 {
                     $handler->escape = $this->escape;
                 }
                 if( property_exists( $handler, 'neutralizeFormulas' ) )
                 {
                     $handler->neutralizeFormulas = $this->neutralizeFormulas;
                 }
                 if( property_exists( $handler, 'raw' ) )
                 {
                     $handler->raw = $this->raw;
                 }
                 $this->handlerMap[$typename] = array( "handler" => $handler,
                                                       "exportable" => true );
            }
            else
                eZDebug::writeError( "Error loading " . $ini->variable($typename, 'HandlerFile'), "Extract" );
        }
    }

    public function getExportableDatatypes()
    {
        return $this->exportableDatatypes;
    }

    /**
     * One attribute as one CSV cell (escaped by its handler), without a separator.
     * A datatype with no handler gives an empty cell, so every row keeps the
     * header's columns.
     */
    public function exportValue( $attribute )
    {
        $handler = isset( $this->handlerMap[$attribute->DataTypeString]['handler'] )
                 ? $this->handlerMap[$attribute->DataTypeString]['handler'] : null;
        if ( is_object( $handler ) )
        {
            return (string)$handler->exportAttribute( $attribute );
        }
        return $this->escape( '' );
    }

    /** Kept for callers of the old API: the cell followed by the separator. */
    public function exportAttribute( &$attribute )
    {
        return $this->exportValue( $attribute ) . $this->separationChar;
    }

    public function escape( $text )
    {
        $handler = new XrowBaseHandler();
        $handler->separationChar = $this->separationChar;
        $handler->escape = $this->escape;
        $handler->neutralizeFormulas = $this->neutralizeFormulas;
        $handler->raw = $this->raw;
        return $handler->escape( $text );
    }
}
