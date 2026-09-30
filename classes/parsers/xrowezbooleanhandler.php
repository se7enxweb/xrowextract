<?php
class XroweZBooleanHandler extends XrowBaseHandler
{
    public $encloseChar = "'";
    public $separationChar = ',';

    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        if ( $attribute->content() )
            return $this->escape( '1' );
        else
            return $this->escape( '0' );
    }
}
?>
