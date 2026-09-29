{ezcss_require( 'xrowextract.css' )}
<form name="eZImport" method="post" enctype="multipart/form-data" action={'xrowextract/import'|ezurl}>
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Import settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='import'}
    <div class="xe-cards">

        {* 1. Upload *}
        <section class="xe-card" aria-labelledby="xe-card-upload">
            <header class="xe-card-head">
                <span class="xe-step">1</span>
                <div>
                    <h2 id="xe-card-upload">{'File'|i18n('design/standard/extract')}</h2>
                    <p>{'An XML, CSV or JSON file written by the export views, any column set.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            {if $UploadError}<p class="xe-error">{$UploadError|wash}</p>{/if}
            {if $ParseError}<p class="xe-error">{$ParseError|wash}</p>{/if}

            {if $HasFile|not}
            <div class="xe-field">
                <label class="xe-label" for="xe-file">{'Choose a file'|i18n('design/standard/extract')}</label>
                <input type="file" name="ImportFile" id="xe-file" accept=".xml,.csv,.json,.ezpkg,.tar.gz,.tgz,text/xml,application/xml,text/csv,application/json,application/gzip" />
                <p class="xe-help">{'XML: the shape XrowExtractWriter writes, with its own column ids and class - the most exact, and what "Start from a template" and "Try a sample" build. CSV: separator and encoding are detected automatically (UTF-8 with or without a BOM); you can still change the separator once it is uploaded. JSON: an array of objects, one per row.'|i18n('design/standard/extract')}</p>
                <input class="defaultbutton" type="submit" name="Upload" value="{'Upload'|i18n('design/standard/extract')}" />
            </div>

            {if $SampleClassGroups|count}
            <div class="xe-field xe-sample-try">
                <span class="xe-label">{'Try a sample'|i18n('design/standard/extract')}</span>
                <p class="xe-help">{'Nothing to import yet? Pick any class and one click builds a small file from the site’s own content for it - an edited row, an unchanged row, a new object, and (when the class has a date attribute) a row with a deliberate error - and loads it below, ready to preview. A class without objects gets the new object only. Applying it writes real content.'|i18n('design/standard/extract')}</p>
                <label class="xe-label xe-label-small" for="xe-sample-class">{'Class for the sample'|i18n('design/standard/extract')}</label>
                <select name="SampleClassID" id="xe-sample-class" class="xe-sample-class">
                    {foreach $SampleClassGroups as $group}
                    <optgroup label="{$group.name|wash}">
                        {foreach $group.classes as $class}
                        <option value="{$class.id}"{if $class.id|eq( $SampleClassID )} selected="selected"{/if}>{$class.name|wash} ({$class.identifier|wash}) · {if $class.count|eq( 0 )}{'no objects yet'|i18n('design/standard/extract')}{else}{$class.count} {'objects'|i18n('design/standard/extract')}{/if}</option>
                        {/foreach}
                    </optgroup>
                    {/foreach}
                </select>
                <div class="xe-inline xe-sample-buttons">
                    <button class="defaultbutton" type="submit" name="TrySample" value="xml">{'Try a sample (XML - recommended)'|i18n('design/standard/extract')}</button>
                    <button class="button" type="submit" name="TrySample" value="json">{'Try a sample (JSON)'|i18n('design/standard/extract')}</button>
                    <button class="button" type="submit" name="TrySample" value="csv">{'Try a sample (CSV)'|i18n('design/standard/extract')}</button>
                </div>
            </div>
            {/if}
            {else}
            <div class="xe-node">
                <span class="xe-node-text">
                    <span class="xe-node-name">{$UploadedName|wash}</span>
                    <span class="xe-node-meta">{$FileRowCount} {'rows'|i18n('design/standard/extract')} · {$FileHeader|count} {'columns'|i18n('design/standard/extract')} · {$ImportFormat|wash}{if $IsSample} · {'sample'|i18n('design/standard/extract')}{/if}</span>
                </span>
                <input class="button" type="submit" name="RemoveFile" value="{'Remove'|i18n('design/standard/extract')}" />
            </div>
            {if $IsSample}
            <p class="xe-note">{'This is a sample built from the site’s own content, for trying the importer - not a file you uploaded. Applying it writes real content (see step 4).'|i18n('design/standard/extract')}</p>
            {/if}
            {if $ImportFormat|eq( 'csv' )}
            <div class="xe-field">
                <span class="xe-label">{'Separator'|i18n('design/standard/extract')}</span>
                <div class="xe-segmented" role="radiogroup">
                    {foreach hash( 'comma', ',', 'semicolon', ';', 'tab', 'Tab', 'pipe', '|' ) as $key => $label}
                    <label><input type="radio" name="ImportSeparator" value="{$key}"{if $key|eq( $ImportSeparatorKey )} checked{/if} /><span>{$label}</span></label>
                    {/foreach}
                </div>
                <input class="button" type="submit" name="Preview" value="{'Reparse'|i18n('design/standard/extract')}" />
                <p class="xe-help">{'Detected automatically from the header line; change it if the columns above do not line up.'|i18n('design/standard/extract')}</p>
            </div>
            {/if}
            {/if}
        </section>

        {* 2. Class, matching, language, placement: chosen before or after the upload *}
        <section class="xe-card" aria-labelledby="xe-card-target">
            <header class="xe-card-head">
                <span class="xe-step">2</span>
                <div>
                    <h2 id="xe-card-target">{'Class and matching'|i18n('design/standard/extract')}</h2>
                    <p>{'Which class, which objects to update, and where new ones go.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-class">{'Class'|i18n('design/standard/extract')}</label>
                    <div class="xe-inline">
                        <select name="ClassID" id="xe-class">
                            <option value="0">{'Choose...'|i18n('design/standard/extract')}</option>
                            {foreach $ClassChoices as $class}
                            <option value="{$class.id}"{if $class.id|eq( $ClassID )} selected{/if}>{$class.name|wash} ({$class.count})</option>
                            {/foreach}
                        </select>
                        <input class="button" type="submit" name="Preview" value="{'Update'|i18n('design/standard/extract')}" />
                    </div>
                    <p class="xe-help">{'Used for rows without a "class" column (a special column, exported as "class"); with one, each row picks its own class.'|i18n('design/standard/extract')}</p>
                </div>

                <div class="xe-field">
                    <span class="xe-label" id="xe-match-label">{'Match existing objects by'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-match-label">
                        <label title="{'The default when the file has a remote-id column'|i18n('design/standard/extract')|wash}"><input type="radio" name="MatchMode" value="remote_id"{if $MatchMode|eq( 'remote_id' )} checked{/if} /><span>{'Remote ID'|i18n('design/standard/extract')}</span></label>
                        <label><input type="radio" name="MatchMode" value="object_id"{if $MatchMode|eq( 'object_id' )} checked{/if} /><span>{'Object ID'|i18n('design/standard/extract')}</span></label>
                        <label title="{'Every row creates a new object, even one with a remote or object id column'|i18n('design/standard/extract')|wash}"><input type="radio" name="MatchMode" value="none"{if $MatchMode|eq( 'none' )} checked{/if} /><span>{'Always create'|i18n('design/standard/extract')}</span></label>
                    </div>
                    <p class="xe-help">{'A row that matches an existing object updates it; otherwise it creates one.'|i18n('design/standard/extract')}</p>
                </div>

                <div class="xe-field">
                    <label class="xe-label" for="xe-language">{'Language'|i18n('design/standard/extract')}</label>
                    <select name="Language" id="xe-language">
                        {foreach $ContentLanguages as $locale => $lang}
                        <option value="{$locale}"{if $locale|eq( $Language )} selected{/if}>{$lang.name|wash} ({$locale}){if $lang.default} — {'site default'|i18n('design/standard/extract')}{/if}</option>
                        {/foreach}
                    </select>
                    <p class="xe-help">{'Used for rows without a language column; a language column creates or updates that translation instead.'|i18n('design/standard/extract')}</p>
                </div>

                <div class="xe-field">
                    <span class="xe-label">{'Parent for new objects'|i18n('design/standard/extract')}</span>
                    <div class="xe-node">
                        {if $ParentNode}
                        <span class="xe-node-text">
                            <span class="xe-node-name">{$ParentNode.name|wash}</span>
                            <span class="xe-node-path">{$ParentNode.path|wash}</span>
                        </span>
                        {else}
                        <span class="xe-node-text xe-node-missing">{'No node %id, or you may not read it.'|i18n('design/standard/extract',, hash( '%id', $ParentNodeID ))}</span>
                        {/if}
                        <input class="button" type="submit" name="BrowseParent" value="{'Change'|i18n('design/standard/extract')}" />
                    </div>
                    <input name="ParentNodeID" type="hidden" value="{$ParentNodeID|wash}" />
                    <input name="MappingClassID" type="hidden" value="{$MappingClassID|wash}" />
                    <p class="xe-help">{'Used when a row has no parent-remote-id or main-parent-node-id column.'|i18n('design/standard/extract')}</p>
                </div>
            </div>
            <div class="xe-field">
                <span class="xe-label">{'Start from a template'|i18n('design/standard/extract')}</span>
                <div class="xe-inline">
                    <select name="TemplateFormat" aria-label="{'File type'|i18n('design/standard/extract')|wash}">
                        <option value="xml" selected>{'XML (recommended)'|i18n('design/standard/extract')}</option>
                        <option value="json">JSON</option>
                        <option value="csv">CSV</option>
                    </select>
                    <input class="button" type="submit" name="DownloadTemplate" value="{'Download a template for this class'|i18n('design/standard/extract')}" />
                </div>
                <p class="xe-help">{'An empty file with every column the import understands for the class (the Migration column set): fill in rows and import it. An export with the Migration set is the same file with the rows filled in. XML also carries the column ids and the class itself, so it is the most exact to fill in by hand.'|i18n('design/standard/extract')}</p>
            </div>
            {if $ClassID}
            <div class="xe-field">
                <a class="button" href={concat( 'xrowextract/package?ClassID=', $ClassID )|ezurl}>{'Content + class package (.ezpkg)'|i18n('design/standard/extract')}</a>
                <p class="xe-help">{'A richer starting point than a CSV/JSON template: a real, installable package with the class definition and 2-3 sample content objects for it, built on the Package page.'|i18n('design/standard/extract')}</p>
            </div>
            {/if}
        </section>

        {if $HasFile}
        {* 3. Column mapping *}
        <section class="xe-card" aria-labelledby="xe-card-mapping">
            <header class="xe-card-head">
                <span class="xe-step">3</span>
                <div>
                    <h2 id="xe-card-mapping">{'Column mapping'|i18n('design/standard/extract')}</h2>
                    <p>{'Every file column, mapped automatically; change any of them.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            {if $Mapping|count|eq( 0 )}
            <p class="xe-empty-state">{'The file has no columns.'|i18n('design/standard/extract')}</p>
            {else}
            <div class="xe-scroll" tabindex="0">
                <table class="xe-table">
                    <thead><tr>
                        <th>{'File column'|i18n('design/standard/extract')}</th>
                        <th>{'Maps to'|i18n('design/standard/extract')}</th>
                    </tr></thead>
                    <tbody>
                    {foreach $Mapping as $map}
                    <tr>
                        <td><code>{$map.column|wash}</code></td>
                        <td>
                            <select name="Mapping[{$map.index}]">
                                <option value="ignore"{if $map.target|eq( 'ignore' )} selected{/if}>{'Ignore'|i18n('design/standard/extract')}</option>
                                {if $SpecialChoices|count}
                                <optgroup label="{'Special columns'|i18n('design/standard/extract')|wash}">
                                {foreach $SpecialChoices as $special}
                                <option value="{$special.value}"{if $map.target|eq( $special.value )} selected{/if}>{$special.name|wash}</option>
                                {/foreach}
                                </optgroup>
                                {/if}
                                {if $AttributeChoices|count}
                                <optgroup label="{'Attributes'|i18n('design/standard/extract')|wash}">
                                {foreach $AttributeChoices as $attribute}
                                <option value="{$attribute.value}"{if $map.target|eq( $attribute.value )} selected{/if}{if $attribute.importable|not} disabled title="{$attribute.reason|wash}"{/if}>{$attribute.name|wash}{if $attribute.importable|not} ({'not supported'|i18n('design/standard/extract')}){/if}</option>
                                {/foreach}
                                </optgroup>
                                {/if}
                                {if $FormatChoices|count}
                                <optgroup label="{'Attribute formats'|i18n('design/standard/extract')|wash}">
                                {foreach $FormatChoices as $format}
                                <option value="{$format.value}"{if $map.target|eq( $format.value )} selected{/if}>{$format.name|wash}</option>
                                {/foreach}
                                </optgroup>
                                {/if}
                            </select>
                            {if $map.reason}<p class="xe-help">{$map.reason|wash}</p>{/if}
                        </td>
                    </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
            {/if}

            <div class="xe-toolbar">
                <span class="xe-spacer"></span>
                <input class="defaultbutton" type="submit" name="Preview" value="{'Preview'|i18n('design/standard/extract')}" />
            </div>
        </section>
        {/if}

        {if $Preview|or( $Applied )}
        {include uri='design:xrowextract/import_result.tpl'}
        {/if}

        {include uri='design:xrowextract/import_package.tpl'}

        {* File format reference: complete, technical, with real examples for the reference class *}
        <section class="xe-card" id="xe-card-reference" aria-labelledby="xe-card-reference-h">
            <header class="xe-card-head">
                <div>
                    <h2 id="xe-card-reference-h">{'File format reference'|i18n('design/standard/extract')}</h2>
                    <p>{if $ReferenceClass}{'Examples below use real data of %class where the site has some.'|i18n('design/standard/extract',, hash( '%class', $ReferenceClass.name ))}{else}{'Choose a class above for examples built from its own content.'|i18n('design/standard/extract')}{/if}</p>
                </div>
            </header>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_xml'|ezurl}{if ezpreference( 'admin_xrowextract_ref_xml' )|eq( 'open' )} open{/if}>
                <summary>{'XML - the recommended format'|i18n('design/standard/extract')}</summary>
                <p class="xe-help">{'What XrowExtractWriter writes, and what "Start from a template" and "Try a sample" build: a root <export>, its class and when it was written; a <columns> block naming every column once, by a short display name and its exact column id (an attribute identifier, "identifier:format" for an attribute format, or a special column id such as ezcontentobject.remote_id); then one <object> per row, one <field name="..."> per column, matched to the <columns> block by that same name. The importer reads a column by its id, not by guessing from a name, so XML mapping is always exact. A DOCTYPE is refused outright (never written by this tool, and the classic way to smuggle in external entities).'|i18n('design/standard/extract')|wash}</p>
                {if $ReferenceExamples.xml.rowCount|gt( 0 )}
                <pre class="xe-example">{$ReferenceExamples.xml.text|wash}</pre>
                <div class="xe-toolbar">
                    <span class="xe-spacer"></span>
                    <input class="button" type="submit" name="DownloadExample" value="xml" formnovalidate="formnovalidate" />
                </div>
                {else}
                <pre class="xe-example">&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;export class="ng_article" created="2026-09-29T12:00:00+00:00"&gt;
  &lt;columns&gt;
    &lt;column name="title" id="title"&gt;Title&lt;/column&gt;
    &lt;column name="remote-id" id="ezcontentobject.remote_id"&gt;Remote ID&lt;/column&gt;
    &lt;column name="authors-ids" id="authors:ids"&gt;Author: object ids&lt;/column&gt;
  &lt;/columns&gt;
  &lt;object&gt;
    &lt;field name="title"&gt;Sample article&lt;/field&gt;
    &lt;field name="remote-id"&gt;xrowextract-sample-1&lt;/field&gt;
    &lt;field name="authors-ids"&gt;42&lt;/field&gt;
  &lt;/object&gt;
&lt;/export&gt;</pre>
                <p class="xe-help">{'A static example (this class has no content on this site yet to show a real one).'|i18n('design/standard/extract')}</p>
                {/if}
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_csv'|ezurl}{if ezpreference( 'admin_xrowextract_ref_csv' )|ne( 'closed' )} open{/if}>
                <summary>{'CSV'|i18n('design/standard/extract')}</summary>
                <ul>
                    <li>{'The header row holds the column names (an attribute identifier with "-" for "_", an attribute format as identifier-format, or a special column such as remote-id); the importer maps them by name, or lets you change any mapping by hand.'|i18n('design/standard/extract')}</li>
                    <li>{'The separator is detected from the header row (comma, semicolon, tab or pipe, whichever appears most) and can be changed once the file is uploaded.'|i18n('design/standard/extract')}</li>
                    <li>{'Standard RFC 4180 quoting: a cell that holds the separator, a quote or a line break is wrapped in double quotes, and a quote inside it is doubled ("" for a literal "); a quoted cell may contain real line breaks.'|i18n('design/standard/extract')}</li>
                    <li>{'UTF-8, with or without a byte order mark (BOM); either is read correctly.'|i18n('design/standard/extract')}</li>
                    <li>{'An empty cell is an empty value, not "not present": for an update, an empty cell can clear an attribute.'|i18n('design/standard/extract')}</li>
                </ul>
                {if $ReferenceExamples.csv.rowCount|gt( 0 )}
                <pre class="xe-example">{$ReferenceExamples.csv.text|wash}</pre>
                <div class="xe-toolbar">
                    <span class="xe-spacer"></span>
                    <input class="button" type="submit" name="DownloadExample" value="csv" formnovalidate="formnovalidate" />
                </div>
                {else}
                <pre class="xe-example">"title","remote-id","authors-ids","class","language"
"Sample article","xrowextract-sample-1","42","ng_article","eng-US"</pre>
                <p class="xe-help">{'A static example (this class has no content on this site yet to show a real one).'|i18n('design/standard/extract')}</p>
                {/if}
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_json'|ezurl}{if ezpreference( 'admin_xrowextract_ref_json' )|ne( 'closed' )} open{/if}>
                <summary>{'JSON'|i18n('design/standard/extract')}</summary>
                <p class="xe-help">{'An array of objects, one per row, each key a column name (the same names CSV uses); every value a string. No column list and no class attribute - a "class" column carries the class, the same as CSV.'|i18n('design/standard/extract')}</p>
                {if $ReferenceExamples.json.rowCount|gt( 0 )}
                <pre class="xe-example">{$ReferenceExamples.json.text|wash}</pre>
                <div class="xe-toolbar">
                    <span class="xe-spacer"></span>
                    <input class="button" type="submit" name="DownloadExample" value="json" formnovalidate="formnovalidate" />
                </div>
                {else}
                <pre class="xe-example">[
  {ldelim}"title": "Sample article", "remote-id": "xrowextract-sample-1", "authors-ids": "42", "class": "ng_article", "language": "eng-US"{rdelim}
]</pre>
                <p class="xe-help">{'A static example (this class has no content on this site yet to show a real one).'|i18n('design/standard/extract')}</p>
                {/if}
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_special'|ezurl}{if ezpreference( 'admin_xrowextract_ref_special' )|ne( 'closed' )} open{/if}>
                <summary>{'Special columns'|i18n('design/standard/extract')}</summary>
                <p class="xe-help">{'Every column this importer reads outside the class’s own attributes. A column not listed here, or an attribute the class does not have, is ignored (never guessed at).'|i18n('design/standard/extract')}</p>
                <div class="xe-scroll" tabindex="0">
                    <table class="xe-table">
                        <thead><tr><th>{'Column'|i18n('design/standard/extract')}</th><th>{'id'|i18n('design/standard/extract')}</th><th>{'What it does'|i18n('design/standard/extract')}</th></tr></thead>
                        <tbody>
                            <tr><td><code>remote-id</code></td><td><code>ezcontentobject.remote_id</code></td><td>{'The default match key: an existing object with this remote id is updated; otherwise it is created with it.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>object-id</code></td><td><code>ezcontentobject.id</code></td><td>{'Matches by object id instead, when matching is set to Object ID.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>class</code></td><td><code>ezcontentobject.class_identifier</code></td><td>{'The class for this row, overriding the class chosen above (an identifier or a numeric id); an XML file’s own export class attribute is the default when none is chosen.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>language</code></td><td><code>ezcontentobject.language</code></td><td>{'The translation this row creates or updates, overriding the language chosen above.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>parent-remote-id</code></td><td><code>node.parent_remote_id</code></td><td>{'Where a new object is placed, by the parent’s remote id; wins over main-parent-node-id and the chosen parent.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>main-parent-node-id</code></td><td><code>ezcontentobject.main_parent_node_id</code></td><td>{'Where a new object is placed, by node id; used when there is no parent-remote-id column.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>published-timestamp</code> / <code>published</code></td><td><code>ezcontentobject.published_timestamp</code></td><td>{'Sets the object’s published date after writing it (Unix time, or any accepted date form) - for preserving history on a migration.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>modified-timestamp</code> / <code>modified</code></td><td><code>ezcontentobject.modified_timestamp</code></td><td>{'Sets the object’s last modified date the same way.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>section</code></td><td><code>ezcontentobject.section</code></td><td>{'The section, by name or numeric id.'|i18n('design/standard/extract')}</td></tr>
                            <tr><td><code>node-remote-id</code></td><td><code>node.remote_id</code></td><td>{'The main node’s own remote id, as exported; informational only, not written back.'|i18n('design/standard/extract')}</td></tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_attributes'|ezurl}{if ezpreference( 'admin_xrowextract_ref_attributes' )|ne( 'closed' )} open{/if}>
                <summary>{'Attribute and attribute format columns'|i18n('design/standard/extract')}</summary>
                <ul>
                    <li>{'An attribute column is named after the attribute identifier, "-" for "_" (a CSV/JSON header only; XML uses the identifier itself as the column id and any display name).'|i18n('design/standard/extract')}</li>
                    <li>{'An attribute format column is identifier-format, for example authors-ids, authors-remote-ids or metadata-json (identifier:format as the XML/mapping id). Only the formats named per datatype below are accepted for import; the others (word counts, sizes, URLs of a file...) are export-only.'|i18n('design/standard/extract')}</li>
                </ul>
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_datatypes'|ezurl}{if ezpreference( 'admin_xrowextract_ref_datatypes' )|ne( 'closed' )} open{/if}>
                <summary>{'Every importable datatype: accepted values, with an example'|i18n('design/standard/extract')}</summary>
                <div class="xe-scroll" tabindex="0">
                    <table class="xe-table">
                        <thead><tr><th>{'Datatype'|i18n('design/standard/extract')}</th><th>{'Accepted as'|i18n('design/standard/extract')}</th><th>{'Example'|i18n('design/standard/extract')}</th></tr></thead>
                        <tbody>
                        <tr><td>ezstring, eztext</td><td>{'The text itself.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezstring.value ), $DatatypeExamples.ezstring.value, cond( is_set( $DatatypeExamples.eztext.value ), $DatatypeExamples.eztext.value, 'Sample text' ) )|wash}</td></tr>
                        <tr><td>ezinteger</td><td>{'A whole number.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezinteger.value ), $DatatypeExamples.ezinteger.value, '42' )|wash}</td></tr>
                        <tr><td>ezfloat</td><td>{'A decimal number.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezfloat.value ), $DatatypeExamples.ezfloat.value, '3.5' )|wash}</td></tr>
                        <tr><td>ezboolean</td><td>{'1 or 0, yes or no, ja or nein, true or false, on or off.'|i18n('design/standard/extract')}</td><td>1</td></tr>
                        <tr><td>ezemail</td><td>{'The address.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezemail.value ), $DatatypeExamples.ezemail.value, 'name@example.com' )|wash}</td></tr>
                        <tr><td>ezurl</td><td>{'A URL, or "URL|link text".'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezurl.value ), $DatatypeExamples.ezurl.value, 'https://example.com|Example' )|wash}</td></tr>
                        <tr><td>ezdate</td><td>{'YYYY-MM-DD, ISO 8601, or a Unix timestamp.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezdate.value ), $DatatypeExamples.ezdate.value, '2026-09-29' )|wash}</td></tr>
                        <tr><td>ezdatetime</td><td>{'YYYY-MM-DD HH:MM:SS, ISO 8601, or a Unix timestamp.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezdatetime.value ), $DatatypeExamples.ezdatetime.value, '2026-09-29 12:00:00' )|wash}</td></tr>
                        <tr><td>ezselection</td><td>{'An option name, or (the :ids format) its numeric id.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezselection.value ), $DatatypeExamples.ezselection.value, 'Published' )|wash}</td></tr>
                        <tr><td>ezkeyword</td><td>{'Comma-separated keywords.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezkeyword.value ), $DatatypeExamples.ezkeyword.value, 'summer, sale' )|wash}</td></tr>
                        <tr><td>eztags</td><td>{'Comma-separated tag names, each already existing and unambiguous (a tag path, or an ambiguous or missing name, is refused with a warning).'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.eztags.value ), $DatatypeExamples.eztags.value, 'Sports, Running' )|wash}</td></tr>
                        <tr><td>ezxmltext</td><td>{'The HTML the export writes, converted through the ezoe input parser when that extension is active, else as plain paragraphs.'|i18n('design/standard/extract')}</td><td>&lt;p&gt;{cond( is_set( $DatatypeExamples.ezxmltext.value ), $DatatypeExamples.ezxmltext.value, 'Hello, world.' )|wash}&lt;/p&gt;</td></tr>
                        <tr><td>ezimage, ezbinaryfile, ezmedia</td><td>{'A path already inside var/storage (as the export writes it), or an absolute URL of this site - either way, the file is imported.'|i18n('design/standard/extract')}</td><td>{cond( is_set( $DatatypeExamples.ezimage.value ), $DatatypeExamples.ezimage.value, 'var/storage/images/sample/1-1-eng-US/sample.jpg' )|wash}</td></tr>
                        <tr><td>ezobjectrelation, ezobjectrelationlist</td><td>{'Only from an :ids or :remote_ids column (comma-separated); names are ambiguous and refused with a message.'|i18n('design/standard/extract')}</td><td>42,57 {'(as authors-ids)'|i18n('design/standard/extract')}</td></tr>
                        <tr><td>xrowmetadata</td><td>{'Only from the :json column - the same fields the export writes as JSON.'|i18n('design/standard/extract')}</td><td>{ldelim}"title":"...","keywords":["a","b"]{rdelim} {'(as metadata-json)'|i18n('design/standard/extract')}</td></tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_unsupported'|ezurl}{if ezpreference( 'admin_xrowextract_ref_unsupported' )|ne( 'closed' )} open{/if}>
                <summary>{'Datatypes this importer cannot write'|i18n('design/standard/extract')}</summary>
                <ul class="xe-typelist">
                    {foreach $ReferenceUnimportable as $datatype}
                    <li><strong>{$datatype.name|wash}</strong> <code>{$datatype.id|wash}</code><small>{$datatype.reason|wash}</small></li>
                    {/foreach}
                </ul>
                <p class="xe-help">{'Shown in the mapping as not supported, with this reason, and never written - not dropped without a trace.'|i18n('design/standard/extract')}</p>
            </details>

            <details class="xe-remember" data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_ref_packages'|ezurl}{if ezpreference( 'admin_xrowextract_ref_packages' )|ne( 'closed' )} open{/if}>
                <summary>{'Content packages (.ezpkg)'|i18n('design/standard/extract')}</summary>
                {include uri='design:xrowextract/import_package_reference.tpl'}
            </details>
        </section>

    </div>
    </div>

    {* DESIGN: Content END *}</div></div></div>

    <div class="controlbar">
    {* DESIGN: Control bar START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-tc"><div class="box-bl"><div class="box-br">
    <div class="block"></div>
    {* DESIGN: Control bar END *}</div></div></div></div></div></div>
    </div>
</div>
</form>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>
