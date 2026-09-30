<?php

class XroweZEmailHandler extends XrowBaseHandler
{
	/** @param eZContentObjectAttribute $attribute */
	public function exportAttribute( &$attribute ): string
	{

		return $this->escape( $attribute->content() );
	}
}

?>