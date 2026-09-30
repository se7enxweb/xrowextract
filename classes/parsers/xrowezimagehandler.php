<?php
class XroweZImageExportHandler extends XrowBaseHandler
{
       /** @param eZContentObjectAttribute $attribute */
       function exportAttribute( &$attribute ): string
       {
            $imageHandler = $attribute->content();
            $imageAlias = ( is_object( $imageHandler ) && $attribute->hasContent() ) ? $imageHandler->imageAlias( 'original' ) : false;
            return $this->escape( is_array( $imageAlias ) && isset( $imageAlias['url'] ) ? $imageAlias['url'] : '' );
       }
}
?>
