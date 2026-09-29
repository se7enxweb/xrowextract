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

    {* What goes where: the class(es) the rows import into, where new objects are placed, in which language, matched how *}
    <dl class="xe-import-target">
        <div class="xe-import-target-class">
            <dt>{if $ResultClasses|count|gt( 1 )}{'Classes'|i18n('design/standard/extract')}{else}{'Class'|i18n('design/standard/extract')}{/if}</dt>
            <dd>
                {foreach $ResultClasses as $rc}
                <span class="xe-class-chip">
                    <a href={concat( 'class/view/', $rc.id )|ezurl} target="_blank" rel="noopener"><strong>{$rc.name|wash}</strong></a>
                    <code>{$rc.identifier|wash}</code>
                    <small>{'%count rows'|i18n('design/standard/extract',, hash( '%count', $rc.rows ))}{if $ResultClasses|count|gt( 1 )} · {$rc.create} {'create'|i18n('design/standard/extract')}, {$rc.update} {'update'|i18n('design/standard/extract')}, {$rc.unchanged} {'unchanged'|i18n('design/standard/extract')}, {$rc.error} {'error'|i18n('design/standard/extract')}{/if}</small>
                </span>
                {foreachelse}
                <span class="xe-muted">{'No row could be given a class: choose one above, or map a "class" column.'|i18n('design/standard/extract')}</span>
                {/foreach}
            </dd>
        </div>
        <div>
            <dt>{'New objects go below'|i18n('design/standard/extract')}</dt>
            <dd>{if $ParentNode}<a href={concat( 'content/view/full/', $ParentNode.node_id )|ezurl} target="_blank" rel="noopener">{$ParentNode.name|wash}</a>{else}<code>{$ParentNodeID|wash}</code>{/if}
                <small>{'unless a row has its own parent column'|i18n('design/standard/extract')}</small></dd>
        </div>
        <div>
            <dt>{'Language'|i18n('design/standard/extract')}</dt>
            <dd>{$ResultLanguageName|wash} <code>{$Language|wash}</code></dd>
        </div>
        <div>
            <dt>{'Existing objects matched by'|i18n('design/standard/extract')}</dt>
            <dd>{cond( $MatchMode|eq( 'object_id' ), 'Object ID'|i18n('design/standard/extract'), $MatchMode|eq( 'none' ), 'nothing (always create)'|i18n('design/standard/extract'), 'Remote ID'|i18n('design/standard/extract') )}</dd>
        </div>
    </dl>

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
                <td class="xe-result-object">
                    {* The object by name: the matched object's current name, else the name the row gives a new one *}
                    {def $rowName = $row.object_name}
                    {if $rowName|eq( '' )}{foreach $row.changes as $change}{if $change.field|eq( 'name' )}{set $rowName = $change.new}{/if}{/foreach}{/if}
                    {if $row.node_id}<a href={concat( 'content/view/full/', $row.node_id )|ezurl} target="_blank" rel="noopener"><strong>{if $rowName|ne( '' )}{$rowName|wash}{else}{'Object %id'|i18n('design/standard/extract',, hash( '%id', $row.object_id ))}{/if}</strong></a>
                    {elseif $rowName|ne( '' )}<strong>{$rowName|wash}</strong>
                    {else}—{/if}
                    <small>
                        {if $row.object_id}#{$row.object_id}{elseif $row.action|eq( 'create' )}{'new'|i18n('design/standard/extract')}{/if}
                        {if $row.class_identifier} · <code>{$row.class_identifier|wash}</code>{/if}
                    </small>
                    {undef $rowName}
                </td>
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
