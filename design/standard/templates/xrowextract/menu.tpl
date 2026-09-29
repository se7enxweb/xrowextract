{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'CSV export'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'Exports the objects of one class below a node as a CSV file, one row per object and one column per chosen attribute. Spreadsheets, mail tools and other systems read it.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Choose what to export'|i18n( 'design/standard/extract' )}
        <small>{'Scope (below a node, below a node tree, the whole site), the class, the languages.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Filter and sort'|i18n( 'design/standard/extract' )}
        <small>{'By date (also changed since your last export), section, state, visibility, name or an attribute; the order of the rows.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Choose the file format'|i18n( 'design/standard/extract' )}
        <small>{'CSV, JSON or XML; for CSV the separator, line endings and quoting, with a sample row.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Pick the columns'|i18n( 'design/standard/extract' )}
        <small>{'Attributes, attribute formats, special columns and column sets; rename, reorder, remove.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Preview the rows'|i18n( 'design/standard/extract' )}
        <small>{'The first rows as a spreadsheet shows them, with warnings for shifted columns.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Download, or run in the background'|i18n( 'design/standard/extract' )}
        <small>{'Large exports run as a job; the Jobs tab has the file when it is done.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

<details open>
    <summary>{'Tips'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Excel: in many locales it expects a semicolon. If umlauts look wrong, open the file with Data, From Text/CSV and choose UTF-8.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Keep "Quoted": values with commas, quotes or line breaks (rich text) stay in their cell.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Very large selections: export in batches with "Skip" and "Take at most".'|i18n( 'design/standard/extract' )}</li>
        <li>{'Your settings and columns are kept per class for your session.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Regular updates: filter on "Changed since my last export" to get only what changed since your last download.'|i18n( 'design/standard/extract' )}</li>
        <li>{'"Reset to defaults" starts again from the default node and the class with the most objects.'|i18n( 'design/standard/extract' )}</li>
        <li>{'The Import tab reads such a file back in (the Migration column set keeps what it needs).'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Special columns'|i18n( 'design/standard/extract' )}</summary>
    <dl>
        <dt>{'Login, E-Mail, User Status'|i18n( 'design/standard/extract' )}</dt>
        <dd>{'From the user account of the object; empty for objects that are not users.'|i18n( 'design/standard/extract' )}</dd>
        <dt>{'Published, Modified'|i18n( 'design/standard/extract' )}</dt>
        <dd>{'Dates as YYYY-MM-DD.'|i18n( 'design/standard/extract' )}</dd>
        <dt>{'URL Alias, Absolute URL Alias'|i18n( 'design/standard/extract' )}</dt>
        <dd>{'The path of the main location, or the full address on the public site.'|i18n( 'design/standard/extract' )}</dd>
        <dt>{'Main Node ID, Main Parent Node ID, Main Parent Name, Parent Names'|i18n( 'design/standard/extract' )}</dt>
        <dd>{'Where the object is placed; names only of locations you may read.'|i18n( 'design/standard/extract' )}</dd>
    </dl>
</details>

<details>
    <summary>{'Safety'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Only objects you may read are exported.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Cells that start like a formula (= + - @) get a leading apostrophe, so a spreadsheet does not run them.'|i18n( 'design/standard/extract' )}{if ezini( 'General', 'NeutralizeFormulas', 'csv.ini' )|eq( 'disabled' )} <strong>{'Switched off in csv.ini.'|i18n( 'design/standard/extract' )}</strong>{/if}</li>
        <li>{'Password hash and hash type are special columns for users with the policy xrowextract/password_hash (administrators have it); csv.ini can switch them off.'|i18n( 'design/standard/extract' )}{if ezini( 'General', 'AllowPasswordHashExport', 'csv.ini' )|eq( 'disabled' )} <strong>{'Switched off in csv.ini.'|i18n( 'design/standard/extract' )}</strong>{/if}</li>
        <li>{'An export can hold personal data: store and share it accordingly, and delete it when it is no longer needed.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Exported datatypes'|i18n( 'design/standard/extract' )}</summary>
    <p class="xe-side-lead">{'Attributes of other datatypes give empty cells.'|i18n( 'design/standard/extract' )}</p>
    <ul class="xe-typelist">
        {if is_set( $ExportableDatatypeNames )}
        {foreach $ExportableDatatypeNames as $datatype}
        {if $datatype.name|eq( $datatype.id )}
        <li class="xe-typelist-missing"><code>{$datatype.id|wash}</code> <small>{'not installed on this site'|i18n( 'design/standard/extract' )}</small></li>
        {else}
        <li><strong>{$datatype.name|wash}</strong> <code>{$datatype.id|wash}</code>{if $datatype.cell} <small>→ {$datatype.cell|wash}</small>{/if}</li>
        {/if}
        {/foreach}
        {else}
        {foreach ezini( 'General', 'ExportableDatatypes', 'csv.ini' ) as $datatype}
        <li><code>{$datatype|wash}</code></li>
        {/foreach}
        {/if}
    </ul>
</details>

<details>
    <summary>{'Settings'|i18n( 'design/standard/extract' )}</summary>
    <dl>
        <dt>export.ini ExportClasses</dt>
        <dd>{if ezini( 'ExportSettings', 'ExportClasses', 'export.ini' )}{ezini( 'ExportSettings', 'ExportClasses', 'export.ini' )|implode( ', ' )|wash}{else}{'every class'|i18n( 'design/standard/extract' )}{/if}</dd>
        <dt>export.ini StartNodeID</dt>
        <dd>{if ezini( 'ExportSettings', 'StartNodeID', 'export.ini' )}{ezini( 'ExportSettings', 'StartNodeID', 'export.ini' )|wash}{else}{'the root node of the default siteaccess'|i18n( 'design/standard/extract' )}{/if}</dd>
        <dt>export.ini DefaultClassID</dt>
        <dd>{if ezini( 'ExportSettings', 'DefaultClassID', 'export.ini' )}{ezini( 'ExportSettings', 'DefaultClassID', 'export.ini' )|wash}{else}{'the class with the most objects in the selection'|i18n( 'design/standard/extract' )}{/if}</dd>
        <dt>export.ini PreselectAttributes</dt>
        <dd>{ezini( 'ExportSettings', 'PreselectAttributes', 'export.ini' )|wash}</dd>
        <dt>csv.ini StripURLText</dt>
        <dd>{ezini( 'General', 'StripURLText', 'csv.ini' )|wash}</dd>
        <dt>csv.ini NeutralizeFormulas</dt>
        <dd>{if ezini_hasvariable( 'General', 'NeutralizeFormulas', 'csv.ini' )}{ezini( 'General', 'NeutralizeFormulas', 'csv.ini' )|wash}{else}enabled{/if}</dd>
        <dt>csv.ini AllowPasswordHashExport</dt>
        <dd>{if ezini_hasvariable( 'General', 'AllowPasswordHashExport', 'csv.ini' )}{ezini( 'General', 'AllowPasswordHashExport', 'csv.ini' )|wash}{else}enabled{/if}</dd>
    </dl>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
