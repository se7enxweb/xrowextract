<?php
class XroweZExampleHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $content = $attribute->content();
        return $this->escape( $content );
    }
}
?>
