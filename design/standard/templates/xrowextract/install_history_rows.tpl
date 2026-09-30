{* Package installs from the history (XrowExtractHistory::installRow()): who, when, which package, how existing
   objects and classes were handled, the result, the datatype check; "Export these again" for the objects it
   installed (a background export of their nodes). Included by jobs.tpl (every package) and package.tpl
   (one package: show_package false). Parameters: installs, show_package. *}
<ul class="xe-install-history">
    {foreach $installs as $install}
    <li class="xe-install-row xe-install-{$install.state|wash}">
        <div class="xe-install-main">
            <span class="xe-badge xe-state-{cond( $install.state|eq( 'warning' ), 'done', $install.state )|wash}{if $install.state|eq( 'warning' )} xe-badge-update{/if}">{if $install.state|eq( 'failed' )}{'failed'|i18n('design/standard/extract')}{elseif $install.state|eq( 'warning' )}{'done, with warnings'|i18n('design/standard/extract')}{else}{'done'|i18n('design/standard/extract')}{/if}</span>
            {if $show_package}
            <strong>{if $install.package_exists}<a href={concat( 'xrowextract/package/', $install.package )|ezurl}>{$install.package|wash}</a>{else}{$install.package|wash} <small>({'no longer in the repository'|i18n('design/standard/extract')})</small>{/if}</strong>
            {/if}
            <span class="xe-install-who">{'by %name'|i18n('design/standard/extract',, hash( '%name', $install.owner_user.name ))|wash}{if $install.mine} <small>({'you'|i18n('design/standard/extract')})</small>{/if}</span>
            <time datetime="{$install.started|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$install.started|l10n( shortdatetime )}</time>{if $install.duration} <small>{'took %time'|i18n('design/standard/extract',, hash( '%time', $install.duration ))|wash}</small>{/if}
            {if $install.trigger|eq( 'cli' )}<span class="xe-badge">{'command line'|i18n('design/standard/extract')}</span>{/if}
        </div>
        <div class="xe-install-result">
            <strong>{$install.created}</strong> {'created'|i18n('design/standard/extract')},
            <strong>{$install.existing}</strong> {'already there'|i18n('design/standard/extract')}
            ({if $install.object_mode|eq( 'skip' )}{'left as they were'|i18n('design/standard/extract')}{elseif $install.object_mode|eq( 'new' )}{'added again as copies'|i18n('design/standard/extract')}{else}{'updated'|i18n('design/standard/extract')}{/if}){if $install.not_installed|gt( 0 )},
            <strong>{$install.not_installed}</strong> {'not installed (class missing)'|i18n('design/standard/extract')}{/if}{if $install.error_count|gt( 0 )},
            <strong>{$install.error_count}</strong> {'error(s)'|i18n('design/standard/extract')}{/if}
            <small>· {'Existing objects: %objects, existing classes: %classes'|i18n('design/standard/extract',, hash( '%objects', $install.object_mode, '%classes', $install.class_mode ))|wash}{if $install.parent_name|ne( '' )} · {'below %parent'|i18n('design/standard/extract',, hash( '%parent', $install.parent_name ))|wash}{/if}{if $install.site_access|ne( '' )} · {$install.site_access|wash}{/if}</small>
        </div>
        {if $install.missing_datatypes|count}
        <p class="xe-note xe-note-bad">{'Datatypes this site did not have:'|i18n('design/standard/extract')} {foreach $install.missing_datatypes as $datatype}<code>{$datatype|wash}</code> {/foreach}</p>
        {/if}
        {if $install.error|ne( '' )}<p class="xe-note xe-note-bad">{$install.error|wash}</p>{/if}
        <div class="xe-install-buttons">
            {if $install.job_exists}<a class="button" href={concat( 'xrowextract/jobs#job-', $install.job_id )|ezurl}>{'Open the job'|i18n('design/standard/extract')}</a>{/if}
            {if and( $install.node_count|gt( 0 ), $JobsAvailable )}
            <form method="post" action={'xrowextract/jobs'|ezurl} class="xe-inline">
                <input type="hidden" name="ExportAgainHistoryID" value="{$install.id}" />
                <button type="submit" class="button" title="{'A new content package of the %count objects this install left on the site, as a background job'|i18n('design/standard/extract',, hash( '%count', $install.node_count ))|wash}">{'Export these again'|i18n('design/standard/extract')}</button>
            </form>
            {/if}
        </div>
    </li>
    {/foreach}
</ul>
