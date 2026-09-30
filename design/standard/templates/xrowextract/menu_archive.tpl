{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Multi class of content export'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

{* The steps follow the page's cards from top to bottom: Nodes, Classes, Languages filters and columns,
   File format, then the buttons at the bottom. Keep them in step when a card changes. *}
<div class="xe-side">
<p class="xe-side-lead">{'Exports the content below the chosen nodes as one archive: a CSV, JSON or XML file for every class, with a manifest. For a backup to read, a migration, an audit or a hand-over.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Choose the nodes'|i18n( 'design/standard/extract' )}
        <small>{'A set (Sites, content, media, users ...) is the quickest start, Browse for nodes adds any other; the counts show how much is below each node.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Check the classes'|i18n( 'design/standard/extract' )}
        <small>{'All classes with content are ticked; untick the ones you do not need.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Languages, filters and columns'|i18n( 'design/standard/extract' )}
        <small>{'The languages to export; filters by date, section and visibility; the columns: standard, migration or attributes only, and the plain text of rich text fields if you want it.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Choose the archive and file format'|i18n( 'design/standard/extract' )}
        <small>{'ZIP opens everywhere; TAR.GZ, TAR.BZ2 and TAR.XZ are smaller; 7-Zip and RAR need their programs on the server. The files inside are CSV, JSON or XML.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Download archive, or run in the background'|i18n( 'design/standard/extract' )}
        <small>{'Most sites are written in seconds and nothing is kept on the server. A large archive runs as a job, and Export as package makes a content package (.ezpkg) of the nodes instead; the Jobs tab has the files.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

<details open>
    <summary>{'In the archive'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'One file per class in the chosen format, named by the class identifier.'|i18n( 'design/standard/extract' )}</li>
        <li>{'One row per object at its main location; an object is written once even when nodes overlap.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Standard columns: object id, remote id, main node, parent node, URL alias, published, modified, then every attribute of the class. Migration adds what rebuilding elsewhere needs; Attributes only leaves the rest out.'|i18n( 'design/standard/extract' )}</li>
        <li>{'manifest.json (machine readable) and README.txt: nodes, classes, rows per file, format.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Safety'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Only objects you may read are exported.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Password hashes are only in the archive when you include them; that needs the policy xrowextract/password_hash.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Cells that start like a formula (= + - @) get a leading apostrophe, so a spreadsheet does not run them.'|i18n( 'design/standard/extract' )}</li>
        <li>{'An export can hold personal data: store and share it accordingly, and delete it when it is no longer needed.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Archive formats on this server'|i18n( 'design/standard/extract' )}</summary>
    <p class="xe-side-lead">{'7-Zip and RAR need their programs (7z, rar) on the server; they appear as soon as they are installed.'|i18n( 'design/standard/extract' )}</p>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
