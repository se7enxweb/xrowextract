<?php

class XroweZStringHandler extends XrowBaseHandler
{
        public function exportAttribute( &$attribute )
        {
            return $this->escape( self::utf8( $attribute->content() ) );
        }
}

?>
