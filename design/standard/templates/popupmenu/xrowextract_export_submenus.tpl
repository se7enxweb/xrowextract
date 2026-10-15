{* The "Subtree of class" submenu of the content tree's Advanced > Export menu (admininterface.ini
   [AdditionalMenuSettings] ExportSubMenuTemplateArray): the classes below the item, read when it opens from
   xrowextract/subtree_classes/<node> by javascript/xrowextract-exportmenu.js (BackendJavaScriptList), each opening
   xrowextract/csv filled in for the item's subtree and that class. For a user with the policy xrowextract/csv. *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'csv' ) )}
<script type="text/javascript">
menuArray['ExportClassMenu'] = {ldelim} 'depth': 3, 'elements': {ldelim}{rdelim},
    'onshow': function( menu, values, refresh ) {ldelim} if ( window.xrowExtractExportMenu ) window.xrowExtractExportMenu.show( menu, values, refresh ); {rdelim} {rdelim};
</script>
<div class="popupmenu" id="ExportClassMenu"
     data-classes-url="{'xrowextract/subtree_classes/%nodeID%'|ezurl( 'no' )|wash}"
     data-entry-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/tree/(class)/%classID%'|ezurl( 'no' )|wash}"
     data-more-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/tree'|ezurl( 'no' )|wash}"
     data-loading="{'Loading…'|i18n( 'extension/xrowextract/popupmenu' )|wash}"
     data-empty="{'No classes below this item'|i18n( 'extension/xrowextract/popupmenu' )|wash}"
     data-failed="{'The classes could not be loaded'|i18n( 'extension/xrowextract/popupmenu' )|wash}"
     data-more="{'More classes…'|i18n( 'extension/xrowextract/popupmenu' )|wash}"
     data-count="{'%name (%count)'|i18n( 'extension/xrowextract/popupmenu' )|wash}">
</div>
{/if}
