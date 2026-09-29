<?php

class XroweZPriceHandler extends XrowBaseHandler
{
    public function exportAttribute( &$attribute )
    {
        $locale = eZLocale::instance();
        $price = $attribute->content();
        $content = is_object( $price ) ? $locale->formatCleanCurrency( $price->attribute( 'inc_vat_price' ) ) : '';
        return $this->escape( $content );
    }
}