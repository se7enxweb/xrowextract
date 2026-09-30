<?php
/* The name of the related object (ezobjectrelation). */
class XroweZObjectRelationHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $object = $attribute->hasContent() ? $attribute->content() : null;
        return $this->escape( $object instanceof eZContentObject ? self::utf8( $object->name() ) : '' );
    }
}
?>
