{* The import and job entries of the node view's Import/Export menu (admininterface.ini [AdditionalMenuSettings]
   ImportExportMenuTemplateArray), with $menu_id and $item_prefix, each for its own policy: Import into this item
   (xrowextract/import, opened with the item as the target: import/(parent)/<node id>; only for a container below which
   the user may create content, %canImport%, which the view checks again), the background jobs (xrowextract/jobs), the
   export history (xrowextract/history) and the schedules (xrowextract/schedule). The job pages list every job of the
   user (all users' with xrowextract/all_jobs), not only this item's. *}
{def $xe_import = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'import' ) )
     $xe_jobs = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'jobs' ) )
     $xe_history = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'history' ) )
     $xe_schedule = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'schedule' ) )}
{if or( $xe_import, $xe_jobs, $xe_history, $xe_schedule )}
{if $xe_import}
<script type="text/javascript">
menuArray['{$menu_id}']['elements']['{$item_prefix}-import'] = {ldelim} 'url': {"xrowextract/import/(parent)/%nodeID%"|ezurl}, 'shown_if': '%canImport%' {rdelim};
</script>
    <a id="{$item_prefix}-import" href="#" onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-upload" aria-hidden="true"></span> {'Import into this item…'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
{if $xe_jobs}
    <a id="{$item_prefix}-jobs" href={'xrowextract/jobs'|ezurl} onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-list-ul" aria-hidden="true"></span> {'Export and import jobs'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
{if $xe_history}
    <a id="{$item_prefix}-history" href={'xrowextract/history'|ezurl} onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-clock-history" aria-hidden="true"></span> {'Export history'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
{if $xe_schedule}
    <a id="{$item_prefix}-schedules" href={'xrowextract/schedules'|ezurl} onmouseover="ezpopmenu_mouseOver( '{$menu_id}' )"><span class="a4-icon a4-bi-calendar3" aria-hidden="true"></span> {'Schedules'|i18n( 'extension/xrowextract/popupmenu' )}</a>
{/if}
{/if}
{undef $xe_import $xe_jobs $xe_history $xe_schedule}
