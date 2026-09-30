<?php

class XroweZObjectRelationListHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $content = $attribute->content();
        $names = array();
        foreach ( isset( $content['relation_list'] ) ? $content['relation_list'] : array() as $item )
        {
            $object = eZContentObject::fetch( (int)$item['contentobject_id'] );
            if ( is_object( $object ) )
            {
                $names[] = $object->name();
            }
        }
        return $this->escape( self::utf8( join( ", ", $names ) ) );
    }
}
?>
