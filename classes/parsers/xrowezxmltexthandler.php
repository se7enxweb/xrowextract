<?php
class XroweZXMLTextHandler extends XrowBaseHandler
{
        /** @param eZContentObjectAttribute $attribute */
        public function exportAttribute( &$attribute ): string
        {
            $content = $attribute->content();
            $contentHTML = $content->outputHandler( $content->XMLData, 'html' )->outputText();
            $whitespaceFreeHTML = preg_replace('~>\s+<~', '><', $contentHTML);
            return $this->escape( preg_replace('~\n+~', '', $whitespaceFreeHTML) );
            // return $content->outputHandler( $content->XMLData, 'html' )->outputText();
            // return $this->escape( $content->XMLData );
        }
}
?>
