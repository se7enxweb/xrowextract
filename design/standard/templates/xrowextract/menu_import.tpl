{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Import'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'Reads an XML, CSV or JSON file back into a class: rows update the objects they match and create the others. It also installs content packages (.ezpkg): their classes and content, or a single class or object XML. Nothing is written before you have seen the dry run.'|i18n( 'design/standard/extract' )}</p>

<p class="xe-side-lead"><a href="#xe-card-upload">{'Try a sample'|i18n( 'design/standard/extract' )}</a> &middot; <a href="#xe-card-reference">{'File format reference'|i18n( 'design/standard/extract' )}</a> &middot; <a href="#xe-ref-packages">{'Content packages (.ezpkg)'|i18n( 'design/standard/extract' )}</a></p>

<ol class="xe-side-steps">
    <li>{'File'|i18n( 'design/standard/extract' )}
        <small>{'Pick a format - XML, CSV, JSON or a content package - to Try a sample, Download a template or read its reference, then Upload a file (class and object XML files upload the same way). A package already in the repository comes here with Open in Import on the Package tab. Remove starts over.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Class and matching'|i18n( 'design/standard/extract' )}
        <small>{'Which class, which objects a row updates (remote id, object id, or always create), the language, and the parent for new objects; a class, language or parent column in the file wins for its rows.'|i18n( 'design/standard/extract' )}</small>
        <small>{'For a content package this step is Review and install the package instead: what it carries, the datatype check, the parent, how an existing object or class is handled, and Review the package (dry run).'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Column mapping'|i18n( 'design/standard/extract' )}
        <small>{'Columns are matched to attributes, attribute formats and special columns by name; change any, or ignore it; then Preview. Not needed for a content package.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Preview (dry run — nothing was written)'|i18n( 'design/standard/extract' )}
        <small>{'Per row: create, update (old and new values), unchanged, skip or error with the reason; for a package, every class and object it carries, and its files.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Import the changes'|i18n( 'design/standard/extract' )}
        <small>{'Import N changes writes only the changed attributes of each row; a row with an error writes nothing. A package: Install N changes (in the background), with its progress, log and install history on the Jobs page.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

<details open>
    <summary>{'Matching'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Remote ID: a row updates the object with the same remote id (the remote-id column), otherwise it creates one with that remote id. Best for moving content between sites.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Object ID: a row updates the object with that id (the object-id column); for changes made to an export of the same site.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Always create: every row is a new object.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Values'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Dates: 2026-09-29, 2026-09-29 12:00, ISO 8601 or Unix time.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Checkboxes: 1 or 0, yes or no, ja or nein.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Relations: the attribute-ids or attribute-remote-ids columns; names are ambiguous and refused.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Images and files: a path below var/storage or an address on this site.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Rich text: the HTML the export writes.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Importable datatypes'|i18n( 'design/standard/extract' )}</summary>
    <ul class="xe-typelist">
        {if is_set( $ImportableDatatypes )}
        {foreach $ImportableDatatypes as $datatype}
        <li><strong>{$datatype.name|wash}</strong> <code>{$datatype.id|wash}</code></li>
        {/foreach}
        {/if}
    </ul>
    <p class="xe-side-lead">{'Other datatypes are shown in the mapping as not supported, with the reason, and never written.'|i18n( 'design/standard/extract' )}</p>
</details>

<details>
    <summary>{'Safety'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Your permissions apply to every row: creating below the parent, editing the matched object.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Every import publishes new versions; keep a backup or an export of the class before a large import.'|i18n( 'design/standard/extract' )}</li>
        <li>{'The uploaded file is kept privately on the server and removed after the import or after a set number of hours.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Command line: ext:xrowextract:import --file ... (a dry run unless --apply).'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'No file size limit'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'A large file uploads in chunks (a progress bar, pause and resume, and a dropped connection retries and resumes on its own) - independent of the server’s own upload size settings.'|i18n( 'design/standard/extract' )}</li>
        <li>{'XML and CSV are read a row at a time while they import, however large the file; only a very large JSON file is read whole (JSON has no streaming format).'|i18n( 'design/standard/extract' )}</li>
        <li>{'The only real limit is free disk space, checked before the upload starts.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Above a row or size threshold, both the dry run and the import run as a background job (the Jobs tab) instead of holding the page open; a job that partly failed can resume from the row it stopped at.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
