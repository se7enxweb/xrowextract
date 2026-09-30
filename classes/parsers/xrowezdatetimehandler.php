<?php
class XroweZDateTimeHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $timestamp = (int)$attribute->metaData();
        return $this->escape( $timestamp > 0 ? date( 'Y-m-d H:i:s', $timestamp ) : '' );
    }
}
?>
