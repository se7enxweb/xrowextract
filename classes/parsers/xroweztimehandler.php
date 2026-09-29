<?php
class XroweZTimeHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $content = $attribute->content();
        return $this->escape( is_object( $content ) && $attribute->hasContent() ? $content->attribute( 'time_of_day' ) : '' );
    }
}
?>
