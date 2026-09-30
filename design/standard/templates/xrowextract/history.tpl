{ezcss_require( array( 'xrowextract.css', 'xrowextract-schedules.css' ) )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='schedules'}
    {include uri='design:xrowextract/schedules_nav.tpl' part='history'}

    {if $alert_count|gt( 0 )}
    <form method="post" action={'xrowextract/history'|ezurl} class="xe-note xe-note-bad xe-alert-note" role="status">
        <span>{'%count scheduled run(s) failed, were skipped or could not be delivered since you last looked.'|i18n('design/standard/extract',, hash( '%count', $alert_count ))}</span>
        <a href={'xrowextract/history?state=failed'|ezurl}>{'Show the failed runs'|i18n('design/standard/extract')}</a>
        <button type="submit" class="button" name="AcknowledgeAlerts" value="1">{'Mark as seen'|i18n('design/standard/extract')}</button>
    </form>
    {/if}

    <div class="xe-cards">
        <section class="xe-card" id="xe-history" aria-labelledby="xe-card-history">
            <header class="xe-card-head">
                <div>
                    <h2 id="xe-card-history">{if $all_jobs}{'Export history'|i18n('design/standard/extract')}{else}{'Your export history'|i18n('design/standard/extract')}{/if}</h2>
                    <p>{'Every run, kept %days days (a schedule can keep its own longer or shorter).'|i18n('design/standard/extract',, hash( '%days', $retention_days ))}</p>
                </div>
            </header>

            <dl class="xe-job-stats">
                <div class="xe-stat xe-stat-total"><dt><a href={concat( 'xrowextract/history?x=1', $query_no_state )|ezurl}>{'Total runs'|i18n('design/standard/extract')}</a></dt><dd>{$counts.total}</dd></div>
                <div class="xe-stat xe-stat-done"><dt><a href={concat( 'xrowextract/history?state=done', $query_no_state )|ezurl}>{'Completed'|i18n('design/standard/extract')}</a></dt><dd>{$counts.done}</dd></div>
                <div class="xe-stat xe-stat-queued{if $counts.warning|eq( 0 )} xe-stat-zero{/if}"><dt><a href={concat( 'xrowextract/history?state=warning', $query_no_state )|ezurl}>{'With warnings'|i18n('design/standard/extract')}</a></dt><dd>{$counts.warning}</dd></div>
                <div class="xe-stat xe-stat-running{if $counts.skipped|eq( 0 )} xe-stat-zero{/if}"><dt><a href={concat( 'xrowextract/history?state=skipped', $query_no_state )|ezurl}>{'Skipped'|i18n('design/standard/extract')}</a></dt><dd>{$counts.skipped}</dd></div>
                <div class="xe-stat xe-stat-failed{if $counts.failed|eq( 0 )} xe-stat-zero{/if}"><dt><a href={concat( 'xrowextract/history?state=failed', $query_no_state )|ezurl}>{'Failed'|i18n('design/standard/extract')}</a></dt><dd>{$counts.failed}</dd></div>
            </dl>

            <form method="get" action={'xrowextract/history'|ezurl} class="xe-history-filter" role="search">
                <div class="xe-grid">
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-state">{'Result'|i18n('design/standard/extract')}</label>
                        <select id="xe-history-state" name="state">
                            <option value="">{'Any'|i18n('design/standard/extract')}</option>
                            <option value="done"{if $filter.state|eq( 'done' )} selected="selected"{/if}>{'Completed'|i18n('design/standard/extract')}</option>
                            <option value="warning"{if $filter.state|eq( 'warning' )} selected="selected"{/if}>{'With warnings'|i18n('design/standard/extract')}</option>
                            <option value="skipped"{if $filter.state|eq( 'skipped' )} selected="selected"{/if}>{'Skipped'|i18n('design/standard/extract')}</option>
                            <option value="failed"{if $filter.state|eq( 'failed' )} selected="selected"{/if}>{'Failed'|i18n('design/standard/extract')}</option>
                        </select>
                    </div>
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-kind">{'Kind'|i18n('design/standard/extract')}</label>
                        <select id="xe-history-kind" name="kind">
                            <option value="">{'Any'|i18n('design/standard/extract')}</option>
                            {foreach hash( 'csv', 'One class'|i18n('design/standard/extract'), 'archive', 'Site archive'|i18n('design/standard/extract'), 'package', 'Content package'|i18n('design/standard/extract'), 'import', 'Import'|i18n('design/standard/extract') ) as $kind => $label}
                            <option value="{$kind}"{if $filter.kind|eq( $kind )} selected="selected"{/if}>{$label|wash}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-schedule">{'Schedule'|i18n('design/standard/extract')}</label>
                        <select id="xe-history-schedule" name="schedule">
                            <option value="">{'Any'|i18n('design/standard/extract')}</option>
                            {foreach $schedules as $schedule}<option value="{$schedule.id}"{if $filter.schedule_id|eq( $schedule.id )} selected="selected"{/if}>{$schedule.name|wash}</option>{/foreach}
                        </select>
                    </div>
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-trigger">{'Started by'|i18n('design/standard/extract')}</label>
                        <select id="xe-history-trigger" name="trigger">
                            <option value="">{'Any'|i18n('design/standard/extract')}</option>
                            {foreach hash( 'schedule', 'Schedule'|i18n('design/standard/extract'), 'system_cron', 'System cron'|i18n('design/standard/extract'), 'manual', 'By hand'|i18n('design/standard/extract'), 'cli', 'Command line'|i18n('design/standard/extract'), 'download', 'Direct download'|i18n('design/standard/extract') ) as $trigger => $label}
                            <option value="{$trigger}"{if $filter.trigger|eq( $trigger )} selected="selected"{/if}>{$label|wash}</option>
                            {/foreach}
                        </select>
                    </div>
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-delivery">{'Delivery'|i18n('design/standard/extract')}</label>
                        <select id="xe-history-delivery" name="delivery">
                            <option value="">{'Any'|i18n('design/standard/extract')}</option>
                            <option value="ok"{if $filter.delivery|eq( 'ok' )} selected="selected"{/if}>{'Delivered'|i18n('design/standard/extract')}</option>
                            <option value="partial"{if $filter.delivery|eq( 'partial' )} selected="selected"{/if}>{'Partly delivered'|i18n('design/standard/extract')}</option>
                            <option value="failed"{if $filter.delivery|eq( 'failed' )} selected="selected"{/if}>{'Not delivered'|i18n('design/standard/extract')}</option>
                        </select>
                    </div>
                    <div class="xe-field">
                        <span class="xe-label">{'Date'|i18n('design/standard/extract')}</span>
                        <div class="xe-range">
                            <label>{'from'|i18n('design/standard/extract')} <input type="date" name="from" value="{$filter.from|wash}" /></label>
                            <label>{'to'|i18n('design/standard/extract')} <input type="date" name="to" value="{$filter.to|wash}" /></label>
                        </div>
                    </div>
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-text">{'Text'|i18n('design/standard/extract')}</label>
                        <input type="search" id="xe-history-text" name="text" value="{$filter.text|wash}" class="xe-wide" />
                    </div>
                    {if $all_jobs}
                    <div class="xe-field">
                        <label class="xe-label" for="xe-history-owner">{'User (login)'|i18n('design/standard/extract')}</label>
                        <input type="text" id="xe-history-owner" name="owner" value="{$filter.owner|wash}" class="xe-wide" />
                    </div>
                    {/if}
                </div>
                <div class="xe-toolbar">
                    <button type="submit" class="defaultbutton">{'Filter'|i18n('design/standard/extract')}</button>
                    <a class="button" href={'xrowextract/history'|ezurl}>{'Reset'|i18n('design/standard/extract')}</a>
                    <span class="xe-spacer"></span>
                    <span class="xe-match">{'%count run(s)'|i18n('design/standard/extract',, hash( '%count', $total ))}</span>
                </div>
            </form>

            {if $rows|count|eq( 0 )}
            <p class="xe-columns-empty">{'No runs match.'|i18n('design/standard/extract')}</p>
            {else}
            <ul class="xe-jobs xe-history">
                {foreach $rows as $row}
                <li id="history-{$row.id}" class="xe-job xe-job-{$row.state|wash}">
                    <div class="xe-job-main">
                        <span class="xe-job-type">{if $row.kind|eq( 'archive' )}{'Archive'|i18n('design/standard/extract')}{elseif $row.kind|eq( 'import' )}{'Import'|i18n('design/standard/extract')}{elseif $row.kind|eq( 'package' )}{'Package'|i18n('design/standard/extract')}{else}{'CSV'|i18n('design/standard/extract')}{/if}</span>
                        <span class="xe-colinfo">
                            <strong>{$row.what|wash}</strong>
                            <small>
                                {if $row.format}<code>{$row.format|wash}</code>{/if}
                                {if $row.schedule_id} · <a class="xe-badge" href={concat( 'xrowextract/history?schedule=', $row.schedule_id )|ezurl}>{$row.schedule_name|wash}</a>{/if}
                                {if $row.preset_name} · <span class="xe-badge">{$row.preset_name|wash}</span>{/if}
                                · {if $row.trigger|eq( 'schedule' )}{'Schedule'|i18n('design/standard/extract')}{elseif $row.trigger|eq( 'system_cron' )}{'System cron'|i18n('design/standard/extract')}{elseif $row.trigger|eq( 'cli' )}{'Command line'|i18n('design/standard/extract')}{elseif $row.trigger|eq( 'download' )}{'Direct download'|i18n('design/standard/extract')}{else}{'By hand'|i18n('design/standard/extract')}{/if}
                                {if $row.run_mode|eq( 'delta' )} · <span class="xe-badge xe-badge-update">{'delta'|i18n('design/standard/extract')}</span>{/if}
                            </small>
                        </span>
                        {def $owner = $row.owner_user}
                        {if $owner.node_id}<a class="xe-user" style="--xe-user-hue: {$owner.hue}" href={concat( 'content/view/full/', $owner.node_id )|ezurl} title="{'Started by %name (%login)'|i18n('design/standard/extract',, hash( '%name', $owner.name, '%login', $owner.login ))|wash}">{else}<span class="xe-user xe-user-gone" style="--xe-user-hue: {$owner.hue}" title="{'Started by %login (account no longer exists)'|i18n('design/standard/extract',, hash( '%login', $owner.login ))|wash}">{/if}
                            <span class="xe-user-avatar" aria-hidden="true">{$owner.initials|wash}</span>
                            <span class="xe-user-name">{$owner.name|wash}{if $row.mine} <small>({'you'|i18n('design/standard/extract')})</small>{/if}</span>
                        {if $owner.node_id}</a>{else}</span>{/if}
                        {undef $owner}
                        <span class="xe-job-state xe-badge xe-state-{if $row.state|eq( 'warning' )}queued{elseif $row.state|eq( 'skipped' )}running{elseif $row.state|eq( 'done' )}done{else}failed{/if}">
                            {if $row.state|eq( 'done' )}{'done'|i18n('design/standard/extract')}{elseif $row.state|eq( 'warning' )}{'done, with warnings'|i18n('design/standard/extract')}{elseif $row.state|eq( 'skipped' )}{'skipped'|i18n('design/standard/extract')}{else}{'failed'|i18n('design/standard/extract')}{/if}
                        </span>
                    </div>
                    <ol class="xe-job-times">
                        <li class="xe-time-done"><span class="xe-time-label">{'Started'|i18n('design/standard/extract')}</span>{if $row.started}<time datetime="{$row.started|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$row.started|l10n( shortdatetime )}</time>{else}<time>—</time>{/if}</li>
                        <li class="xe-time-done{if $row.state|eq( 'failed' )} xe-time-bad{/if}"><span class="xe-time-label">{if $row.state|eq( 'failed' )}{'Failed'|i18n('design/standard/extract')}{else}{'Ended'|i18n('design/standard/extract')}{/if}</span>{if $row.ended}<time datetime="{$row.ended|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$row.ended|l10n( shortdatetime )}</time>{else}<time>—</time>{/if}{if $row.duration}<small>{'took %time'|i18n('design/standard/extract',, hash( '%time', $row.duration ))}</small>{/if}</li>
                        {if $row.delivery_state}
                        <li class="xe-time-done{if $row.delivery_state|ne( 'ok' )} xe-time-bad{/if}"><span class="xe-time-label">{'Delivery'|i18n('design/standard/extract')}</span><time>{if $row.delivery_state|eq( 'ok' )}{'delivered'|i18n('design/standard/extract')}{elseif $row.delivery_state|eq( 'partial' )}{'partly delivered'|i18n('design/standard/extract')}{else}{'not delivered'|i18n('design/standard/extract')}{/if}</time><small>{$row.destinations|wash}</small></li>
                        {/if}
                    </ol>
                    <div class="xe-job-meta">
                        {if $row.rows}<span><strong>{$row.rows}</strong> {'rows'|i18n('design/standard/extract')}</span>{/if}
                        {if $row.size_kb}<span>{$row.size_kb} KB</span>{/if}
                        {if $row.file_name}<span><code>{$row.file_name|wash}</code></span>{/if}
                        {if $row.checksum}<span title="SHA-256 {$row.checksum|wash}">sha256 <code>{$row.checksum|shorten( 16, '…' )|wash}</code></span>{/if}
                    </div>
                    {if $row.error}<p class="xe-note xe-note-bad">{$row.error|wash}</p>{/if}
                    {if or( $row.warnings, $row.delivery )}
                    <details class="xe-history-details">
                        <summary>{if $row.warnings}{'%count warning(s)'|i18n('design/standard/extract',, hash( '%count', $row.warnings|count ))}{/if}{if and( $row.warnings, $row.delivery )} · {/if}{if $row.delivery}{'Delivery details'|i18n('design/standard/extract')}{/if}</summary>
                        {if $row.warnings}<ul class="xe-warning-list">{foreach $row.warnings as $warning}<li>{$warning|wash}</li>{/foreach}</ul>{/if}
                        {if $row.delivery}
                        <ul class="xe-delivery-list">
                            {foreach $row.delivery as $delivery}
                            <li class="{if $delivery.ok}xe-delivery-ok{else}xe-delivery-bad{/if}"><strong>{$delivery.destination|wash}</strong>: {if $delivery.ok}{'delivered'|i18n('design/standard/extract')}{else}{'not delivered'|i18n('design/standard/extract')}{/if} · {'%count attempt(s)'|i18n('design/standard/extract',, hash( '%count', $delivery.attempts ))} · {$delivery.message|wash}</li>
                            {/foreach}
                        </ul>
                        {/if}
                    </details>
                    {/if}
                    {if $row.job_exists}
                    <div class="xe-job-buttons">
                        <a class="button" href={concat( 'xrowextract/jobs#job-', $row.job_id )|ezurl}>{'Show the job'|i18n('design/standard/extract')}</a>
                        {if or( $row.state|eq( 'done' ), $row.state|eq( 'warning' ) )}<a class="button" href={concat( 'xrowextract/job_download/', $row.job_id )|ezurl}>{'Download'|i18n('design/standard/extract')}</a>{/if}
                    </div>
                    {/if}
                </li>
                {/foreach}
            </ul>
            {/if}

            {if $pages|gt( 1 )}
            <nav class="xe-pager" aria-label="{'Pages'|i18n('design/standard/extract')|wash}">
                {if $prev_offset|ge( 0 )}<a class="button" rel="prev" href={concat( 'xrowextract/history?offset=', $prev_offset, $query_all )|ezurl}>{'Newer'|i18n('design/standard/extract')}</a>{else}<span class="button button-disabled">{'Newer'|i18n('design/standard/extract')}</span>{/if}
                <span>{'Page %page of %pages'|i18n('design/standard/extract',, hash( '%page', $page, '%pages', $pages ))}</span>
                {if $next_offset|ge( 0 )}<a class="button" rel="next" href={concat( 'xrowextract/history?offset=', $next_offset, $query_all )|ezurl}>{'Older'|i18n('design/standard/extract')}</a>{else}<span class="button button-disabled">{'Older'|i18n('design/standard/extract')}</span>{/if}
            </nav>
            {/if}
        </section>
    </div>

    </div>
    {* DESIGN: Content END *}</div></div></div>
</div>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>
