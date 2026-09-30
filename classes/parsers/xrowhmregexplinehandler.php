<?php

class XrowhmregexplineHandler extends XrowBaseHandler
{
        /** @param eZContentObjectAttribute $attribute */
        public function exportAttribute( &$attribute ): string
        {
            return $this->escape( self::utf8( $attribute->content() ) );
        }
}

?>
