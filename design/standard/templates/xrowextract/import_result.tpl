{* The dry run preview or the applied result: counts per action, then one row per file row. *}
<section class="xe-card xe-preview" aria-labelledby="xe-card-result">
    <header class="xe-preview-head">
        <div class="xe-preview-title">
            <h2 id="xe-card-result">{if $Applied}{'Import result'|i18n('design/standard/extract')}{else}{'Preview (dry run — nothing was written)'|i18n('design/standard/extract')}{/if}</h2>
        </div>
        <ul class="xe-stats">
            <li class="xe-badge xe-badge-create"><strong>{$Result.counts.create}</strong> {'create'|i18n('design/standard/extract')}</li>
            <li class="xe-badge xe-badge-update"><strong>{$Result.counts.update}</strong> {'update'|i18n('design/standard/extract')}</li>
            <li class="xe-badge xe-badge-unchanged"><strong>{$Result.counts.unchanged}</strong> {'unchanged'|i18n('design/standard/extract')}</li>
            <li class="xe-badge xe-badge-error"><strong>{$Result.counts.error}</strong> {'error'|i18n('design/standard/extract')}</li>
        </ul>
    </header>

    {if $IsSample}
    <p class="xe-note">{if $Applied}{'This was a sample built from the site’s own content: real content was just written (see below).'|i18n('design/standard/extract')}{else}{'This is a sample built from the site’s own content, to try the importer. Applying it writes real content.'|i18n('design/standard/extract')}{/if}</p>
    {/if}
    {if $Result.ezoe|eq( false )}
    <p class="xe-note">{'The ezoe extension is not active on this installation; rich text columns were imported as plain paragraphs, without inline formatting or links.'|i18n('design/standard/extract')}</p>
    {/if}

    <div class="xe-scroll" tabindex="0">
        <table class="xe-table">
            <thead><tr>
                <th class="xe-num">#</th>
                <th>{'Action'|i18n('design/standard/extract')}</th>
                <th>{'Object'|i18n('design/standard/extract')}</th>
                <th>{'Changes'|i18n('design/standard/extract')}</th>
            </tr></thead>
            <tbody>
            {foreach $Result.rows as $row}
            <tr>
                <th class="xe-num" scope="row">{$row.number}</th>
                <td><span class="xe-badge xe-badge-{$row.action}">{cond( $row.action|eq( 'create' ), 'create'|i18n('design/standard/extract'),
                                                                        $row.action|eq( 'update' ), 'update'|i18n('design/standard/extract'),
                                                                        $row.action|eq( 'unchanged' ), 'unchanged'|i18n('design/standard/extract'),
                                                                        $row.action|eq( 'error' ), 'error'|i18n('design/standard/extract'),
                                                                        'skip'|i18n('design/standard/extract') )}</span>
                    {if $row.action|eq( 'error' )}<p class="xe-help">{$row.reason|wash}</p>{/if}</td>
                <td>{if $row.object_id}{if $row.node_id}<a href={concat( 'content/view/full/', $row.node_id )|ezurl} target="_blank" rel="noopener"><code>{$row.object_id}</code></a>{else}<code>{$row.object_id}</code>{/if}{else}—{/if}</td>
                <td>
                    {if $row.changes|count}
                    <table class="xe-table" style="width: 100%">
                        {foreach $row.changes as $change}
                        <tr><td><code>{$change.field|wash}</code></td>
                            <td class="xe-long">{$change.old|wash}</td>
                            <td>&rarr;</td>
                            <td class="xe-long">{$change.new|wash}</td>
                        </tr>
                        {/foreach}
                    </table>
                    {/if}
                </td>
            </tr>
            {/foreach}
            </tbody>
        </table>
    </div>

    {if $Applied}
    <div class="xe-toolbar">
        <span class="xe-spacer"></span>
        <input class="defaultbutton" type="submit" name="NewImport" value="{'Import another file'|i18n('design/standard/extract')}" />
    </div>
    {else}
    <div class="xe-toolbar">
        <span class="xe-spacer"></span>
        {if $IsSample}
        <input class="defaultbutton" type="submit" name="Apply" value="{'Import %count changes (writes real content)'|i18n('design/standard/extract',, hash( '%count', $ApplyCount ))}"
               onclick="return confirm('{'This sample really writes to the site: %count objects will be created or updated. Continue?'|i18n('design/standard/extract',, hash( '%count', $ApplyCount ))|wash}');" />
        {else}
        <input class="defaultbutton" type="submit" name="Apply" value="{'Import %count changes'|i18n('design/standard/extract',, hash( '%count', $ApplyCount ))}" />
        {/if}
    </div>
    {/if}
</section>
