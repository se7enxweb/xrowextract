<?php
class XroweZMediaExportHandler extends XrowBaseHandler
{
       function exportAttribute( &$attribute )
       {
            $content = $attribute->content();
            if ( is_object( $content ) )
            {
                $info = $content->storedFileInfo();
                if ( $info['filename'] != '' )
                {
                    return $this->escape( $content->filePath() );
                }
            }
            return $this->escape( '' );
       }
}
?>
