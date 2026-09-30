<?php

class XroweZIdentifierHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    function exportAttribute( &$attribute ): string
    {
        return $this->escape( $attribute->content() );
    }
}

?>