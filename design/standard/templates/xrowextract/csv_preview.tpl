{* The spreadsheet preview: the export file read back as a spreadsheet would, before downloading it. *}
{def $wrap = ezpreference( 'admin_xrowextract_preview_wrap' )|eq( '1' )}
<section class="xe-preview" id="xe-preview" aria-label="{'Preview'|i18n( 'design/standard/extract' )}"
         data-preference-url={'/user/preferences/set_and_exit/admin_xrowextract_preview_wrap'|ezurl}
         data-copied="{'Copied'|i18n( 'design/standard/extract' )|wash}"
         data-matches="{'%shown of %all rows match'|i18n( 'design/standard/extract' )|wash}">
    <header class="xe-preview-head">
        <div class="xe-preview-title">
            <h2>{'Preview'|i18n( 'design/standard/extract' )}</h2>
            <span class="xe-file" title="{'File name of the download'|i18n( 'design/standard/extract' )|wash}">{$preview.file|wash}</span>
        </div>
        <ul class="xe-stats">
            <li><strong>{$preview.shown}</strong> {'of'|i18n( 'design/standard/extract' )} <strong>{$preview.total}</strong> {'rows'|i18n( 'design/standard/extract' )}</li>
            <li><strong>{$preview.columns}</strong> {'columns'|i18n( 'design/standard/extract' )}</li>
            <li>{'Separator'|i18n( 'design/standard/extract' )} <code>{$preview.separator|wash}</code></li>
            <li>{if $preview.escape}{'Quoted cells'|i18n( 'design/standard/extract' )}{else}{'Unquoted cells'|i18n( 'design/standard/extract' )}{/if}</li>
            <li title="{'Estimated from the rows shown'|i18n( 'design/standard/extract' )|wash}">≈ {$preview.estimated_kb} KB</li>
            <li>{$preview.milliseconds} ms</li>
        </ul>
    </header>

    {if $preview.mismatch}
    <p class="xe-note xe-note-bad" role="alert">{'%count rows do not have as many cells as the header. A spreadsheet would shift their columns: a value probably contains the separator. Switch "Escape" to "Yes" or choose another separator.'|i18n( 'design/standard/extract',, hash( '%count', $preview.mismatch ) )}</p>
    {/if}
    {if $preview.formulas}
    <p class="xe-note">{'%count cells start like a spreadsheet formula; they are exported with a leading apostrophe so they open as text.'|i18n( 'design/standard/extract',, hash( '%count', $preview.formulas ) )}</p>
    {/if}

    {if and( is_set( $preview_sample ), $preview_sample )}
    <details class="xe-picker xe-output-sample" open>
        <summary>{'The first rows as %type'|i18n( 'design/standard/extract',, hash( '%type', $preview_sample_format ) )}</summary>
        <pre class="xe-sample-line">{$preview_sample|wash}</pre>
    </details>
    {/if}
    <div class="xe-toolbar">
        <label class="xe-filter">
            <span class="xe-visually-hidden">{'Filter rows'|i18n( 'design/standard/extract' )}</span>
            <input type="search" placeholder="{'Filter rows'|i18n( 'design/standard/extract' )|wash}" autocomplete="off" />
        </label>
        <span class="xe-match" aria-live="polite"></span>
        <label class="xe-toggle"><input type="checkbox" class="xe-wrap-toggle"{if $wrap} checked{/if} /> {'Wrap cells'|i18n( 'design/standard/extract' )}</label>
        <label class="xe-rows">{'Rows'|i18n( 'design/standard/extract' )}
            <select name="PreviewRows">
                {foreach $PreviewRowChoices as $choice}
                <option value="{$choice}"{if $choice|eq( $PreviewRows )} selected{/if}>{$choice}</option>
                {/foreach}
            </select>
        </label>
        <span class="xe-spacer"></span>
        <button type="button" class="button xe-copy" title="{'Copy the rows shown, tab separated, to paste into a spreadsheet'|i18n( 'design/standard/extract' )|wash}">{'Copy'|i18n( 'design/standard/extract' )}</button>
        <input class="button" type="submit" name="Preview" value="{'Refresh'|i18n( 'design/standard/extract' )}" />
        <input class="defaultbutton" type="submit" name="Download" value="{'Download'|i18n( 'design/standard/extract' )}" />
        <button type="button" class="button xe-close" aria-label="{'Close preview'|i18n( 'design/standard/extract' )|wash}">×</button>
    </div>

    {if $preview.shown|eq( 0 )}
    <p class="xe-empty-state">{'No objects match this selection. Check the node, the class and the depth.'|i18n( 'design/standard/extract' )}</p>
    {else}
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table{if $wrap} xe-wrap{/if}">
            <thead>
                <tr class="xe-letters">
                    <th class="xe-num" scope="col"></th>
                    {foreach $preview.header as $index => $column}
                    <th scope="col" data-col="{$index}" title="{'Sort the preview by this column'|i18n( 'design/standard/extract' )|wash}"><button type="button" class="xe-sort">{$column.letters}</button></th>
                    {/foreach}
                </tr>
                <tr>
                    <th class="xe-num" scope="col">#</th>
                    {foreach $preview.header as $index => $column}
                    <th scope="col" data-col="{$index}">
                        <span class="xe-colname">{$column.name|wash}</span>
                        {if is_set( $PreviewColumns[$index] )}<span class="xe-colsource">{$PreviewColumns[$index].name|wash}</span>{if is_set( $AttributeMeta[$PreviewColumns[$index].id] )}<span class="xe-coltype" title="{$AttributeMeta[$PreviewColumns[$index].id].datatype|wash}">{$AttributeMeta[$PreviewColumns[$index].id].datatype_name|wash} → {$AttributeMeta[$PreviewColumns[$index].id].cell|wash}</span>{/if}{/if}
                        <span class="xe-fill" title="{'%percent % of the rows shown have a value'|i18n( 'design/standard/extract',, hash( '%percent', $column.fill ) )|wash}"><span style="width: {$column.fill}%"></span></span>
                    </th>
                    {/foreach}
                </tr>
            </thead>
            <tbody>
                {foreach $preview.rows as $row}
                <tr{if $row.mismatch} class="xe-mismatch" title="{'%cells cells, the header has %columns'|i18n( 'design/standard/extract',, hash( '%cells', $row.count, '%columns', $preview.columns ) )|wash}"{/if}>
                    <th class="xe-num" scope="row">{$row.number}</th>
                    {foreach $row.cells as $cell}
                    <td class="{if $cell.empty}xe-blank{/if}{if $cell.formula} xe-formula{/if}{if $cell.long} xe-long{/if}">{$cell.text|wash}</td>
                    {/foreach}
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    <p class="xe-hint">{'Click a cell to see all of it, a column letter to sort the preview. The download holds every row, in export order.'|i18n( 'design/standard/extract' )}</p>
    {/if}
</section>
{undef $wrap}
