{* The CSV entries of the content tree's Advanced > Export menu (admininterface.ini [AdditionalMenuSettings]
   ExportMenuTemplateArray), for a user with the policy xrowextract/csv. Each opens xrowextract/csv filled in for the
   item (the entry parameters (node), (scope), (columns), XrowExtractEntry); the export is started there.
   "Subtree of class" opens a list of the classes below the item (xrowextract_export_submenus.tpl). *}
{if fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'csv' ) )}
<script type="text/javascript">
menuArray['ExportMenu']['elements']['menu-export-csv-children'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/list"|ezurl} {rdelim};
menuArray['ExportMenu']['elements']['menu-export-csv-subtree'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree"|ezurl} {rdelim};
menuArray['ExportMenu']['elements']['menu-export-csv-all'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/all"|ezurl} {rdelim};
menuArray['ExportMenu']['elements']['menu-export-csv-migration'] = {ldelim} 'url': {"xrowextract/csv/(node)/%nodeID%/(scope)/tree/(columns)/migration"|ezurl} {rdelim};
</script>
    <a id="menu-export-csv-children" href="#" onmouseover="ezpopmenu_mouseOver( 'ExportMenu' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Children as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="menu-export-csv-subtree" href="#" onmouseover="ezpopmenu_mouseOver( 'ExportMenu' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="menu-export-csv-class" class="more" href="#" onmouseover="ezpopmenu_showSubLevel( event, 'ExportClassMenu', 'menu-export-csv-class' ); return false;"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree of class'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="menu-export-csv-all" href="#" onmouseover="ezpopmenu_mouseOver( 'ExportMenu' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, every attribute…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
    <a id="menu-export-csv-migration" href="#" onmouseover="ezpopmenu_mouseOver( 'ExportMenu' )"><span class="a4-icon a4-bi-table" aria-hidden="true"></span> {'Subtree as CSV, migration columns…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
