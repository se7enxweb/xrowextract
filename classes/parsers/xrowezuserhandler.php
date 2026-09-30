<?php

class XroweZUserHandler extends XrowBaseHandler
{
	/** @param eZContentObjectAttribute $attribute */
	public function exportAttribute( &$attribute ): string
	{
		$content = $attribute->content();
		return $this->escape( $content instanceof eZUser ? $content->attribute( 'login' ) : '' );
	}
}
?>
