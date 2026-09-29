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
                <span class="xe-count"><strong>{$jobs|count}</strong> {'jobs'|i18n('design/standard/extract')}</span>
            </header>

            {if $jobs|count|eq( 0 )}
            <p class="xe-columns-empty">{'No jobs yet. Start one from the "Run in the background" button on the class or site archive page.'|i18n('design/standard/extract')}</p>
            {else}
            <ul class="xe-jobs" data-poll-base={'xrowextract/job_status'|ezurl} data-download-base={'xrowextract/job_download'|ezurl} data-download-label="{'Download'|i18n('design/standard/extract')|wash}">
                {foreach $jobs as $job}
                <li id="job-{$job.id|wash}" class="xe-job xe-job-{$job.state}" data-job-id="{$job.id|wash}" data-state="{$job.state|wash}"{if $job.active} data-poll="1"{/if}>
                    <div class="xe-job-main">
                        <span class="xe-job-type" title="{if $job.type|eq( 'archive' )}{'Site archive'|i18n('design/standard/extract')|wash}{else}{'One class'|i18n('design/standard/extract')|wash}{/if}">{if $job.type|eq( 'archive' )}{'Archive'|i18n('design/standard/extract')}{else}{'CSV'|i18n('design/standard/extract')}{/if}</span>
                        <span class="xe-colinfo">
                            <strong>{$job.what|wash}</strong>
                            <small>
                                <code>{$job.format|wash}</code>
                                {if $all_jobs} · {$job.owner|wash}{/if}
                                · {$job.created|l10n( shortdatetime )}
                            </small>
                        </span>
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

                    <div class="xe-job-meta">
                        <span data-role="rows">{if $job.rows|ne( null )}<strong>{$job.rows}</strong> {'rows'|i18n('design/standard/extract')}{/if}</span>
                        <span data-role="size">{if $job.size_kb|ne( null )}{$job.size_kb} KB{/if}</span>
                        {if $job.started}<span>{'started'|i18n('design/standard/extract')} {$job.started|l10n( shortdatetime )}</span>{/if}
                        {if $job.ended}<span>{'ended'|i18n('design/standard/extract')} {$job.ended|l10n( shortdatetime )}</span>{/if}
                    </div>
                    {if $job.error}<p class="xe-note xe-note-bad" data-role="error">{$job.error|wash}</p>{/if}

                    <div class="xe-job-buttons">
                        {if $job.state|eq( 'done' )}
                        <a class="button" data-role="download" href={concat( 'xrowextract/job_download/', $job.id )|ezurl}>{'Download'|i18n('design/standard/extract')}</a>
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
