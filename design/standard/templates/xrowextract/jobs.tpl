{ezcss_require( 'xrowextract.css' )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='jobs'}

    {if $started_job_id}
    <p class="xe-note" role="status">{'Job started: '|i18n('design/standard/extract')}<a href="#job-{$started_job_id|wash}">{'see it below'|i18n('design/standard/extract')}</a>{' — it runs in the background; this page updates on its own while it does.'|i18n('design/standard/extract')}</p>
    {/if}

    <div class="xe-cards">
        <section class="xe-card" aria-labelledby="xe-card-jobs">
            <header class="xe-card-head">
                <div>
                    <h2 id="xe-card-jobs">{if $all_jobs}{'All jobs'|i18n('design/standard/extract')}{else}{'Your jobs'|i18n('design/standard/extract')}{/if}</h2>
                    <p>{'Exports started with "Run in the background", kept for %days days after they finish.'|i18n('design/standard/extract',, hash( '%days', $retention_days ))}</p>
                </div>
            </header>

            {* Totals by state; the Jobs script recounts them from the rows as the poll moves jobs on *}
            <dl class="xe-job-stats" data-role="job-stats">
                <div class="xe-stat xe-stat-total"><dt>{'Total jobs'|i18n('design/standard/extract')}</dt><dd data-count="total">{$job_counts.total}</dd></div>
                <div class="xe-stat xe-stat-done"><dt>{'Completed'|i18n('design/standard/extract')}</dt><dd data-count="done">{$job_counts.done}</dd></div>
                <div class="xe-stat xe-stat-running{if $job_counts.running|eq( 0 )} xe-stat-zero{/if}"><dt>{'Running'|i18n('design/standard/extract')}</dt><dd data-count="running">{$job_counts.running}</dd></div>
                <div class="xe-stat xe-stat-queued{if $job_counts.queued|eq( 0 )} xe-stat-zero{/if}"><dt>{'Queued'|i18n('design/standard/extract')}</dt><dd data-count="queued">{$job_counts.queued}</dd></div>
                <div class="xe-stat xe-stat-failed{if $job_counts.failed|eq( 0 )} xe-stat-zero{/if}"><dt>{'Failed'|i18n('design/standard/extract')}</dt><dd data-count="failed">{$job_counts.failed}</dd></div>
            </dl>

            {if $jobs|count|eq( 0 )}
            <p class="xe-columns-empty">{'No jobs yet. Start one from the "Run in the background" button on the class or site archive page.'|i18n('design/standard/extract')}</p>
            {else}
            <ul class="xe-jobs" data-poll-base={'xrowextract/job_status'|ezurl} data-download-base={'xrowextract/job_download'|ezurl} data-download-label="{'Download'|i18n('design/standard/extract')|wash}">
                {foreach $jobs as $job}
                <li id="job-{$job.id|wash}" class="xe-job xe-job-{$job.state}" data-job-id="{$job.id|wash}" data-state="{$job.state|wash}"{if $job.active} data-poll="1"{/if}>
                    <div class="xe-job-main">
                        <span class="xe-job-type" title="{if $job.type|eq( 'archive' )}{'Site archive'|i18n('design/standard/extract')|wash}{elseif $job.type|eq( 'import' )}{'Import'|i18n('design/standard/extract')|wash}{elseif $job.type|eq( 'package' )}{'Content package'|i18n('design/standard/extract')|wash}{else}{'One class'|i18n('design/standard/extract')|wash}{/if}">{if $job.type|eq( 'archive' )}{'Archive'|i18n('design/standard/extract')}{elseif $job.type|eq( 'import' )}{'Import'|i18n('design/standard/extract')}{elseif $job.type|eq( 'package' )}{'Package'|i18n('design/standard/extract')}{else}{'CSV'|i18n('design/standard/extract')}{/if}</span>
                        <span class="xe-colinfo">
                            <strong>{$job.what|wash}</strong>
                            <small><code>{$job.format|wash}</code>{if $job.preset|ne( '' )} · <span class="xe-badge" title="{'Started from a saved preset'|i18n('design/standard/extract')|wash}">{$job.preset_name|wash}</span>{/if}</small>
                        </span>
                        {def $owner = $job.owner_user}
                        {if $owner.node_id}
                        <a class="xe-user" style="--xe-user-hue: {$owner.hue}" href={concat( 'content/view/full/', $owner.node_id )|ezurl} title="{'Started by %name (%login)'|i18n('design/standard/extract',, hash( '%name', $owner.name, '%login', $owner.login ))|wash}">
                        {else}
                        <span class="xe-user xe-user-gone" style="--xe-user-hue: {$owner.hue}" title="{'Started by %login (account no longer exists)'|i18n('design/standard/extract',, hash( '%login', $owner.login ))|wash}">
                        {/if}
                            <span class="xe-user-avatar" aria-hidden="true">{$owner.initials|wash}</span>
                            <span class="xe-user-name">{$owner.name|wash}{if $job.mine} <small>({'you'|i18n('design/standard/extract')})</small>{/if}</span>
                        {if $owner.node_id}</a>{else}</span>{/if}
                        {undef $owner}
                        <span class="xe-job-state xe-badge xe-state-{$job.state|wash}" data-role="state">
                            {if $job.state|eq( 'queued' )}{'queued'|i18n('design/standard/extract')}
                            {elseif $job.state|eq( 'running' )}{'running'|i18n('design/standard/extract')}
                            {elseif $job.state|eq( 'done' )}{'done'|i18n('design/standard/extract')}
                            {else}{'failed'|i18n('design/standard/extract')}{/if}
                        </span>
                    </div>

                    <div class="xe-job-progress"{if $job.active|not} hidden{/if} data-role="progress-wrap">
                        <div class="xe-progress"><div class="xe-progress-bar" data-role="progress-bar" style="width: {$job.progress_percent}%"></div></div>
                        <span class="xe-job-progress-text" data-role="progress-text">{if $job.progress}{$job.progress.done} / {$job.progress.total} · {$job.progress.phase|wash}{else}{'Starting …'|i18n('design/standard/extract')}{/if}</span>
                    </div>

                    <ol class="xe-job-times" data-created="{$job.created}">
                        <li class="xe-time-done">
                            <span class="xe-time-label">{'Queued'|i18n('design/standard/extract')}</span>
                            <time datetime="{$job.created|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$job.created|l10n( shortdatetime )}</time>
                        </li>
                        <li class="{if $job.started}xe-time-done{else}xe-time-pending{/if}" data-role="started-step">
                            <span class="xe-time-label">{'Started'|i18n('design/standard/extract')}</span>
                            <time data-role="started"{if $job.started} datetime="{$job.started|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}"{/if}>{if $job.started}{$job.started|l10n( shortdatetime )}{else}{'waiting …'|i18n('design/standard/extract')}{/if}</time>
                            <small data-role="wait">{if $job.wait_text}{'after %time in the queue'|i18n('design/standard/extract',, hash( '%time', $job.wait_text ))}{/if}</small>
                        </li>
                        <li class="{if $job.ended}xe-time-done{if $job.state|eq( 'failed' )} xe-time-bad{/if}{else}xe-time-pending{/if}" data-role="ended-step">
                            <span class="xe-time-label" data-role="ended-label">{if $job.state|eq( 'failed' )}{'Failed'|i18n('design/standard/extract')}{else}{'Ended'|i18n('design/standard/extract')}{/if}</span>
                            <time data-role="ended"{if $job.ended} datetime="{$job.ended|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}"{/if}>{if $job.ended}{$job.ended|l10n( shortdatetime )}{else}—{/if}</time>
                            <small data-role="took">{if $job.run_text}{'took %time'|i18n('design/standard/extract',, hash( '%time', $job.run_text ))}{/if}</small>
                        </li>
                    </ol>

                    <div class="xe-job-meta" data-label-failed="{'Failed'|i18n('design/standard/extract')|wash}" data-label-took="{'took %time'|i18n('design/standard/extract')|wash}" data-label-wait="{'after %time in the queue'|i18n('design/standard/extract')|wash}">
                        <span data-role="rows">{if $job.rows|ne( null )}<strong>{$job.rows}</strong> {'rows'|i18n('design/standard/extract')}{/if}</span>
                        <span data-role="size">{if $job.size_kb|ne( null )}{$job.size_kb} KB{/if}</span>
                    </div>
                    {if $job.error}<p class="xe-note xe-note-bad" data-role="error">{$job.error|wash}</p>{/if}
                    {if $job.type|eq( 'import' )|and( $job.counts )}
                    <ul class="xe-stats xe-job-counts">
                        <li class="xe-badge xe-badge-create"><strong>{$job.counts.create}</strong> {'create'|i18n('design/standard/extract')}</li>
                        <li class="xe-badge xe-badge-update"><strong>{$job.counts.update}</strong> {'update'|i18n('design/standard/extract')}</li>
                        <li class="xe-badge xe-badge-unchanged"><strong>{$job.counts.unchanged}</strong> {'unchanged'|i18n('design/standard/extract')}</li>
                        <li class="xe-badge xe-badge-error"><strong>{$job.counts.error}</strong> {'error'|i18n('design/standard/extract')}</li>
                    </ul>
                    {/if}

                    <div class="xe-job-buttons">
                        {if $job.state|eq( 'done' )}
                        <a class="button" data-role="download" href={concat( 'xrowextract/job_download/', $job.id )|ezurl}>{if $job.type|eq( 'import' )}{'Download report'|i18n('design/standard/extract')}{else}{'Download'|i18n('design/standard/extract')}{/if}</a>
                        {if $job.has_errors_file}
                        <a class="button" href={concat( 'xrowextract/job_download/', $job.id, '/errors' )|ezurl}>{'Download error rows'|i18n('design/standard/extract')}</a>
                        {/if}
                        {/if}
                        {if $job.type|eq( 'import' )|and( or( $job.state|eq( 'failed' ), $job.counts.error|gt( 0 ) ) )}
                        <form method="post" action={'xrowextract/import'|ezurl} class="xe-job-resume-form xe-inline">
                            <input type="hidden" name="ResumeJobID" value="{$job.id|wash}" />
                            <label>{'Resume from row'|i18n('design/standard/extract')} <input type="number" name="ResumeFromRow" min="1" value="1" class="xe-resume-row" /></label>
                            <button type="submit" class="button">{'Resume as a new job'|i18n('design/standard/extract')}</button>
                        </form>
                        {/if}
                        {if or( $job.mine, $all_jobs )}
                        <form method="post" action={'xrowextract/jobs'|ezurl} class="xe-job-delete-form">
                            <input type="hidden" name="DeleteJobID" value="{$job.id|wash}" />
                            <button type="submit" class="button xe-icon-text" data-confirm="{'Delete this job and its file?'|i18n('design/standard/extract')|wash}">{'Delete'|i18n('design/standard/extract')}</button>
                        </form>
                        {/if}
                    </div>
                </li>
                {/foreach}
            </ul>
            {/if}
        </section>
    </div>

    </div>
    {* DESIGN: Content END *}</div></div></div>
</div>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>
