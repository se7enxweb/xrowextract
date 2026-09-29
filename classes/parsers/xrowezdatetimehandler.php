<?php
class XroweZDateTimeHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $timestamp = (int)$attribute->metaData();
        return $this->escape( $timestamp > 0 ? date( 'Y-m-d H:i:s', $timestamp ) : '' );
    }
}
?>
