{* The CSV entries of the export menus (admininterface.ini [AdditionalMenuSettings] ExportMenuTemplateArray), included
   by Exponential's popupmenu/popup_export_items.tpl in the context menus' Advanced > Export and in the node view's
   Import/Export menu, with $menu_id (the menu), $item_prefix (of the item ids) and $sub_menu_depth. For a user with
   the policy xrowextract/csv. Each opens xrowextract/csv filled in for the item (the entry parameters (node),
   (scope), (columns), XrowExtractEntry); the export is started there. "Subtree of class" opens the list of the
   classes below the item (xrowextract_export_submenus.tpl). *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'csv' ) )}
<script type="text/javascript">
menuArray['{$menu_id}']['elements']['{$item_prefix}-csv-children'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/list"|ezurl} {rdelim};
menuArray['{$menu_id}']['elements']['{$item_prefix}-csv-subtree'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree"|ezurl} {rdelim};
menuArray['{$menu_id}']['elements']['{$item_prefix}-csv-all'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/all"|ezurl} {rdelim};
menuArray['{$menu_id}']['elements']['{$item_prefix}-csv-migration'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/migration"|ezurl} {rdelim};
</script>
    <a id="{$item_prefix}-csv-children" href="#" onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Children as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-subtree" href="#" onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-class" class="more" href="#" onmouseover="ezpopmenu_showSubLevel( event, '{$menu_id}Classes', '{$item_prefix}-csv-class' ); return false;"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree of class'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-all" href="#" onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, every attribute…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-migration" href="#" onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, migration columns…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
