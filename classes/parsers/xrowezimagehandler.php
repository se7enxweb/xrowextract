<?php
class XroweZImageExportHandler extends XrowBaseHandler
{
       function exportAttribute( &$attribute )
       {
            $imageHandler = $attribute->content();
            $imageAlias = ( is_object( $imageHandler ) && $attribute->hasContent() ) ? $imageHandler->imageAlias( 'original' ) : false;
            return $this->escape( is_array( $imageAlias ) && isset( $imageAlias['url'] ) ? $imageAlias['url'] : '' );
       }
}
?>
