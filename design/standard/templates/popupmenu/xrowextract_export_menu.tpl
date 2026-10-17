{* The CSV entries of the export menus (admininterface.ini [AdditionalMenuSettings] ExportMenuTemplateArray), included
   by Exponential's popupmenu/popup_export_items.tpl in the context menus' Advanced > Export and in the node view's
   Import/Export menu, with $menu_id (the menu), $item_prefix (of the item ids) and $sub_menu_depth. For a user with
   the policy xrowextract/csv. Each opens xrowextract/csv filled in for the item (the entry parameters (node),
   (scope), (columns), XrowExtractEntry); the export is started there. "Subtree of class" opens the list of the
   classes below the item (xrowextract_export_submenus.tpl).
   No inline code: an entry's URL is in data-menu-url, its menu in data-xrowextract-menu and the submenu it opens in
   data-menu-submenu; javascript/xrowextract-exportmenu.js (BackendJavaScriptList) adds them to menuArray and calls
   ezpopmenu_mouseOver() / ezpopmenu_showSubLevel() when they are pointed at. *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'csv' ) )}
    <a id="{$item_prefix}-csv-children" href="#" data-xrowextract-menu="{$menu_id|wash}" data-menu-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/list'|ezurl( 'no' )|wash}"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Children as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-subtree" href="#" data-xrowextract-menu="{$menu_id|wash}" data-menu-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/tree'|ezurl( 'no' )|wash}"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-class" class="more" href="#" data-xrowextract-menu="{$menu_id|wash}" data-menu-submenu="{concat( $menu_id, 'Classes' )|wash}"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree of class'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-all" href="#" data-xrowextract-menu="{$menu_id|wash}" data-menu-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/all'|ezurl( 'no' )|wash}"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, every attribute…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="{$item_prefix}-csv-migration" href="#" data-xrowextract-menu="{$menu_id|wash}" data-menu-url="{'xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/migration'|ezurl( 'no' )|wash}"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, migration columns…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
