{* The five xrowextract pages: one class as a file, the site as an archive, background jobs, a file read
   back in, and a content package (.ezpkg) in or out. $active: 'csv', 'archive', 'jobs', 'import' or
   'package'. $RunningJobsCount, when set by the calling view, is the viewer's queued-or-running job
   count, shown as a badge on the Jobs tab. *}
<nav class="xe-tabs" aria-label="{'Export'|i18n( 'design/standard/extract' )|wash}">
    <a href={'xrowextract/csv'|ezurl}{if $active|eq( 'csv' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'One class'|i18n( 'design/standard/extract' )}</strong>
        <small>{'A CSV file of one class, columns of your choice'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/archive'|ezurl}{if $active|eq( 'archive' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Site archive'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Every class below the chosen nodes, one CSV per class, packed'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/jobs'|ezurl}{if $active|eq( 'jobs' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Jobs'|i18n( 'design/standard/extract' )}{if and( is_set( $RunningJobsCount ), $RunningJobsCount|gt( 0 ) )} <span class="xe-tab-badge">{$RunningJobsCount}</span>{/if}</strong>
        <small>{'Background exports: started, running and finished'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/import'|ezurl}{if $active|eq( 'import' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Import content file'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Read an XML, CSV or JSON export or a content package (.ezpkg) back in: create or update objects'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/package'|ezurl}{if $active|eq( 'package' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Import content package'|i18n( 'design/standard/extract' )}</strong>
        <small>{'A content package (.ezpkg): inspect, install, or build a sample one'|i18n( 'design/standard/extract' )}</small>
    </a>
</nav>
