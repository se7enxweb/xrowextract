{ezcss_require( 'xrowextract.css' )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{if $Mode|eq( 'packages' )}{'Compare %a with %b'|i18n('design/standard/extract',, hash( '%a', $PackageName, '%b', $OtherName ))|wash}{else}{'Compare %name with this site'|i18n('design/standard/extract',, hash( '%name', $PackageName ))|wash}{/if}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='package'}

    <p><a href={concat( 'xrowextract/package/', $PackageName )|ezurl}>&laquo; {'Back to %name'|i18n('design/standard/extract',, hash( '%name', $PackageName ))|wash}</a>
        &middot; <a href={concat( 'xrowextract/browse/', $PackageName, '/', 0 )|ezurl}>{'browse its files'|i18n('design/standard/extract')}</a></p>

    <div class="xe-cards">

        {* 1. What is compared *}
        <section class="xe-card" aria-labelledby="xe-card-compare-what">
            <header class="xe-card-head">
                <span class="xe-step">1</span>
                <div>
                    <h2 id="xe-card-compare-what">{'What is compared'|i18n('design/standard/extract')}</h2>
                    <p>{if $Mode|eq( 'packages' )}{'Classes and objects matched by remote id, read from the two packages alone; nothing on the site is looked at.'|i18n('design/standard/extract')}{else}{'The package against this site: what installing it would create or change, from the same dry run as the Package tab. Nothing is written.'|i18n('design/standard/extract')}{/if}</p>
                </div>
            </header>
            <form method="get" action={concat( 'xrowextract/compare/', $PackageName )|ezurl} class="xe-field">
                <label class="xe-label" for="xe-compare-with">{'Compare %name with'|i18n('design/standard/extract',, hash( '%name', $PackageName ))|wash}</label>
                <div class="xe-inline">
                    <select name="with" id="xe-compare-with">
                        <option value=""{if $OtherName|eq( '' )} selected{/if}>{'this site'|i18n('design/standard/extract')}</option>
                        {foreach $OtherPackages as $otherPackage}
                        <option value="{$otherPackage.name|wash}"{if $otherPackage.name|eq( $OtherName )} selected{/if}>{$otherPackage.name|wash}{if $otherPackage.version} ({$otherPackage.version|wash}){/if}</option>
                        {/foreach}
                    </select>
                    <input class="button" type="submit" value="{'Compare'|i18n('design/standard/extract')}" />
                </div>
            </form>
            <form method="post" action="" class="xe-inline xe-inspection-checked">
                <small>{'Compared at %time'|i18n('design/standard/extract',, hash( '%time', $CheckedAt|l10n( shortdatetime ) ))}{if $Cached} · {'kept for 15 minutes while you page through it'|i18n('design/standard/extract')}{/if}</small>
                <input class="button" type="submit" name="RecheckComparison" value="{'Check again'|i18n('design/standard/extract')}" />
            </form>
        </section>

        {* 2. Summary *}
        <section class="xe-card" aria-labelledby="xe-card-compare-summary">
            <header class="xe-card-head">
                <span class="xe-step">2</span>
                <div>
                    <h2 id="xe-card-compare-summary">{'Summary'|i18n('design/standard/extract')}</h2>
                    <p>{if $Mode|eq( 'packages' )}{'Added: only in %b. Removed: only in %a. Changed: in both, with differences.'|i18n('design/standard/extract',, hash( '%a', $PackageName, '%b', $OtherName ))|wash}{else}{'Pick a count to list only those objects.'|i18n('design/standard/extract')}{/if}</p>
                </div>
            </header>
            {if $Mode|eq( 'packages' )}
            <div class="xe-scroll" tabindex="0">
            <table class="xe-table xe-compare-counts">
                <thead><tr><th></th><th>{'Added'|i18n('design/standard/extract')}</th><th>{'Removed'|i18n('design/standard/extract')}</th><th>{'Changed'|i18n('design/standard/extract')}</th><th>{'The same'|i18n('design/standard/extract')}</th></tr></thead>
                <tbody>
                <tr><th>{'Classes'|i18n('design/standard/extract')}</th><td>{$Comparison.counts.classes.added}</td><td>{$Comparison.counts.classes.removed}</td><td>{$Comparison.counts.classes.changed}</td><td>{$Comparison.counts.classes.unchanged}</td></tr>
                <tr><th>{'Objects'|i18n('design/standard/extract')}</th>
                    <td><a href="?change=added">{$Comparison.counts.objects.added}</a></td>
                    <td><a href="?change=removed">{$Comparison.counts.objects.removed}</a></td>
                    <td><a href="?change=changed">{$Comparison.counts.objects.changed}</a></td>
                    <td>{$Comparison.counts.objects.unchanged}</td></tr>
                </tbody>
            </table>
            </div>
            {else}
            <ul class="xe-stats">
                <li class="xe-badge xe-badge-create"><strong>{$SiteCounts.classes_create}</strong> {'classes: create'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-update"><strong>{$SiteCounts.classes_update}</strong> {'classes: update'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-create"><a href="?change=create{if $ParentNodeID}&amp;parent={$ParentNodeID}{/if}"><strong>{$SiteCounts.objects_create}</strong> {'objects: create'|i18n('design/standard/extract')}</a></li>
                <li class="xe-badge xe-badge-update"><a href="?change=update{if $ParentNodeID}&amp;parent={$ParentNodeID}{/if}"><strong>{$SiteCounts.objects_update}</strong> {'objects: update'|i18n('design/standard/extract')}</a></li>
                <li class="xe-badge xe-badge-unchanged"><a href="?change=unchanged{if $ParentNodeID}&amp;parent={$ParentNodeID}{/if}"><strong>{$SiteCounts.objects_unchanged}</strong> {'objects: unchanged'|i18n('design/standard/extract')}</a></li>
                <li class="xe-badge xe-badge-error"><a href="?change=class_missing{if $ParentNodeID}&amp;parent={$ParentNodeID}{/if}"><strong>{$SiteCounts.objects_class_missing}</strong> {'objects: class missing'|i18n('design/standard/extract')}</a></li>
            </ul>
            {include uri='design:xrowextract/package_datatype_check.tpl' missing=$MissingDatatypes}
            {/if}
        </section>

        {* 3. Classes *}
        <section class="xe-card" aria-labelledby="xe-card-compare-classes">
            <header class="xe-card-head">
                <span class="xe-step">3</span>
                <div>
                    <h2 id="xe-card-compare-classes">{'Classes'|i18n('design/standard/extract')}</h2>
                    <p>{if $Mode|eq( 'packages' )}{'Name and attributes: added, removed, or with another datatype.'|i18n('design/standard/extract')}{else}{'A new class, or the attributes that differ from the site’s class of the same remote id or identifier.'|i18n('design/standard/extract')}{/if}</p>
                </div>
            </header>
            {if $ClassRows|count|eq( 0 )}
            <p class="xe-columns-empty">{'No class differs.'|i18n('design/standard/extract')}</p>
            {else}
            <div class="xe-scroll" tabindex="0">
                <table class="xe-table xe-compare-table">
                    <thead><tr><th>{'Change'|i18n('design/standard/extract')}</th><th>{'Identifier'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Differences'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    {foreach $ClassRows as $classRow}
                    {if $Mode|eq( 'packages' )}
                    <tr>
                        <td><span class="xe-badge xe-badge-{cond( $classRow.change|eq( 'added' ), 'create', $classRow.change|eq( 'removed' ), 'error', 'update' )}">{cond( $classRow.change|eq( 'added' ), 'added'|i18n('design/standard/extract'), $classRow.change|eq( 'removed' ), 'removed'|i18n('design/standard/extract'), 'changed'|i18n('design/standard/extract') )}</span></td>
                        <td><code>{$classRow.identifier|wash}</code></td>
                        <td>{$classRow.name|wash}</td>
                        <td class="xe-long">{if $classRow.differences|count}<ul class="xe-diff-list">{foreach $classRow.differences as $difference}<li><code>{$difference.field|wash}</code> {cond( $difference.kind|eq( 'added' ), 'added'|i18n('design/standard/extract'), $difference.kind|eq( 'removed' ), 'removed'|i18n('design/standard/extract'), 'changed'|i18n('design/standard/extract') )}{if $difference.old|ne( '' )}: <del>{$difference.old|wash}</del>{/if}{if $difference.new|ne( '' )} &rarr; <ins>{$difference.new|wash}</ins>{/if}</li>{/foreach}</ul>{else}{'%count attributes'|i18n('design/standard/extract',, hash( '%count', $classRow.attribute_count ))}{/if}</td>
                    </tr>
                    {else}
                    <tr>
                        <td><span class="xe-badge xe-badge-{$classRow.state}">{cond( $classRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'), 'update'|i18n('design/standard/extract') )}</span></td>
                        <td><code>{$classRow.identifier|wash}</code>{if $classRow.existing_id} <a href={concat( 'class/view/', $classRow.existing_id )|ezurl} target="_blank" rel="noopener">#{$classRow.existing_id}</a>{/if}</td>
                        <td>{$classRow.name|wash}</td>
                        <td class="xe-long">{if $classRow.diff}{if $classRow.diff.has_changes}<ul class="xe-diff-list">
                            {foreach $classRow.diff.added as $d}<li><code>{$d.identifier|wash}</code> {'new attribute'|i18n('design/standard/extract')}: <ins>{$d.datatype|wash}</ins></li>{/foreach}
                            {foreach $classRow.diff.removed as $d}<li><code>{$d.identifier|wash}</code> {'only on the site'|i18n('design/standard/extract')}: <del>{$d.datatype|wash}</del></li>{/foreach}
                            {foreach $classRow.diff.changed as $d}<li><code>{$d.identifier|wash}</code> {'changed'|i18n('design/standard/extract')}: <del>{$d.old_datatype|wash}</del> &rarr; <ins>{$d.new_datatype|wash}</ins></li>{/foreach}
                        </ul>{else}{'The same attributes as the site’s class.'|i18n('design/standard/extract')}{/if}{else}{'%count attributes'|i18n('design/standard/extract',, hash( '%count', $classRow.attribute_count ))}{/if}</td>
                    </tr>
                    {/if}
                    {/foreach}
                    </tbody>
                </table>
            </div>
            {/if}
        </section>

        {* 4. Objects *}
        <section class="xe-card" aria-labelledby="xe-card-compare-objects">
            <header class="xe-card-head">
                <span class="xe-step">4</span>
                <div>
                    <h2 id="xe-card-compare-objects">{'Objects'|i18n('design/standard/extract')}</h2>
                    <p>{if $Mode|eq( 'packages' )}{'Per object: added, removed, or changed, with every field that differs (per language).'|i18n('design/standard/extract')}{else}{'Per object: what an install would do, and the fields that differ from the site (text, numbers, checkboxes, e-mail and identifiers are compared field by field).'|i18n('design/standard/extract')}{/if}</p>
                </div>
            </header>

            <form method="get" action="" class="xe-filter-bar" role="search">
                <input type="hidden" name="per_page" value="{$ComparePager.per_page|wash}" />
                {if and( $Mode|eq( 'site' ), $ParentNodeID )}<input type="hidden" name="parent" value="{$ParentNodeID|wash}" />{/if}
                <div class="xe-field">
                    <label class="xe-label" for="xe-compare-change">{'Change'|i18n('design/standard/extract')}</label>
                    <select name="change" id="xe-compare-change">
                        {if $Mode|eq( 'packages' )}
                        <option value="">{'any'|i18n('design/standard/extract')}</option>
                        <option value="added"{if $CompareFilter.change|eq( 'added' )} selected{/if}>{'added'|i18n('design/standard/extract')}</option>
                        <option value="removed"{if $CompareFilter.change|eq( 'removed' )} selected{/if}>{'removed'|i18n('design/standard/extract')}</option>
                        <option value="changed"{if $CompareFilter.change|eq( 'changed' )} selected{/if}>{'changed'|i18n('design/standard/extract')}</option>
                        {else}
                        <option value="">{'what an install would change'|i18n('design/standard/extract')}</option>
                        <option value="create"{if $CompareFilter.change|eq( 'create' )} selected{/if}>{'create'|i18n('design/standard/extract')}</option>
                        <option value="update"{if $CompareFilter.change|eq( 'update' )} selected{/if}>{'update'|i18n('design/standard/extract')}</option>
                        <option value="unchanged"{if $CompareFilter.change|eq( 'unchanged' )} selected{/if}>{'already there'|i18n('design/standard/extract')}</option>
                        <option value="class_missing"{if $CompareFilter.change|eq( 'class_missing' )} selected{/if}>{'class missing'|i18n('design/standard/extract')}</option>
                        <option value="all"{if $CompareFilter.change|eq( 'all' )} selected{/if}>{'all objects'|i18n('design/standard/extract')}</option>
                        {/if}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-compare-class">{'Class'|i18n('design/standard/extract')}</label>
                    <select name="class" id="xe-compare-class">
                        <option value="">{'any'|i18n('design/standard/extract')}</option>
                        {foreach $CompareFilter.classes as $classChoice}
                        <option value="{$classChoice.identifier|wash}"{if $classChoice.identifier|eq( $CompareFilter.class )} selected{/if}>{$classChoice.identifier|wash} ({$classChoice.count})</option>
                        {/foreach}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-compare-q">{'Name or remote id'|i18n('design/standard/extract')}</label>
                    <input type="search" name="q" id="xe-compare-q" value="{$CompareFilter.q|wash}" />
                </div>
                <div class="xe-field xe-filter-buttons">
                    <input class="button" type="submit" value="{'Filter'|i18n('design/standard/extract')}" />
                    {if $CompareFilter.active}<a class="button" href="?per_page={$ComparePager.per_page|wash}{if and( $Mode|eq( 'site' ), $ParentNodeID )}&amp;parent={$ParentNodeID}{/if}">{'Clear filters'|i18n('design/standard/extract')}</a>{/if}
                </div>
            </form>

            {include uri='design:xrowextract/pager.tpl' pager=$ComparePager query=$CompareFilter.query label='Pages of the compared objects'|i18n('design/standard/extract')}
            {if $ObjectRows|count|eq( 0 )}
            <p class="xe-columns-empty">{if $CompareFilter.active}{'No object matches these filters.'|i18n('design/standard/extract')}{else}{'No object differs.'|i18n('design/standard/extract')}{/if}</p>
            {else}
            <div class="xe-scroll" tabindex="0">
                <table class="xe-table xe-compare-table">
                    <thead><tr><th>{'Change'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Class'|i18n('design/standard/extract')}</th><th>{'Differences'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    {foreach $ObjectRows as $objectRow}
                    {if $Mode|eq( 'packages' )}
                    <tr>
                        <td><span class="xe-badge xe-badge-{cond( $objectRow.change|eq( 'added' ), 'create', $objectRow.change|eq( 'removed' ), 'error', 'update' )}">{cond( $objectRow.change|eq( 'added' ), 'added'|i18n('design/standard/extract'), $objectRow.change|eq( 'removed' ), 'removed'|i18n('design/standard/extract'), 'changed'|i18n('design/standard/extract') )}</span></td>
                        <td>{$objectRow.name|wash}<br /><small class="xe-long">{$objectRow.remote_id|wash}</small></td>
                        <td><code>{$objectRow.class_identifier|wash}</code></td>
                        <td class="xe-long">{if $objectRow.differences|count}<ul class="xe-diff-list">{foreach $objectRow.differences as $difference}<li><code>{$difference.field|wash}</code>{if $difference.language|ne( '' )} <small>{$difference.language|wash}</small>{/if} {cond( $difference.kind|eq( 'added' ), 'added'|i18n('design/standard/extract'), $difference.kind|eq( 'removed' ), 'removed'|i18n('design/standard/extract'), 'changed'|i18n('design/standard/extract') )}{if $difference.old|ne( '' )}: <del>{$difference.old|wash}</del>{/if}{if $difference.new|ne( '' )} &rarr; <ins>{$difference.new|wash}</ins>{/if}</li>{/foreach}</ul>{else}{$objectRow.languages|implode( ', ' )|wash}{/if}</td>
                    </tr>
                    {else}
                    <tr>
                        <td><span class="xe-badge xe-badge-{cond( $objectRow.state|eq( 'class_missing' ), 'error', $objectRow.state )}">{cond( $objectRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'), $objectRow.state|eq( 'update' ), 'update'|i18n('design/standard/extract'), $objectRow.state|eq( 'unchanged' ), 'unchanged'|i18n('design/standard/extract'), 'class missing'|i18n('design/standard/extract') )}</span></td>
                        <td>{$objectRow.name|wash}{if $objectRow.existing_id}{if $objectRow.existing.node_id} <a href={concat( 'content/view/full/', $objectRow.existing.node_id )|ezurl} target="_blank" rel="noopener">#{$objectRow.existing_id}</a>{else} #{$objectRow.existing_id}{/if}{/if}<br /><small class="xe-long">{$objectRow.remote_id|wash}</small></td>
                        <td><code>{$objectRow.class_identifier|wash}</code></td>
                        <td class="xe-long">
                            {if $objectRow.field_changes|count}
                            <ul class="xe-diff-list">{foreach $objectRow.field_changes as $change}<li><code>{$change.identifier|wash}</code> <small>{$change.language|wash}</small>: <del>{$change.old|wash}</del> &rarr; <ins>{$change.new|wash}</ins></li>{/foreach}</ul>
                            {elseif $objectRow.state|eq( 'create' )}
                            {if $objectRow.placement}{'New, below %path'|i18n('design/standard/extract',, hash( '%path', $objectRow.placement.path ))|wash}{else}{'New'|i18n('design/standard/extract')}{/if}
                            {elseif $objectRow.state|eq( 'update' )}
                            {'Differs from the site (its modified date); none of the fields compared one by one differ.'|i18n('design/standard/extract')}
                            {elseif $objectRow.state|eq( 'class_missing' )}
                            {'Class %class does not exist on this site.'|i18n('design/standard/extract',, hash( '%class', $objectRow.class_identifier ))|wash}
                            {else}
                            {'The same as on the site.'|i18n('design/standard/extract')}
                            {/if}
                        </td>
                    </tr>
                    {/if}
                    {/foreach}
                    </tbody>
                </table>
            </div>
            {include uri='design:xrowextract/pager.tpl' pager=$ComparePager query=$CompareFilter.query label='Pages of the compared objects'|i18n('design/standard/extract')}
            {/if}
        </section>
    </div>

    </div>

    {* DESIGN: Content END *}</div></div></div>
</div>
