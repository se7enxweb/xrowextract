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
                    <p>{'A CSV or JSON file written by the export views, any column set.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            {if $UploadError}<p class="xe-error">{$UploadError|wash}</p>{/if}
            {if $ParseError}<p class="xe-error">{$ParseError|wash}</p>{/if}

            {if $HasFile|not}
            <div class="xe-field">
                <label class="xe-label" for="xe-file">{'Choose a file'|i18n('design/standard/extract')}</label>
                <input type="file" name="ImportFile" id="xe-file" accept=".csv,.json,text/csv,application/json" />
                <p class="xe-help">{'CSV: separator and encoding are detected automatically (UTF-8 with or without a BOM); you can still change the separator once it is uploaded. JSON: an array of objects, one per row, as XrowExtractWriter writes it.'|i18n('design/standard/extract')}</p>
                <input class="defaultbutton" type="submit" name="Upload" value="{'Upload'|i18n('design/standard/extract')}" />
            </div>
            {else}
            <div class="xe-node">
                <span class="xe-node-text">
                    <span class="xe-node-name">{$UploadedName|wash}</span>
                    <span class="xe-node-meta">{$FileRowCount} {'rows'|i18n('design/standard/extract')} · {$FileHeader|count} {'columns'|i18n('design/standard/extract')} · {$ImportFormat|wash}</span>
                </span>
                <input class="button" type="submit" name="RemoveFile" value="{'Remove'|i18n('design/standard/extract')}" />
            </div>
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
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                    </select>
                    <input class="button" type="submit" name="DownloadTemplate" value="{'Download a template for this class'|i18n('design/standard/extract')}" />
                </div>
                <p class="xe-help">{'An empty file with every column the import understands for the class (the Migration column set): fill in rows and import it. An export with the Migration set is the same file with the rows filled in.'|i18n('design/standard/extract')}</p>
            </div>
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
