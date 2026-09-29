{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Import'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'Reads a CSV or JSON file back into a class: rows update the objects they match and create the others. Nothing is written before you have seen the dry run.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Choose the class, matching, language and parent'|i18n( 'design/standard/extract' )}
        <small>{'Before or after the upload; a class, language or parent column in the file wins for its rows.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Upload the file'|i18n( 'design/standard/extract' )}
        <small>{'An export of this tool (the Migration column set carries everything needed), or a filled template.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Check the column mapping'|i18n( 'design/standard/extract' )}
        <small>{'Columns are matched to attributes, attribute formats and special columns by name; change any, or ignore it.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Read the dry run'|i18n( 'design/standard/extract' )}
        <small>{'Per row: create, update (old and new values), unchanged, skip or error with the reason.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Import the changes'|i18n( 'design/standard/extract' )}
        <small>{'Only the changed attributes of each row are written; a row with an error writes nothing.'|i18n( 'design/standard/extract' )}</small></li>
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
        <li>{'The uploaded file is kept privately on the server and removed after the import or after a day.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Command line: ext:xrowextract:import --file ... (a dry run unless --apply).'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
