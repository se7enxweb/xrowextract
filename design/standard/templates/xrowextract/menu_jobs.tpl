{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Jobs'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'Exports that run in the background, for selections too large for one page request. Start one with "Run in the background" in the One class or Site archive tab.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Set up the export as usual'|i18n( 'design/standard/extract' )}
        <small>{'Everything you chose (nodes, classes, languages, filters, sort, columns, format) goes into the job.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Run in the background'|i18n( 'design/standard/extract' )}
        <small>{'The job starts at once; this page shows its progress and updates on its own.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Download the file'|i18n( 'design/standard/extract' )}
        <small>{'When the job is done; you can leave and come back.'|i18n( 'design/standard/extract' )}</small></li>
</ol>
<p class="xe-side-lead">{'Below the jobs: the Install history - every package install, who, when, how and with what result, filtered by package, kept after the job is removed; a finished install offers Open the package and Export these again (its installed objects as a new package).'|i18n( 'design/standard/extract' )}</p>

<details open>
    <summary>{'Good to know'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'A job exports with your read access, like the page does.'|i18n( 'design/standard/extract' )}</li>
        <li>{'You see your own jobs; users with the policy xrowextract/all_jobs see the jobs of every user.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Finished jobs and their files are removed after the days set in csv.ini [Jobs] RetentionDays.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Files are kept privately on the server and are only sent to their owner.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Command line: ext:xrowextract:job --list, --run=<id>, --clean.'|i18n( 'design/standard/extract' )|wash}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
