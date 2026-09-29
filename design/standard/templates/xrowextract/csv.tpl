{ezcss_require( 'xrowextract.css' )}
<form name="eZExtract" method="post" action={'xrowextract/csv'|ezurl}>

{def $types = array()
     $subtree_node = fetch( 'content', 'node', hash( 'node_id', $Subtree ) )
     $class_attributes = fetch( 'class', 'attribute_list', hash( 'class_id', $Class_id ) )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='csv'}
    <div class="xe-cards">

        {* 1. What is exported *}
        <section class="xe-card" aria-labelledby="xe-card-data">
            <header class="xe-card-head">
                <span class="xe-step">1</span>
                <div>
                    <h2 id="xe-card-data">{'Data selection'|i18n('design/standard/extract')}</h2>
                    <p>{'Which objects become rows.'|i18n('design/standard/extract')}</p>
                </div>
                <span class="xe-count" title="{'Rows the file will hold with these settings'|i18n('design/standard/extract')|wash}"><strong>{$export_rows}</strong> {'rows'|i18n('design/standard/extract')}</span>
            </header>

            {if $has_prefilledata}
            <div class="xe-prefilled">
                <p>{'%count objects were handed over by another view. They are exported as they are; node, depth and limit do not apply.'|i18n('design/standard/extract',, hash( '%count', $prefilled_count ))}</p>
                <input class="button" type="submit" name="RemoveData" value="{'Remove current pre filled selection'|i18n('design/standard/extract')}" />
            </div>
            {else}
            <div class="xe-field">
                <span class="xe-label" id="xe-scope-label">{'Scope'|i18n('design/standard/extract')}</span>
                <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-scope-label">
                    <label title="{'The direct children of the node'|i18n('design/standard/extract')|wash}"><input type="radio" name="Scope" value="list" class="xe-autosubmit"{if $Scope|eq( 'list' )} checked{/if} /><span>{'Below a node'|i18n('design/standard/extract')}</span></label>
                    <label title="{'Everything below the node, at every depth'|i18n('design/standard/extract')|wash}"><input type="radio" name="Scope" value="tree" class="xe-autosubmit"{if $Scope|eq( 'tree' )} checked{/if} /><span>{'Below a node tree'|i18n('design/standard/extract')}</span></label>
                    <label title="{'Every object of the class, wherever it is placed'|i18n('design/standard/extract')|wash}"><input type="radio" name="Scope" value="all" class="xe-autosubmit"{if $Scope|eq( 'all' )} checked{/if} /><span>{'Whole site'|i18n('design/standard/extract')}</span></label>
                </div>
                <p class="xe-help">{if $Scope|eq( 'all' )}{'Every object of the class, wherever it is placed, one row each at its main location. The node and main locations below do not apply.'|i18n('design/standard/extract')}{elseif $Scope|eq( 'list' )}{'The direct children of the node below.'|i18n('design/standard/extract')}{else}{'Everything below the node, at every depth.'|i18n('design/standard/extract')}{/if}</p>
            </div>
            <div class="xe-field{if $Scope|eq( 'all' )} xe-inactive{/if}">
                <span class="xe-label">{'Node'|i18n('design/standard/extract')}</span>
                <div class="xe-node">
                    {if $subtree_node}
                    <span class="xe-node-icon">{$subtree_node.class_identifier|class_icon( 'small', $subtree_node.class_name )}</span>
                    <span class="xe-node-text">
                        <a href={$subtree_node.url_alias|ezurl} class="xe-node-name">{$subtree_node.name|wash}</a>
                        <span class="xe-node-path">{$subtree_node.path_identification_string|wash}</span>
                        <span class="xe-node-meta">{$subtree_node.class_name|wash} · {'Node %id'|i18n('design/standard/extract',, hash( '%id', $subtree_node.node_id ))} · {'%count sub items'|i18n('design/standard/extract',, hash( '%count', $subtree_node.children_count ))}</span>
                    </span>
                    {else}
                    <span class="xe-node-text xe-node-missing">{'Node %id does not exist or you may not read it. Choose another node.'|i18n('design/standard/extract',, hash( '%id', $Subtree ))}</span>
                    {/if}
                    <input class="button" type="submit" name="BrowseSubtree" value="{'Change'|i18n('design/standard/extract')}" />
                </div>
                <input name="Subtree" type="hidden" id="Subtree" value="{$Subtree|wash}" />
            </div>
            {/if}

            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-class">{'Class'|i18n('design/standard/extract')}</label>
                    <div class="xe-inline">
                        <select name="Class_id" id="xe-class" class="xe-autosubmit"{if $has_prefilledata} disabled{/if}>
                            {if $has_prefilledata}
                            {foreach $ClassChoices as $class}
                            <option value="{$class.id}"{if $class.id|eq( $Class_id )} selected{/if}>{$class.name|wash}</option>
                            {/foreach}
                            {else}
                            <optgroup label="{'In this selection'|i18n('design/standard/extract')|wash}">
                            {foreach $ClassChoices as $class}{if $class.count|gt( 0 )}
                            <option value="{$class.id}"{if $class.id|eq( $Class_id )} selected{/if}>{$class.name|wash} ({$class.count})</option>
                            {/if}{/foreach}
                            </optgroup>
                            <optgroup label="{'Not in this selection'|i18n('design/standard/extract')|wash}">
                            {foreach $ClassChoices as $class}{if $class.count|gt( 0 )|not}
                            <option value="{$class.id}"{if $class.id|eq( $Class_id )} selected{/if}>{$class.name|wash} (0)</option>
                            {/if}{/foreach}
                            </optgroup>
                            {/if}
                        </select>
                        <input class="button xe-update" name="Update" type="submit" value="{'Update'|i18n('design/standard/extract')}" title="{'Load the columns of this class'|i18n('design/standard/extract')|wash}" />
                    </div>
                    <p class="xe-help">{'%count objects of this class in the selection. The list shows the number for every class.'|i18n('design/standard/extract',, hash( '%count', $max_count ))}</p>
                    {if $ChosenClass}<p class="xe-help xe-meta"><code>{$ChosenClass.identifier|wash}</code> · {'%count attributes'|i18n('design/standard/extract',, hash( '%count', $ChosenClass.attributes ))}</p>{/if}
                </div>

                {if $has_prefilledata|not}
                <div class="xe-field{if $Scope|eq( 'all' )} xe-inactive{/if}">
                    <span class="xe-label">{'Locations'|i18n('design/standard/extract')}</span>
                    <label class="xe-check"><input type="checkbox" name="mainnodeonly" value="1"{if $Mainnodeonly|eq( '1' )} checked{/if} /> {'Main locations only'|i18n('design/standard/extract')}</label>
                    <p class="xe-help">{'An object with several locations is then one row, not one per location.'|i18n('design/standard/extract')}</p>
                </div>

                <div class="xe-field">
                    <span class="xe-label">{'Rows'|i18n('design/standard/extract')}</span>
                    <div class="xe-range">
                        <label>{'Skip'|i18n('design/standard/extract')} <input name="Offset" type="number" min="0" step="1" id="Offset" value="{$Offset|wash}" inputmode="numeric" /></label>
                        <label>{'Take at most'|i18n('design/standard/extract')} <input name="Limit" type="number" min="0" step="1" id="Limit" value="{$Limit|wash}" inputmode="numeric" /></label>
                    </div>
                    <p class="xe-help">{'0 takes every row. Large exports: take them in batches, for example 5000 rows, skipping 0, 5000, 10000 ...'|i18n('design/standard/extract')}</p>
                </div>
                {/if}
            </div>
        </section>

        {* 2. How the file is written *}
        <section class="xe-card" aria-labelledby="xe-card-format">
            <header class="xe-card-head">
                <span class="xe-step">2</span>
                <div>
                    <h2 id="xe-card-format">{'File format'|i18n('design/standard/extract')}</h2>
                    <p>{'How the cells and rows are separated.'|i18n('design/standard/extract')}</p>
                </div>
            </header>
            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="Separator">{'Column separator'|i18n('design/standard/extract')}</label>
                    <div class="xe-presets" data-tab="{$TabNotation|wash}">
                        <button type="button" class="xe-preset" data-sep=",">{'Comma'|i18n('design/standard/extract')} <code>,</code></button>
                        <button type="button" class="xe-preset" data-sep=";">{'Semicolon'|i18n('design/standard/extract')} <code>;</code></button>
                        <button type="button" class="xe-preset" data-sep="tab">{'Tab'|i18n('design/standard/extract')}</button>
                        <button type="button" class="xe-preset" data-sep="|">{'Pipe'|i18n('design/standard/extract')} <code>|</code></button>
                        <input name="Separator" type="text" id="Separator" value="{$Separator|wash}" maxlength="2" size="3" aria-describedby="xe-sep-help" />
                    </div>
                    <p class="xe-help" id="xe-sep-help">{'Excel in German and many other locales expects a semicolon. One character; %tab is a tab.'|i18n('design/standard/extract',, hash( '%tab', concat( '<code>', $TabNotation|wash, '</code>' ) ))}</p>
                </div>

                <div class="xe-field">
                    <span class="xe-label" id="xe-lines-label">{'Line endings'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-lines-label">
                        {foreach $LineSeparatorArray as $line}
                        <label><input type="radio" name="LineSeparator" value="{$line.id|wash}"{if $line.id|eq( $LineSeparator )} checked{/if} /><span>{$line.name|wash}</span></label>
                        {/foreach}
                    </div>
                    <p class="xe-help">{'Windows (CRLF) is what RFC 4180 and Excel expect; every current program reads all three.'|i18n('design/standard/extract')}</p>
                </div>

                <div class="xe-field">
                    <span class="xe-label" id="xe-escape-label">{'Escape'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-escape-label">
                        <label><input type="radio" name="Escape" value="1"{if $Escape} checked{/if} /><span>{'Quoted'|i18n('design/standard/extract')}</span></label>
                        <label><input type="radio" name="Escape" value="0"{if $Escape|not} checked{/if} /><span>{'Unquoted'|i18n('design/standard/extract')}</span></label>
                    </div>
                    <p class="xe-help">{'Recommended. Quoted cells may hold the separator, quotes and line breaks (%rfc). Unquoted removes line breaks, and a separator inside a value shifts the columns.'|i18n( 'design/standard/extract',, hash( '%rfc', '<a href="https://www.rfc-editor.org/rfc/rfc4180" target="_blank" rel="noopener">RFC 4180</a>' ) )}</p>
                </div>
            </div>
            <div class="xe-sample" aria-live="polite">
                <span class="xe-label">{'A row will look like this'|i18n('design/standard/extract')}</span>
                <code class="xe-sample-line" data-value="{'Hello, "world"'|i18n('design/standard/extract')|wash}"
                      data-columns="{foreach $Attributes as $item max 3}{$item.exportname|wash}{delimiter}&#10;{/delimiter}{/foreach}"></code>
            </div>
        </section>

        {* 3. The columns *}
        <section class="xe-card" aria-labelledby="xe-card-columns">
            <header class="xe-card-head">
                <span class="xe-step">3</span>
                <div>
                    <h2 id="xe-card-columns">{'Columns'|i18n('design/standard/extract')}</h2>
                    <p>{'Add the class attributes and special columns the file should have; order and names are set in the list below.'|i18n('design/standard/extract')}</p>
                </div>
                <span class="xe-count"><strong>{$Attributes|count}</strong> {'columns'|i18n('design/standard/extract')}</span>
            </header>
            <div class="xe-field">
                <label class="xe-label" for="xe-add">{'Add a column'|i18n('design/standard/extract')}</label>
                <div class="xe-inline">
                    <select name="AddAttributeID" id="xe-add">
                        <optgroup label="{'Class attributes'|i18n('design/standard/extract')|wash}">
                        {foreach $class_attributes as $attribute}
                            <option value="{$attribute.id|wash}">{$attribute.name|wash} ({$attribute.identifier|wash}) · {if is_set( $AttributeMeta[$attribute.identifier] )}{$AttributeMeta[$attribute.identifier].datatype_name|wash}{else}{$attribute.data_type_string|wash}{/if}{if $ExportableDatatypes|contains( $attribute.data_type_string )|not} – {'empty: no export handler for %type'|i18n('design/standard/extract',, hash( '%type', $attribute.data_type_string ))|wash}{/if}</option>
                        {/foreach}
                        </optgroup>
                        <optgroup label="{'Special columns'|i18n('design/standard/extract')|wash}">
                        {foreach $ExtraAttributes as $extra}
                            <option value="{$extra.id|wash}">{$extra.name|wash}</option>
                        {/foreach}
                        </optgroup>
                    </select>
                    <input class="button" name="AddAttribute" type="submit" value="{'Add attribute'|i18n('design/standard/extract')}" />
                    <input class="button" name="AddAllAttributes" type="submit" value="{'Add all class attributes'|i18n('design/standard/extract')}" title="{'Adds every attribute of the class that is not a column yet'|i18n('design/standard/extract')|wash}" />
                </div>
            </div>
            <input type="hidden" name="AttributesClassID" value="{$Class_id}" />
            <div class="xe-columns-head">
                <span class="xe-label">{'Columns of the file'|i18n('design/standard/extract')}</span>
                <span class="xe-columns-hint">{'Drag a column, or use the arrows, to change the order; the names are the header of the file.'|i18n('design/standard/extract')}</span>
                <span class="xe-spacer"></span>
                <input class="button" type="submit" name="ResetAttributes" value="{'Reset to all class attributes'|i18n('design/standard/extract')}" title="{'Every attribute of the class, in class order, with its identifier as name'|i18n('design/standard/extract')|wash}" />
                <input class="button xe-remove-all" type="submit" name="RemoveAllAttributes" value="{'Remove all'|i18n('design/standard/extract')}"{if $Attributes|count|eq( 0 )} disabled{/if} />
            </div>
            <p class="xe-columns-empty"{if $Attributes|count|gt( 0 )} hidden{/if}>{'No columns yet. Add a column above, or reset to all class attributes.'|i18n('design/standard/extract')}</p>
            <ol class="xe-columns" data-up="{'Move up'|i18n('design/standard/extract')|wash}" data-down="{'Move down'|i18n('design/standard/extract')|wash}">
                {foreach $Attributes as $index => $item}
                <li class="xe-column">
                    <span class="xe-handle" title="{'Drag to move'|i18n('design/standard/extract')|wash}" aria-hidden="true">⠿</span>
                    <span class="xe-colpos">{$index|sum( 1 )}</span>
                    <span class="xe-colinfo">
                        <strong>{$item.name|wash}</strong>
                        {if is_set( $AttributeMeta[$item.id] )}{def $meta = $AttributeMeta[$item.id]}
                        <small class="xe-meta">
                            {if $meta.special}<span class="xe-badge">{'Special column'|i18n('design/standard/extract')}</span>{if $meta.sensitive} <span class="xe-badge xe-badge-warn" title="{'Handle with care: it lets anyone try the passwords offline'|i18n('design/standard/extract')|wash}">{'sensitive'|i18n('design/standard/extract')}</span>{/if}{else}<code>{$item.id|wash}</code>
                            <span class="xe-type" title="{$meta.datatype|wash}">{$meta.datatype_name|wash}</span>{/if}
                            {if $meta.required}<span class="xe-flag" title="{'Required'|i18n('design/standard/extract')|wash}">{'required'|i18n('design/standard/extract')}</span>{/if}
                            {if $meta.translatable|not}{if $meta.special|not}<span class="xe-flag" title="{'Not translatable'|i18n('design/standard/extract')|wash}">{'not translatable'|i18n('design/standard/extract')}</span>{/if}{/if}
                            {if $meta.searchable}<span class="xe-flag">{'searchable'|i18n('design/standard/extract')}</span>{/if}
                            {if $meta.collector}<span class="xe-flag">{'information collector'|i18n('design/standard/extract')}</span>{/if}
                            {if $meta.exportable}<span class="xe-cell" title="{'What the cell holds'|i18n('design/standard/extract')|wash}">→ {$meta.cell|wash}</span>{else}<span class="xe-badge xe-badge-warn">{'empty: no export handler for %type'|i18n('design/standard/extract',, hash( '%type', $meta.datatype ))|wash}</span>{/if}
                        </small>
                        {undef $meta}{else}
                        <small class="xe-meta"><code>{$item.id|wash}</code> <span class="xe-badge xe-badge-warn">{'not an attribute of this class: empty'|i18n('design/standard/extract')}</span></small>
                        {/if}
                    </span>
                    <input name="Attributes[{$index}][id]" type="hidden" value="{$item.id|wash}" />
                    <input name="Attributes[{$index}][name]" type="hidden" value="{$item.name|wash}" />
                    <label class="xe-colname-edit">
                        <span class="xe-visually-hidden">{'Column name in the file'|i18n('design/standard/extract')}</span>
                        <input name="Attributes[{$index}][exportname]" type="text" value="{$item.exportname|wash}" spellcheck="false" />
                    </label>
                    <span class="xe-colbuttons">
                        <button type="submit" class="xe-icon xe-up" name="MoveAttributeUp[{$index}]" value="1" title="{'Move up'|i18n('design/standard/extract')|wash}"{if $index|eq( 0 )} disabled{/if}>↑</button>
                        <button type="submit" class="xe-icon xe-down" name="MoveAttributeDown[{$index}]" value="1" title="{'Move down'|i18n('design/standard/extract')|wash}"{if $index|eq( $Attributes|count|sub( 1 ) )} disabled{/if}>↓</button>
                        <button type="submit" class="xe-icon xe-remove" name="RemoveAttribute[{$index}]" value="1" title="{'Remove this column'|i18n('design/standard/extract')|wash}" aria-label="{'Remove %name'|i18n('design/standard/extract',, hash( '%name', $item.name ))|wash}">×</button>
                    </span>
                </li>
                {/foreach}
            </ol>
        </section>
    </div>
    </div>

    <div id="xe-preview-slot" class="xe-preview-slot">{if is_set( $preview )}{include uri='design:xrowextract/csv_preview.tpl'}{/if}</div>

    {* DESIGN: Content END *}</div></div></div>

    <div class="controlbar xe-actionbar">
        <div class="xe-actionbar-summary" aria-live="polite">
            <strong>{$export_rows}</strong> {'rows'|i18n('design/standard/extract')} ·
            <strong class="xe-column-total">{$Attributes|count}</strong> {'columns'|i18n('design/standard/extract')}
            {if $Scope|eq( 'all' )}· <span class="xe-actionbar-node">{'Whole site'|i18n('design/standard/extract')}</span>{elseif $subtree_node}· <span class="xe-actionbar-node">{$subtree_node.name|wash}</span>{/if}
        </div>
        <div class="xe-actionbar-buttons">
            <input class="button" name="Preview" type="submit" value="{'Preview'|i18n('design/standard/extract')}" title="{'See the rows as a spreadsheet will show them, before downloading'|i18n('design/standard/extract')|wash}" />
            <input class="defaultbutton" name="Download" type="submit" value="{'Download CSV'|i18n('design/standard/extract')}" />
        </div>
    </div>
</div>

</form>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>