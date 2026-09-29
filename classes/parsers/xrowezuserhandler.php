<?php

class XroweZUserHandler extends XrowBaseHandler
{
	public function exportAttribute( &$attribute )
	{
		$content = $attribute->content();
		return $this->escape( is_object( $content ) ? $content->attribute( 'login' ) : '' );
	}
}
?>
