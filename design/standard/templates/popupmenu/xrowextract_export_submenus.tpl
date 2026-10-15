{* The "Subtree of class" submenu of an export menu (admininterface.ini [AdditionalMenuSettings]
   ExportSubMenuTemplateArray), with $menu_id, $item_prefix and $sub_menu_depth of the menu it belongs to; its id is
   <menu id>Classes. The classes below the item are read when it opens from xrowextract/subtree_classes/<node> by
   javascript/xrowextract-exportmenu.js (BackendJavaScriptList), each opening xrowextract/csv filled in for the item's
   subtree and that class. For a user with the policy xrowextract/csv. *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'csv' ) )}
<script type="text/javascript">
menuArray['{$menu_id}Classes'] = {ldelim} 'depth': {$sub_menu_depth}, 'elements': {ldelim}{rdelim},
    'onshow': function( menu, values, refresh ) {ldelim} if ( window.xrowExtractExportMenu ) window.xrowExtractExportMenu.show( menu, values, refresh ); {rdelim} {rdelim};
</script>
<div class="popupmenu xrowextract-class-menu" id="{$menu_id}Classes"
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
