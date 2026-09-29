{* The two ways to export: one class as a CSV file, or the site as an archive. $active: 'csv' or 'archive' *}
<nav class="xe-tabs" aria-label="{'Export'|i18n( 'design/standard/extract' )|wash}">
    <a href={'xrowextract/csv'|ezurl}{if $active|eq( 'csv' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'One class'|i18n( 'design/standard/extract' )}</strong>
        <small>{'A CSV file of one class, columns of your choice'|i18n( 'design/standard/extract' )}</small>
    </a>
    <a href={'xrowextract/archive'|ezurl}{if $active|eq( 'archive' )} class="xe-tab-active" aria-current="page"{/if}>
        <strong>{'Site archive'|i18n( 'design/standard/extract' )}</strong>
        <small>{'Every class below the chosen nodes, one CSV per class, packed'|i18n( 'design/standard/extract' )}</small>
    </a>
</nav>
