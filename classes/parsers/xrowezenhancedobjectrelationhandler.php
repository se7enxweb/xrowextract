<?php

class XroweZenhancedobjectrelationHandler extends XrowBaseHandler
{
    public function exportAttribute(&$attribute)
    {
        $content = $attribute->content();
        $id_list = isset( $content['id_list'] ) ? (array)$content['id_list'] : array();

        // OutputRelatedObjectNames=false exports the ids; the setting is a string, so "false" must be compared
        $ini = eZINI::instance( "csv.ini" );
        if ( $ini->variable( "ezenhancedobjectrelation", "OutputRelatedObjectNames" ) !== 'false' )
        {
            $names = array();
            foreach ( $id_list as $id )
            {
                $object = eZContentObject::fetch( (int)$id );
                if ( is_object( $object ) )
                    $names[] = $object->name();
            }
            return $this->escape( self::utf8( join( " ", $names ) ) );
        }
        return $this->escape( join( " ", $id_list ) );
    }
}

?>
