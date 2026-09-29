<?php
class XroweZDateHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $timestamp = (int)$attribute->metaData();
        return $this->escape( $timestamp > 0 ? date( 'Y-m-d', $timestamp ) : '' );
    }
}
?>
