{* The xrowextract pages: content of one class out, content of several classes out, a file read back in, a
   content package (.ezpkg) in or out, the schedules (for those allowed), and the background jobs last. $active:
   'csv', 'archive', 'import', 'package', 'schedules' or 'jobs'. $RunningJobsCount, when set by the calling view,
   is the viewer's queued-or-running job count, shown as a badge on the Jobs tab; failed scheduled runs not seen
   yet get a red one. *}
{ezcss_require( 'xrowextract-schedules.css' )}
<nav class="xe-tabs" aria-label="{'Export'|i18n( 'design/standard/extract' )|wash}">
    <a href={'xrowextract/csv'|ezurl}{if $active|eq( 'csv' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'One class of content export'|i18n( 'design/standard/extract' )}</strong>
        <small>{'A CSV file of one class, columns of your choice'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/archive'|ezurl}{if $active|eq( 'archive' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Multi class of content export'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Every class below the chosen nodes, one CSV per class, packed'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/import'|ezurl}{if $active|eq( 'import' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Import content file'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Read an XML, CSV or JSON export or a content package (.ezpkg) back in: create or update objects'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/package'|ezurl}{if $active|eq( 'package' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Import content package'|i18n( 'design/standard/extract' )}</strong>
        <small>{'A content package (.ezpkg): inspect, install, or build a sample one'|i18n( 'design/standard/extract' )}</small>
    </a>
    {def $can_schedule = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'schedule' ) )
         $can_history = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'history' ) )
         $can_destinations = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'destinations' ) )}
    {if or( $can_schedule, $can_history, $can_destinations )}
    <a href={cond( $can_schedule, 'xrowextract/schedules', $can_history, 'xrowextract/history', 'xrowextract/destinations' )|ezurl}{if $active|eq( 'schedules' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Schedules'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Exports and imports that run on their own, their destinations and history'|i18n( 'design/standard/extract' )}</small>
    </a>
    {/if}
    {undef $can_schedule $can_history $can_destinations}
    {* Its own name: the Jobs view sets $schedule_alerts itself, and this include shares its variables *}
    {def $xe_tab_alerts = fetch( 'xrowextract', 'schedule_alerts' )}
    <a href={'xrowextract/jobs'|ezurl}{if $active|eq( 'jobs' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Jobs'|i18n( 'design/standard/extract' )}{if and( is_set( $RunningJobsCount ), $RunningJobsCount|gt( 0 ) )} <span class="xe-tab-badge">{$RunningJobsCount}</span>{/if}{if $xe_tab_alerts|gt( 0 )} <span class="xe-tab-badge xe-tab-badge-bad" title="{'Failed scheduled runs you have not seen yet'|i18n( 'design/standard/extract' )|wash}">{$xe_tab_alerts}</span>{/if}</strong>
        <small>{'Background exports: started, running and finished'|i18n( 'design/standard/extract' )}</small>
    </a>
    {undef $xe_tab_alerts}
</nav>
{* The Schedules tab has three parts, each with its own features: schedules_nav.tpl shows their notices *}
{if $active|ne( 'schedules' )}{include uri='design:xrowextract/requirements_notice.tpl' page=$active}{/if}
