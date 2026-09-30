<?php

class XroweZPriceHandler extends XrowBaseHandler
{
    /** @param eZContentObjectAttribute $attribute */
    public function exportAttribute( &$attribute ): string
    {
        $locale = eZLocale::instance();
        $price = $attribute->content();
        $content = $price instanceof eZPrice ? $locale->formatCleanCurrency( $price->attribute( 'inc_vat_price' ) ) : '';
        return $this->escape( $content );
    }
}