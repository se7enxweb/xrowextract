<?php

class XroweZPriceHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $locale = eZLocale::instance();
        $price = $attribute->content();
        $content = is_object( $price ) ? $locale->formatCleanCurrency( $price->attribute( 'inc_vat_price' ) ) : '';
        return $this->escape( $content );
    }
}