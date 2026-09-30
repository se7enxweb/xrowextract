<?php
class XroweZKeywordHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $content = $attribute->content();
        return $this->escape( $content instanceof eZKeyword ? self::utf8( $content->keywordString() ) : '' );
    }
}
?>
