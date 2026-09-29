<?php
class XroweZKeywordHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $content = $attribute->content();
        return $this->escape( is_object( $content ) ? self::utf8( $content->keywordString( ', ' ) ) : '' );
    }
}
?>
