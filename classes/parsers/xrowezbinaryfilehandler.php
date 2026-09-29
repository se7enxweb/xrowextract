<?php
class XroweZBinaryfileExportHandler extends XrowBaseHandler
{
       function exportAttribute( &$attribute )
       {
            $content = $attribute->content();
            return $this->escape( is_object( $content ) ? $content->filePath() : '' );
       }
}
?>
