{ezcss_require( 'xrowextract.css' )}
<form name="eZExtract" method="post" action={'xrowextract/archive'|ezurl} class="xe-archive-form" data-writing="{'Writing the archive …'|i18n('design/standard/extract')|wash}" data-done="{'Downloaded %name, %size KB, in %seconds s'|i18n('design/standard/extract')|wash}">
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">
    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>
    {* DESIGN: Mainline *}<div class="header-mainline"></div>
    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">
    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='archive'}

    {if $error}<p class="xe-error" role="alert">{$error|wash}</p>{/if}

    <div class="xe-cards">

        {* 1. Nodes *}
        <section class="xe-card" aria-labelledby="xe-card-nodes">
            <header class="xe-card-head">
                <span class="xe-step">1</span>
                <div>
                    <h2 id="xe-card-nodes">{'Nodes'|i18n('design/standard/extract')}</h2>
                    <p>{'Everything below each node is exported. Start from a set, then add or remove single nodes.'|i18n('design/standard/extract')}</p>
                </div>
                <span class="xe-count"><strong>{$nodes|count}</strong> {'nodes'|i18n('design/standard/extract')}</span>
            </header>

            <div class="xe-field">
                <span class="xe-label">{'Sets'|i18n('design/standard/extract')}</span>
                <div class="xe-sets">
                    {foreach $node_sets as $id => $set}
                    <button type="submit" name="UseNodeSet" value="{$id|wash}" class="xe-set{if $active_set|eq( $id )} xe-active{/if}" title="{'Nodes'|i18n('design/standard/extract')|wash}: {$set.nodes|implode( ', ' )}">{$set.name|wash}</button>
                    {/foreach}
                </div>
                <p class="xe-help">{'User accounts hold personal data and are only exported when you choose them.'|i18n('design/standard/extract')}</p>
            </div>

            <div class="xe-columns-head">
                <span class="xe-label">{'Selected nodes'|i18n('design/standard/extract')}</span>
                <span class="xe-spacer"></span>
                <input class="button" type="submit" name="BrowseArchiveNode" value="{'Browse for nodes'|i18n('design/standard/extract')}" />
                <input class="button" type="submit" name="ClearNodes" value="{'Remove all'|i18n('design/standard/extract')}"{if $nodes|count|eq( 0 )} disabled{/if} />
            </div>
            {if $nodes|count|eq( 0 )}
            <p class="xe-columns-empty">{'No nodes yet. Choose a set, add a node below, or browse.'|i18n('design/standard/extract')}</p>
            {else}
            <ul class="xe-columns xe-nodes">
                {foreach $nodes as $item}
                <li class="xe-column xe-node-row{if $item.covered_by} xe-covered{/if}">
                    {if $item.node}
                    <span class="xe-node-icon">{$item.node.class_identifier|class_icon( 'small', $item.node.class_name )}</span>
                    <span class="xe-colinfo">
                        <strong><a href={$item.node.url_alias|ezurl}>{$item.node.name|wash}</a></strong>
                        <small>{$item.node.class_name|wash} · {'Node %id'|i18n('design/standard/extract',, hash( '%id', $item.node_id ))}{if $item.node.path_identification_string} · <code>{$item.node.path_identification_string|wash}</code>{/if}</small>
                    </span>
                    <span class="xe-node-count">
                        {if $item.covered_by}{'inside %name, exported with it'|i18n('design/standard/extract',, hash( '%name', $item.covered_by ))|wash}{else}<strong>{$item.count}</strong> {'objects'|i18n('design/standard/extract')}{/if}
                    </span>
                    {else}
                    <span class="xe-node-icon"></span>
                    <span class="xe-colinfo xe-node-missing">{'Node %id does not exist or you may not read it.'|i18n('design/standard/extract',, hash( '%id', $item.node_id ))}</span>
                    <span></span>
                    {/if}
                    <span class="xe-colbuttons"><button type="submit" class="xe-icon xe-remove" name="RemoveNodeID[{$item.node_id}]" value="1" title="{'Remove this node'|i18n('design/standard/extract')|wash}">×</button></span>
                </li>
                {/foreach}
            </ul>
            {/if}

            <details class="xe-picker"{if $nodes|count|eq( 0 )} open{/if}>
                <summary>{'Add a node'|i18n('design/standard/extract')} <small>({$suggestions|count})</small></summary>
                <div class="xe-picker-tools">
                    <input type="search" class="xe-picker-filter" placeholder="{'Filter by name, class or path'|i18n('design/standard/extract')|wash}" autocomplete="off" />
                    <label class="xe-check"><input type="checkbox" class="xe-picker-nonempty" checked /> {'Only nodes with content below'|i18n('design/standard/extract')}</label>
                </div>
                <ul class="xe-picker-list">
                    {foreach $suggestions as $s}
                    <li class="xe-picker-item xe-level-{$s.level}" data-search="{concat( $s.name, ' ', $s.class_name, ' ', $s.path )|downcase|wash}" data-count="{$s.count}">
                        <span class="xe-colinfo">
                            <strong>{$s.name|wash}</strong>
                            <small>{$s.class_name|wash} · {'Node %id'|i18n('design/standard/extract',, hash( '%id', $s.node_id ))}</small>
                        </span>
                        <span class="xe-node-count"><strong>{$s.count}</strong> {'objects'|i18n('design/standard/extract')}</span>
                        <button type="submit" class="button" name="AddNodeID[{$s.node_id}]" value="1"{if $s.selected} disabled{/if}>{if $s.selected}{'Selected'|i18n('design/standard/extract')}{else}{'Add'|i18n('design/standard/extract')}{/if}</button>
                    </li>
                    {/foreach}
                </ul>
            </details>
        </section>

        {* 2. Classes *}
        <section class="xe-card" aria-labelledby="xe-card-classes">
            <header class="xe-card-head">
                <span class="xe-step">2</span>
                <div>
                    <h2 id="xe-card-classes">{'Classes'|i18n('design/standard/extract')}</h2>
                    <p>{'Every class with objects below the nodes is exported, one CSV file each. Untick what you do not need.'|i18n('design/standard/extract')}</p>
                </div>
                <span class="xe-count"><strong class="xe-classes-on">{$selected_class_count}</strong> / {$classes|count} {'classes'|i18n('design/standard/extract')}</span>
            </header>
            <input type="hidden" name="ClassSelection" value="{$class_ids_shown|wash}" />
            {if $classes|count|eq( 0 )}
            <p class="xe-columns-empty">{'The nodes hold no objects you may read.'|i18n('design/standard/extract')}</p>
            {else}
            <div class="xe-picker-tools">
                <input type="search" class="xe-class-filter" placeholder="{'Filter classes'|i18n('design/standard/extract')|wash}" autocomplete="off" />
                <span class="xe-spacer"></span>
                <input class="button xe-classes-all" type="submit" name="SelectAllClasses" value="{'Select all'|i18n('design/standard/extract')}" />
                <input class="button xe-classes-none" type="submit" name="SelectNoClasses" value="{'Select none'|i18n('design/standard/extract')}" />
            </div>
            <ul class="xe-class-grid">
                {foreach $classes as $class}
                <li data-search="{concat( $class.name, ' ', $class.identifier )|downcase|wash}">
                    <label class="xe-class-item">
                        <input type="checkbox" name="ClassIDs[]" value="{$class.id}" data-rows="{$class.count}"{if $class.included} checked{/if} />
                        <span class="xe-colinfo">
                            <strong>{$class.name|wash}</strong>
                            <small><code>{$class.identifier|wash}.csv</code> · {'%columns columns'|i18n('design/standard/extract',, hash( '%columns', $class.columns ))}</small>
                        </span>
                        <span class="xe-node-count"><strong>{$class.count}</strong> {'rows'|i18n('design/standard/extract')}</span>
                    </label>
                </li>
                {/foreach}
            </ul>
            {/if}
        </section>

        {* 3. Format *}
        <section class="xe-card" aria-labelledby="xe-card-archive-format">
            <header class="xe-card-head">
                <span class="xe-step">3</span>
                <div>
                    <h2 id="xe-card-archive-format">{'File format'|i18n('design/standard/extract')}</h2>
                    <p>{'The archive, and how the CSV files in it are written.'|i18n('design/standard/extract')}</p>
                </div>
            </header>
            <div class="xe-grid">
                <div class="xe-field">
                    <span class="xe-label" id="xe-archive-label">{'Archive'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-archive-label">
                        {foreach $formats as $format}
                        <label{if $format.available|not} title="{'Needs %program on the server'|i18n('design/standard/extract',, hash( '%program', $format.needs ))|wash}"{/if}><input type="radio" name="ArchiveFormat" value="{$format.id|wash}"{if $format.id|eq( $state.format )} checked{/if}{if $format.available|not} disabled{/if} /><span>{$format.name|wash}</span></label>
                        {/foreach}
                    </div>
                    <p class="xe-help">{'ZIP opens everywhere with a double click. Formats that are greyed out need a program the server does not have.'|i18n('design/standard/extract')}</p>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="Separator">{'Column separator'|i18n('design/standard/extract')}</label>
                    <div class="xe-presets" data-tab="{$TabNotation|wash}">
                        <button type="button" class="xe-preset" data-sep=",">{'Comma'|i18n('design/standard/extract')} <code>,</code></button>
                        <button type="button" class="xe-preset" data-sep=";">{'Semicolon'|i18n('design/standard/extract')} <code>;</code></button>
                        <button type="button" class="xe-preset" data-sep="tab">{'Tab'|i18n('design/standard/extract')}</button>
                        <input name="Separator" type="text" id="Separator" value="{$separator_display|wash}" maxlength="2" size="3" />
                    </div>
                </div>
                <div class="xe-field">
                    <span class="xe-label" id="xe-a-lines">{'Line endings'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-a-lines">
                        <label><input type="radio" name="LineSeparator" value="win32"{if $state.line|eq( 'win32' )} checked{/if} /><span>Windows</span></label>
                        <label><input type="radio" name="LineSeparator" value="unix"{if $state.line|eq( 'unix' )} checked{/if} /><span>Unix</span></label>
                        <label><input type="radio" name="LineSeparator" value="mac"{if $state.line|eq( 'mac' )} checked{/if} /><span>Mac</span></label>
                    </div>
                    <span class="xe-label" id="xe-a-escape" style="margin-top: .8em">{'Escape'|i18n('design/standard/extract')}</span>
                    <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-a-escape">
                        <label><input type="radio" name="Escape" value="1"{if $state.escape} checked{/if} /><span>{'Quoted'|i18n('design/standard/extract')}</span></label>
                        <label><input type="radio" name="Escape" value="0"{if $state.escape|not} checked{/if} /><span>{'Unquoted'|i18n('design/standard/extract')}</span></label>
                    </div>
                </div>
            </div>
            <p class="xe-help">{'Each file starts with the object id, remote id, main node, parent node, URL alias and dates, then every attribute of its class. manifest.json and README.txt describe the archive.'|i18n('design/standard/extract')}</p>
            {if $allow_password_hashes}
            <label class="xe-check"><input type="checkbox" name="IncludePasswordHashes" value="1"{if $state.password_hashes} checked{/if} /> {'Include password hashes'|i18n('design/standard/extract')} <span class="xe-badge xe-badge-warn">{'sensitive'|i18n('design/standard/extract')}</span></label>
            <p class="xe-help">{'Adds the password hash and its type (md5_password, bcrypt ...) to every class with a user account, for a migration to another system.'|i18n('design/standard/extract')}</p>
            {/if}
        </section>
    </div>

    </div>
    {* DESIGN: Content END *}</div></div></div>

    <div class="controlbar xe-actionbar">
        <div class="xe-actionbar-summary" aria-live="polite">
            <strong class="xe-rows-on">{$total_rows}</strong> {'objects'|i18n('design/standard/extract')} ·
            <strong class="xe-classes-on">{$selected_class_count}</strong> {'files'|i18n('design/standard/extract')} ·
            <strong>{$nodes|count}</strong> {'nodes'|i18n('design/standard/extract')}
        </div>
        <div class="xe-actionbar-buttons">
            <input class="button" type="submit" name="Update" value="{'Update'|i18n('design/standard/extract')}" />
            <input class="defaultbutton xe-download-archive" type="submit" name="DownloadArchive" data-working="{'Writing …'|i18n('design/standard/extract')|wash}" value="{'Download archive'|i18n('design/standard/extract')}"{if or( $nodes|count|eq( 0 ), $selected_class_count|eq( 0 ) )} disabled{/if} />
        </div>
    </div>
</div>
</form>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>
