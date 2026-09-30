<?php
class XroweZSelectionHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    function exportAttribute( &$attribute ): string
    {
        $content = $attribute->content();
        $content = ( is_array( $content ) and count( $content ) == 1 ) ? $attribute->metaData() : $attribute->isA();
        return $this->escape( $content );
    }
}
?>