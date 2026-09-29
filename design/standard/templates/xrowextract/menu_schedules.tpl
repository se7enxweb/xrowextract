{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Schedules'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'Exports and imports that run on their own: a saved preset, a site archive, a package export or an import from a folder or a destination.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Add a destination'|i18n( 'design/standard/extract' )}
        <small>{'SFTP, FTP/FTPS, a local or NAS folder, S3, WebDAV or an HTTP upload; test the connection first.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Create a schedule'|i18n( 'design/standard/extract' )}
        <small>{'What runs, when, full or only the changes, where the file goes and who hears about it.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Follow the history'|i18n( 'design/standard/extract' )}
        <small>{'Every run with its rows, size, checksum, delivery and warnings; failures also as a badge on the Jobs tab.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

<details open>
    <summary>{'Good to know'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'A schedule runs with the read access of its owner.'|i18n( 'design/standard/extract' )}</li>
        <li>{'What a schedule refers to and that no longer exists (a node, a class, an attribute) is skipped with a warning; the rest is still exported.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Every export has a typed column manifest (manifest.json): the importer uses it to map every column exactly.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Passwords and keys of destinations are stored encrypted and are never shown again.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Policies: xrowextract/schedule, xrowextract/destinations, xrowextract/history; with xrowextract/all_jobs you see those of everyone.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Command line: ext:xrowextract:schedule, ext:xrowextract:destination, ext:xrowextract:history.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
