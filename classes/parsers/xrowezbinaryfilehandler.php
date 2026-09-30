<?php
class XroweZBinaryfileExportHandler extends XrowBaseHandler
{
       /** @param eZContentObjectAttribute $attribute */
       function exportAttribute( &$attribute ): string
       {
            $content = $attribute->content();
            return $this->escape( is_object( $content ) ? $content->filePath() : '' );
       }
}
?>
