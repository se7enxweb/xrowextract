<?php


/*
    return the datatypes (identifiers) that can be exported
*/

class ParserInterface
{
    //holds the lookup map for the handler mapings
    /** @var array<string, array{handler: XrowBaseHandler, exportable: bool}> */
    public $handlerMap=array();
    /** @var list<string> csv.ini [General] ExportableDatatypes[] */
    public $exportableDatatypes;
    /** @var string */
    public $separationChar = ",";
    /** @var bool */
    public $escape = true;
    /** @var bool */
    public $neutralizeFormulas = true;
    /** @var bool */
    public $raw = false;

    /**
     * @param string|null $separationChar null: a comma
     * @param bool|null $escape null: on (quoted cells)
     * @param bool $raw
     */
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
                 // Every handler extends XrowBaseHandler (exportAttribute(), escape()); anything else is refused like a missing file
                 if ( !is_string( $classname ) || !class_exists( $classname ) || !is_subclass_of( $classname, 'XrowBaseHandler' ) )
                 {
                     eZDebug::writeError( "Handler class of $typename is not a XrowBaseHandler: " . ( is_string( $classname ) ? $classname : '' ), "Extract" );
                     continue;
                 }
                 $handler = new $classname();
                 // Every XrowBaseHandler has these settings; the parser's own apply to all of its handlers
                 $handler->separationChar = $this->separationChar;
                 $handler->escape = $this->escape;
                 $handler->neutralizeFormulas = $this->neutralizeFormulas;
                 $handler->raw = $this->raw;
                 $this->handlerMap[$typename] = array( "handler" => $handler,
                                                       "exportable" => true );
            }
            else
                eZDebug::writeError( "Error loading " . $ini->variable($typename, 'HandlerFile'), "Extract" );
        }
    }

    /** @return list<string> */
    public function getExportableDatatypes()
    {
        return $this->exportableDatatypes;
    }

    /**
     * One attribute as one CSV cell (escaped by its handler), without a separator.
     * A datatype with no handler gives an empty cell, so every row keeps the
     * header's columns.
     *
     * @param eZContentObjectAttribute $attribute
     */
    public function exportValue( $attribute ): string
    {
        $handler = isset( $this->handlerMap[$attribute->DataTypeString]['handler'] )
                 ? $this->handlerMap[$attribute->DataTypeString]['handler'] : null;
        if ( is_object( $handler ) )
        {
            return (string)$handler->exportAttribute( $attribute );
        }
        return $this->escape( '' );
    }

    /**
     * Kept for callers of the old API: the cell followed by the separator.
     *
     * @param eZContentObjectAttribute $attribute
     */
    public function exportAttribute( &$attribute ): string
    {
        return $this->exportValue( $attribute ) . $this->separationChar;
    }

    /** @param mixed $text */
    public function escape( $text ): string
    {
        $handler = new XrowBaseHandler();
        $handler->separationChar = $this->separationChar;
        $handler->escape = $this->escape;
        $handler->neutralizeFormulas = $this->neutralizeFormulas;
        $handler->raw = $this->raw;
        return $handler->escape( $text );
    }
}
