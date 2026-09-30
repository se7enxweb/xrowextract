<?php
/**
 * xrowmetadata (SEO and sitemap meta data): the title by default. Every field (keywords, description,
 * canonical URL, sitemap priority and change frequency, Open Graph image ...) is also an attribute format
 * of the column list (identifier:format), see XrowExtractColumns::formatValue().
 */
class XrowxrowmetadataHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $meta = $attribute->content();
        return $this->escape( is_object( $meta ) && isset( $meta->title ) ? self::utf8( $meta->title ) : '' );
    }
}
?>
