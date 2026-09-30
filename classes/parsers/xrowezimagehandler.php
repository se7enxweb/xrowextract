<?php
class XroweZImageExportHandler extends XrowBaseHandler
{
       /** @param eZContentObjectAttribute $attribute */
       function exportAttribute( &$attribute ): string
       {
            $imageHandler = $attribute->content();
            $imageAlias = ( $imageHandler instanceof eZImageAliasHandler && $attribute->hasContent() ) ? $imageHandler->imageAlias( 'original' ) : false;
            return $this->escape( is_array( $imageAlias ) && isset( $imageAlias['url'] ) ? $imageAlias['url'] : '' );
       }
}
?>
