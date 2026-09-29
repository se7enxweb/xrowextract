<?php

class XroweZMatrixExportHandler extends XrowBaseHandler
{
    function exportAttribute(&$attribute)
    {
        $content = $attribute->content();
        $rows = is_object( $content ) ? $content->attribute( 'rows' ) : array();
        $matrixArray = array();
        foreach ( isset( $rows['sequential'] ) ? $rows['sequential'] : array() as $row )
        {
            $matrixArray[] = eZStringUtils::implodeStr( $row['columns'], '|' );
        }
        return $this->escape( eZStringUtils::implodeStr( $matrixArray, '&' ) );
    }
}
?>
