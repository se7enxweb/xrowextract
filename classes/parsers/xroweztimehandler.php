<?php
class XroweZTimeHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $content = $attribute->content();
        if ( !is_object( $content ) || !$attribute->hasContent() )
            return $this->escape( '' );
        return $this->escape( sprintf( '%02d:%02d', $content->attribute( 'hour' ), $content->attribute( 'minute' ) ) );
    }
}
?>
