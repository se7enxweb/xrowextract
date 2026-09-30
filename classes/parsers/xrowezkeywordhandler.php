<?php
class XroweZKeywordHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $content = $attribute->content();
        return $this->escape( is_object( $content ) ? self::utf8( $content->keywordString( ', ' ) ) : '' );
    }
}
?>
