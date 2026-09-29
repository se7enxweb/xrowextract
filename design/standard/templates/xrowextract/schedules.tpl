{ezcss_require( array( 'xrowextract.css', 'xrowextract-schedules.css' ) )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='schedules'}
    {include uri='design:xrowextract/schedules_nav.tpl' part='schedules'}

    {if $notice}<p class="xe-note" role="status">{$notice|wash}</p>{/if}
    {if $errors}
    <div class="xe-error" role="alert">
        <strong>{'The schedule was not saved:'|i18n('design/standard/extract')}</strong>
        <ul>{foreach $errors as $error}<li>{$error|wash}</li>{/foreach}</ul>
    </div>
    {/if}
    {if $jobs_available|not}
    <p class="xe-note xe-note-bad">{'Background jobs are not available on this server (no PHP command line binary was found, or exec() is disabled): schedules cannot run.'|i18n('design/standard/extract')}</p>
    {/if}

    <div class="xe-cards">

    {if $edit}
    {* ---------------------------------------------------------------- the form *}
    <form method="post" action={'xrowextract/schedules'|ezurl} class="xe-schedule-form" data-role="schedule-form">
    <input type="hidden" name="Schedule[id]" value="{$edit.id}" />
    <section class="xe-card" id="xe-schedule-edit" aria-labelledby="xe-card-schedule-edit">
        <header class="xe-card-head">
            <span class="xe-step" aria-hidden="true">1</span>
            <div>
                <h2 id="xe-card-schedule-edit">{if $edit.id}{'Change the schedule'|i18n('design/standard/extract')}{else}{'New schedule'|i18n('design/standard/extract')}{/if}</h2>
                <p>{'What runs, and under which name.'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        <div class="xe-grid">
            <div class="xe-field">
                <label class="xe-label" for="xe-schedule-name">{'Name'|i18n('design/standard/extract')}</label>
                <input type="text" id="xe-schedule-name" name="Schedule[name]" value="{$edit.name|wash}" maxlength="150" required="required" class="xe-wide" />
            </div>
            <div class="xe-field">
                <span class="xe-label">{'State'|i18n('design/standard/extract')}</span>
                <label class="xe-check"><input type="checkbox" name="Schedule[enabled]" value="1"{if $edit.enabled} checked="checked"{/if} /> {'Enabled: runs on its own'|i18n('design/standard/extract')}</label>
            </div>
        </div>

        <fieldset class="xe-field xe-kinds">
            <legend class="xe-label">{'What it runs'|i18n('design/standard/extract')}</legend>
            <div class="xe-segmented" role="radiogroup">
                <label><input type="radio" name="Schedule[kind]" value="preset" data-role="kind"{if $edit.kind|eq('preset')} checked="checked"{/if} /><span>{'Saved preset'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="Schedule[kind]" value="archive" data-role="kind"{if $edit.kind|eq('archive')} checked="checked"{/if} /><span>{'Site archive'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="Schedule[kind]" value="package" data-role="kind"{if $edit.kind|eq('package')} checked="checked"{/if} /><span>{'Export as package'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="Schedule[kind]" value="import" data-role="kind"{if $edit.kind|eq('import')} checked="checked"{/if} /><span>{'Import from a location'|i18n('design/standard/extract')}</span></label>
            </div>
        </fieldset>

        {* A saved preset *}
        <div class="xe-kind-panel" data-kind="preset">
            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-preset">{'Preset'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-preset" name="Schedule[preset]" data-role="preset-select">
                        <option value="">{'Choose a preset'|i18n('design/standard/extract')}</option>
                        {foreach $presets as $preset}
                        <option value="{$preset.ref|wash}" data-placeholders="{$preset.placeholders|wash}"{if $edit.d_preset|eq( $preset.ref )} selected="selected"{/if}>{$preset.name|wash}{if $preset.site} ({'site'|i18n('design/standard/extract')}){/if}</option>
                        {/foreach}
                    </select>
                    {if $presets|count|eq( 0 )}<p class="xe-help">{'No presets yet: save one on the One class tab first.'|i18n('design/standard/extract')}</p>{/if}
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-params">{'Placeholder values'|i18n('design/standard/extract')}</label>
                    <textarea id="xe-schedule-params" name="Schedule[params]" rows="3" class="xe-wide" data-role="preset-params" placeholder="node=2">{$edit.params_text|wash}</textarea>
                    <p class="xe-help">{'One name=value per line; the defaults of the preset are used for any left out.'|i18n('design/standard/extract')}</p>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-preset-class">{'Class'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-preset-class" name="Schedule[preset_class]">
                        <option value="">{'The class of the preset'|i18n('design/standard/extract')}</option>
                        {foreach $classes as $class}<option value="{$class.identifier|wash}"{if and( $edit.kind|eq( 'preset' ), $edit.d_class|eq( $class.identifier ) )} selected="selected"{/if}>{$class.name|wash}</option>{/foreach}
                    </select>
                    <p class="xe-help">{'Only needed for a preset that leaves the class open.'|i18n('design/standard/extract')}</p>
                </div>
            </div>
        </div>

        {* A site archive *}
        <div class="xe-kind-panel" data-kind="archive">
            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-set">{'Node set'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-set" name="Schedule[set]">
                        {foreach $node_sets as $set}<option value="{$set.id|wash}"{if $edit.d_set|eq( $set.id )} selected="selected"{/if}>{$set.name|wash} ({$set.nodes|wash})</option>{/foreach}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-nodes">{'Or node ids'|i18n('design/standard/extract')}</label>
                    <input type="text" id="xe-schedule-nodes" name="Schedule[nodes]" value="{$edit.nodes_text|wash}" placeholder="2, 43" class="xe-wide" />
                    <p class="xe-help">{'Comma separated; used instead of the set when given.'|i18n('design/standard/extract')}</p>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-classes">{'Classes'|i18n('design/standard/extract')}</label>
                    <input type="text" id="xe-schedule-classes" name="Schedule[classes]" value="{$edit.classes_text|wash}" placeholder="article, folder" class="xe-wide" />
                    <p class="xe-help">{'Class identifiers, comma separated; empty: every class with objects.'|i18n('design/standard/extract')}</p>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-files">{'Files'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-files" name="Schedule[files]">
                        {foreach array( 'csv', 'json', 'xml' ) as $files}<option value="{$files}"{if $edit.d_files|eq( $files )} selected="selected"{/if}>{$files|upcase}</option>{/foreach}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-format">{'Archive'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-format" name="Schedule[format]">
                        {foreach $archive_formats as $format}<option value="{$format.id|wash}"{if $edit.d_format|eq( $format.id )} selected="selected"{/if}>{$format.name|wash}</option>{/foreach}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-columns">{'Columns'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-columns" name="Schedule[columns]">
                        <option value="standard"{if $edit.d_columns|eq( 'standard' )} selected="selected"{/if}>{'Standard'|i18n('design/standard/extract')}</option>
                        <option value="migration"{if $edit.d_columns|eq( 'migration' )} selected="selected"{/if}>{'Migration'|i18n('design/standard/extract')}</option>
                        <option value="attributes"{if $edit.d_columns|eq( 'attributes' )} selected="selected"{/if}>{'Attributes only'|i18n('design/standard/extract')}</option>
                    </select>
                    <label class="xe-check"><input type="checkbox" name="Schedule[plain_text]" value="1"{if $edit.d_plain_text} checked="checked"{/if} /> {'Plain text of rich text too'|i18n('design/standard/extract')}</label>
                </div>
            </div>
            <fieldset class="xe-field">
                <legend class="xe-label">{'Languages'|i18n('design/standard/extract')}</legend>
                <div class="xe-inline">
                {foreach $languages as $language}
                    <label class="xe-check"><input type="checkbox" name="Schedule[languages][]" value="{$language.locale|wash}"{if $edit.language_list|contains( $language.locale )} checked="checked"{/if} /> {$language.name|wash} <code>{$language.locale|wash}</code></label>
                {/foreach}
                </div>
                <p class="xe-help">{'None ticked: every language.'|i18n('design/standard/extract')}</p>
            </fieldset>
        </div>

        {* Export as package *}
        <div class="xe-kind-panel" data-kind="package">
            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-node">{'Node id'|i18n('design/standard/extract')}</label>
                    <input type="number" min="1" id="xe-schedule-node" name="Schedule[node]" value="{$edit.d_node|wash}" />
                    <label class="xe-check"><input type="checkbox" name="Schedule[subtree]" value="1"{if $edit.d_subtree} checked="checked"{/if} /> {'With its whole subtree'|i18n('design/standard/extract')}</label>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-package-class">{'Only this class'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-package-class" name="Schedule[package_class]">
                        <option value="">{'Every class'|i18n('design/standard/extract')}</option>
                        {foreach $classes as $class}<option value="{$class.identifier|wash}"{if and( $edit.kind|eq( 'package' ), $edit.d_class|eq( $class.identifier ) )} selected="selected"{/if}>{$class.name|wash}</option>{/foreach}
                    </select>
                    <p class="xe-help">{'A package is always exported in full; a delta run is not available for packages.'|i18n('design/standard/extract')}</p>
                </div>
            </div>
        </div>

        {* An import from a location *}
        <div class="xe-kind-panel" data-kind="import">
            <fieldset class="xe-field">
                <legend class="xe-label">{'Read the file from'|i18n('design/standard/extract')}</legend>
                <div class="xe-segmented">
                    <label><input type="radio" name="Schedule[source]" value="local"{if $edit.d_source|ne( 'destination' )} checked="checked"{/if} /><span>{'A folder on this server'|i18n('design/standard/extract')}</span></label>
                    <label><input type="radio" name="Schedule[source]" value="destination"{if $edit.d_source|eq( 'destination' )} checked="checked"{/if} /><span>{'A destination'|i18n('design/standard/extract')}</span></label>
                </div>
            </fieldset>
            <div class="xe-grid">
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-local">{'File on this server'|i18n('design/standard/extract')}</label>
                    <input type="text" id="xe-schedule-local" name="Schedule[local_path]" value="{$edit.d_local_path|wash}" class="xe-wide" placeholder="/mnt/nas/incoming/articles.csv" />
                    <p class="xe-help">{if $local_roots}{'Allowed below: %roots'|i18n('design/standard/extract',, hash( '%roots', $local_roots|implode( ', ' ) ))|wash}{else}{'No folder is allowed yet: xrowextract.ini [Destinations] LocalPathRoots[].'|i18n('design/standard/extract')|wash}{/if}</p>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-src-dest">{'Destination'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-src-dest" name="Schedule[destination_id]">
                        <option value="0">{'Choose a destination'|i18n('design/standard/extract')}</option>
                        {foreach $destination_choices as $choice}<option value="{$choice.id}"{if $edit.d_destination_id|eq( $choice.id )} selected="selected"{/if}>{$choice.name|wash}</option>{/foreach}
                    </select>
                    <label class="xe-label" for="xe-schedule-remote">{'File there'|i18n('design/standard/extract')}</label>
                    <input type="text" id="xe-schedule-remote" name="Schedule[remote_path]" value="{$edit.d_remote_path|wash}" class="xe-wide" placeholder="incoming/articles.csv" />
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-import-class">{'Class'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-import-class" name="Schedule[import_class]">
                        <option value="">{'From the file (manifest or class column)'|i18n('design/standard/extract')}</option>
                        {foreach $classes as $class}<option value="{$class.identifier|wash}"{if and( $edit.kind|eq( 'import' ), $edit.d_class|eq( $class.identifier ) )} selected="selected"{/if}>{$class.name|wash}</option>{/foreach}
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-parent">{'Parent node for new objects'|i18n('design/standard/extract')}</label>
                    <input type="number" min="1" id="xe-schedule-parent" name="Schedule[parent]" value="{$edit.d_parent|wash}" />
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-match">{'Match existing objects by'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-match" name="Schedule[match]">
                        <option value="remote_id"{if $edit.d_match|ne( 'object_id' )|and( $edit.d_match|ne( 'none' ) )} selected="selected"{/if}>{'Remote id'|i18n('design/standard/extract')}</option>
                        <option value="object_id"{if $edit.d_match|eq( 'object_id' )} selected="selected"{/if}>{'Object id'|i18n('design/standard/extract')}</option>
                        <option value="none"{if $edit.d_match|eq( 'none' )} selected="selected"{/if}>{'None: always create'|i18n('design/standard/extract')}</option>
                    </select>
                </div>
                <div class="xe-field">
                    <label class="xe-label" for="xe-schedule-language">{'Language of rows without one'|i18n('design/standard/extract')}</label>
                    <select id="xe-schedule-language" name="Schedule[language]">
                        <option value="">{'The site default'|i18n('design/standard/extract')}</option>
                        {foreach $languages as $language}<option value="{$language.locale|wash}"{if $edit.d_language|eq( $language.locale )} selected="selected"{/if}>{$language.name|wash}</option>{/foreach}
                    </select>
                </div>
            </div>
            <p class="xe-help">{'Every run does a dry run first; the import is applied only when the dry run found no errors. Both reports are kept with the job.'|i18n('design/standard/extract')}</p>
        </div>
    </section>

    <section class="xe-card" id="xe-schedule-when" aria-labelledby="xe-card-schedule-when">
        <header class="xe-card-head">
            <span class="xe-step" aria-hidden="true">2</span>
            <div>
                <h2 id="xe-card-schedule-when">{'When it runs'|i18n('design/standard/extract')}</h2>
                <p>{'In the time zone of the server. The cronjob part "xrowextract" starts it, or system cron with the line shown below the list.'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        <div class="xe-segmented" role="radiogroup">
            {foreach hash( 'hourly', 'Hourly'|i18n('design/standard/extract'), 'daily', 'Daily'|i18n('design/standard/extract'), 'weekly', 'Weekly'|i18n('design/standard/extract'), 'monthly', 'Monthly'|i18n('design/standard/extract'), 'cron', 'Cron expression'|i18n('design/standard/extract') ) as $kind => $label}
            <label><input type="radio" name="Schedule[frequency]" value="{$kind}" data-role="frequency"{if $edit.frequency.kind|eq( $kind )} checked="checked"{/if} /><span>{$label|wash}</span></label>
            {/foreach}
        </div>
        <div class="xe-grid xe-frequency-fields">
            <div class="xe-field" data-frequency="hourly">
                <label class="xe-label" for="xe-schedule-minute">{'At minute'|i18n('design/standard/extract')}</label>
                <input type="number" min="0" max="59" id="xe-schedule-minute" name="Schedule[minute]" value="{$edit.frequency.minute|wash}" />
            </div>
            <div class="xe-field" data-frequency="daily weekly monthly">
                <label class="xe-label" for="xe-schedule-time">{'At'|i18n('design/standard/extract')}</label>
                <input type="time" id="xe-schedule-time" name="Schedule[time]" value="{$edit.frequency.time|wash}" />
            </div>
            <div class="xe-field" data-frequency="weekly">
                <label class="xe-label" for="xe-schedule-weekday">{'On'|i18n('design/standard/extract')}</label>
                <select id="xe-schedule-weekday" name="Schedule[weekday]">
                    {foreach array( 'Sunday'|i18n('design/standard/extract'), 'Monday'|i18n('design/standard/extract'), 'Tuesday'|i18n('design/standard/extract'), 'Wednesday'|i18n('design/standard/extract'), 'Thursday'|i18n('design/standard/extract'), 'Friday'|i18n('design/standard/extract'), 'Saturday'|i18n('design/standard/extract') ) as $index => $day}
                    <option value="{$index}"{if $edit.frequency.weekday|eq( $index )} selected="selected"{/if}>{$day|wash}</option>
                    {/foreach}
                </select>
            </div>
            <div class="xe-field" data-frequency="monthly">
                <label class="xe-label" for="xe-schedule-monthday">{'On day'|i18n('design/standard/extract')}</label>
                <input type="number" min="1" max="31" id="xe-schedule-monthday" name="Schedule[monthday]" value="{$edit.frequency.monthday|wash}" />
                <p class="xe-help">{'A month without that day (31 in April) is skipped, as in cron.'|i18n('design/standard/extract')}</p>
            </div>
            <div class="xe-field" data-frequency="cron">
                <label class="xe-label" for="xe-schedule-expression">{'Cron expression'|i18n('design/standard/extract')}</label>
                <input type="text" id="xe-schedule-expression" name="Schedule[expression]" value="{$edit.frequency.expression|wash}" placeholder="30 2 * * 1-5" class="xe-mono" />
                <p class="xe-help">{'Five fields: minute, hour, day of month, month, day of week (0 or 7 is Sunday). Ranges 1-5, lists 1,15, steps */15.'|i18n('design/standard/extract')|wash}</p>
            </div>
        </div>
        <fieldset class="xe-field">
            <legend class="xe-label">{'Each run exports'|i18n('design/standard/extract')}</legend>
            <label class="xe-check"><input type="radio" name="Schedule[delta_mode]" value="full"{if $edit.delta_mode|ne( 'delta' )} checked="checked"{/if} /> {'Everything (full)'|i18n('design/standard/extract')}</label>
            <label class="xe-check"><input type="radio" name="Schedule[delta_mode]" value="delta"{if $edit.delta_mode|eq( 'delta' )} checked="checked"{/if} /> {'Only what changed since the last successful run (delta)'|i18n('design/standard/extract')}</label>
            <p class="xe-help">{'A delta run filters by modification date (it replaces a date filter of the preset); the first run is always full. "Run now" can still start a full run by hand.'|i18n('design/standard/extract')}</p>
        </fieldset>
    </section>

    <section class="xe-card" id="xe-schedule-deliver" aria-labelledby="xe-card-schedule-deliver">
        <header class="xe-card-head">
            <span class="xe-step" aria-hidden="true">3</span>
            <div>
                <h2 id="xe-card-schedule-deliver">{'Delivery and notifications'|i18n('design/standard/extract')}</h2>
                <p>{'Where the finished file goes, who hears about it, and how long it is kept.'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        <fieldset class="xe-field">
            <legend class="xe-label">{'Deliver to'|i18n('design/standard/extract')}</legend>
            {if $destination_choices|count|eq( 0 )}
            <p class="xe-help">{'No destinations yet.'|i18n('design/standard/extract')}{if $can_manage_destinations} <a href={'xrowextract/destinations'|ezurl}>{'Add one'|i18n('design/standard/extract')}</a>{/if}</p>
            {else}
            <div class="xe-inline">
                {foreach $destination_choices as $choice}
                <label class="xe-check"><input type="checkbox" name="Schedule[destinations][]" value="{$choice.id}"{if $edit.destination_ids|contains( $choice.id )} checked="checked"{/if} /> {$choice.name|wash}</label>
                {/foreach}
            </div>
            <p class="xe-help">{'The file and its manifest; retried with a growing pause when a delivery fails. The job folder keeps its copy either way.'|i18n('design/standard/extract')}</p>
            {/if}
        </fieldset>
        <div class="xe-grid">
            <div class="xe-field">
                <label class="xe-label" for="xe-schedule-failure">{'E-mail on failure, also to'|i18n('design/standard/extract')}</label>
                <input type="text" id="xe-schedule-failure" name="Schedule[failure_emails]" value="{$edit.notify.failure_emails|wash}" class="xe-wide" placeholder="ops@example.com" />
                <p class="xe-help">{'You (the owner) always get failures.'|i18n('design/standard/extract')}</p>
            </div>
            <div class="xe-field">
                <label class="xe-check"><input type="checkbox" name="Schedule[notify_success]" value="1"{if $edit.notify.success} checked="checked"{/if} /> {'E-mail on success too'|i18n('design/standard/extract')}</label>
                <label class="xe-label" for="xe-schedule-success">{'Also to'|i18n('design/standard/extract')}</label>
                <input type="text" id="xe-schedule-success" name="Schedule[success_emails]" value="{$edit.notify.success_emails|wash}" class="xe-wide" />
            </div>
            <div class="xe-field">
                <label class="xe-label" for="xe-schedule-webhook">{'Webhook (POST of the result as JSON)'|i18n('design/standard/extract')}</label>
                <input type="url" id="xe-schedule-webhook" name="Schedule[webhook_url]" value="{$edit.notify.webhook_url|wash}" class="xe-wide" placeholder="https://" />
                <label class="xe-check"><input type="checkbox" name="Schedule[admin_notice]" value="1"{if $edit.notify.admin_notice} checked="checked"{/if} /> {'Show failures as a notice and a badge on the Jobs tab'|i18n('design/standard/extract')}</label>
            </div>
            <div class="xe-field">
                <span class="xe-label">{'Keep'|i18n('design/standard/extract')}</span>
                <div class="xe-range">
                    <label>{'files'|i18n('design/standard/extract')} <input type="number" min="1" name="Schedule[files_days]" value="{$edit.retention.files_days|wash}" placeholder="{$file_retention_days}" /> {'days'|i18n('design/standard/extract')}</label>
                    <label>{'history'|i18n('design/standard/extract')} <input type="number" min="1" name="Schedule[history_days]" value="{$edit.retention.history_days|wash}" placeholder="{$history_retention_days}" /> {'days'|i18n('design/standard/extract')}</label>
                </div>
                <p class="xe-help">{'Empty: the defaults of csv.ini [Jobs] RetentionDays and xrowextract.ini [History] RetentionDays.'|i18n('design/standard/extract')|wash}</p>
            </div>
        </div>
        <div class="xe-toolbar xe-schedule-save">
            <input type="submit" class="defaultbutton" name="SaveSchedule" value="{'Save schedule'|i18n('design/standard/extract')|wash}" />
            <input type="submit" class="button" name="CancelEdit" value="{'Cancel'|i18n('design/standard/extract')|wash}" formnovalidate="formnovalidate" />
        </div>
    </section>
    </form>
    {/if}

    {* ---------------------------------------------------------------- the list *}
    <section class="xe-card" id="xe-schedules" aria-labelledby="xe-card-schedules">
        <header class="xe-card-head">
            <div>
                <h2 id="xe-card-schedules">{if $all_jobs}{'All schedules'|i18n('design/standard/extract')}{else}{'Your schedules'|i18n('design/standard/extract')}{/if}</h2>
                <p>{'Every run is a background job on the Jobs tab and a row in the History.'|i18n('design/standard/extract')}</p>
            </div>
            {if $edit|not}
            <form method="post" action={'xrowextract/schedules'|ezurl} class="xe-inline">
                <button type="submit" class="defaultbutton" name="NewSchedule" value="1">{'New schedule'|i18n('design/standard/extract')}</button>
            </form>
            {/if}
        </header>

        {if $schedules|count|eq( 0 )}
        <p class="xe-columns-empty">{'No schedules yet. A schedule runs a saved preset, a site archive, a package export or an import on its own.'|i18n('design/standard/extract')}</p>
        {else}
        <ul class="xe-jobs xe-schedules">
            {foreach $schedules as $s}
            <li id="schedule-{$s.id}" class="xe-job xe-schedule{if $s.enabled|not} xe-schedule-off{/if}">
                <div class="xe-job-main">
                    <span class="xe-job-type">{if $s.kind|eq( 'archive' )}{'Archive'|i18n('design/standard/extract')}{elseif $s.kind|eq( 'package' )}{'Package'|i18n('design/standard/extract')}{elseif $s.kind|eq( 'import' )}{'Import'|i18n('design/standard/extract')}{else}{'Preset'|i18n('design/standard/extract')}{/if}</span>
                    <span class="xe-colinfo">
                        <strong>{$s.name|wash}</strong>
                        <small>{$s.summary|wash}</small>
                    </span>
                    {def $owner = $s.owner_user}
                    {if $owner.node_id}<a class="xe-user" style="--xe-user-hue: {$owner.hue}" href={concat( 'content/view/full/', $owner.node_id )|ezurl} title="{'Owner: %name (%login)'|i18n('design/standard/extract',, hash( '%name', $owner.name, '%login', $owner.login ))|wash}">{else}<span class="xe-user xe-user-gone" style="--xe-user-hue: {$owner.hue}">{/if}
                        <span class="xe-user-avatar" aria-hidden="true">{$owner.initials|wash}</span>
                        <span class="xe-user-name">{$owner.name|wash}{if $s.mine} <small>({'you'|i18n('design/standard/extract')})</small>{/if}</span>
                    {if $owner.node_id}</a>{else}</span>{/if}
                    {undef $owner}
                    <span class="xe-badge{if $s.enabled} xe-state-done{else} xe-state-queued{/if}">{if $s.enabled}{'enabled'|i18n('design/standard/extract')}{else}{'disabled'|i18n('design/standard/extract')}{/if}</span>
                </div>
                <div class="xe-job-meta xe-schedule-meta">
                    <span>{$s.frequency_text|wash} <code>{$s.cron|wash}</code></span>
                    <span>{if $s.delta}<span class="xe-badge xe-badge-update">{'delta'|i18n('design/standard/extract')}</span>{else}<span class="xe-badge xe-badge-muted">{'full'|i18n('design/standard/extract')}</span>{/if}</span>
                    {if $s.destinations}<span>{'Delivers to'|i18n('design/standard/extract')} <strong>{$s.destinations|implode( ', ' )|wash}</strong></span>{/if}
                </div>
                <ol class="xe-job-times">
                    <li class="{if $s.last_run}xe-time-done{if or( $s.last_state|eq( 'failed' ), $s.last_state|eq( 'delivery_failed' ), $s.last_state|eq( 'skipped' ) )} xe-time-bad{/if}{else}xe-time-pending{/if}">
                        <span class="xe-time-label">{'Last run'|i18n('design/standard/extract')}</span>
                        {if $s.last_run}<time datetime="{$s.last_run|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$s.last_run|l10n( shortdatetime )}</time>{else}<time>—</time>{/if}
                        {if $s.last_state}<small>{$s.last_state|wash}{if $s.running} · {'running'|i18n('design/standard/extract')}{/if}</small>{/if}
                    </li>
                    <li class="{if $s.next_runs}xe-time-pending{else}xe-time-done{/if}">
                        <span class="xe-time-label">{'Next runs'|i18n('design/standard/extract')}</span>
                        {if $s.next_runs}{foreach $s.next_runs as $i => $t}{if $i|gt( 0 )}, {/if}<time datetime="{$t|datetime( 'custom', '%Y-%m-%dT%H:%i:%s' )}">{$t|l10n( shortdatetime )}</time>{/foreach}{else}<time>—</time>{/if}
                    </li>
                </ol>
                <details class="xe-crontab">
                    <summary>{'Run it from system cron instead'|i18n('design/standard/extract')}</summary>
                    <code class="xe-code-line">{$s.crontab|wash}</code>
                </details>
                <div class="xe-job-buttons">
                    <form method="post" action={'xrowextract/schedules'|ezurl} class="xe-inline">
                        <input type="hidden" name="RunMode" value="full" />
                        <button type="submit" class="button" name="RunScheduleID" value="{$s.id}"{if or( $s.running, $jobs_available|not )} disabled="disabled"{/if}>{'Run now'|i18n('design/standard/extract')}</button>
                    </form>
                    {if $s.kind|ne( 'package' )|and( $s.kind|ne( 'import' ) )}
                    <form method="post" action={'xrowextract/schedules'|ezurl} class="xe-inline">
                        <input type="hidden" name="RunMode" value="delta" />
                        <button type="submit" class="button" name="RunScheduleID" value="{$s.id}"{if or( $s.running, $jobs_available|not )} disabled="disabled"{/if} title="{'Only what changed since the last successful run'|i18n('design/standard/extract')|wash}">{'Run changes only'|i18n('design/standard/extract')}</button>
                    </form>
                    {/if}
                    <form method="post" action={'xrowextract/schedules'|ezurl} class="xe-inline">
                        <button type="submit" class="button" name="EditScheduleID" value="{$s.id}">{'Edit'|i18n('design/standard/extract')}</button>
                        {if $s.enabled}
                        <button type="submit" class="button" name="DisableScheduleID" value="{$s.id}">{'Disable'|i18n('design/standard/extract')}</button>
                        {else}
                        <button type="submit" class="button" name="EnableScheduleID" value="{$s.id}">{'Enable'|i18n('design/standard/extract')}</button>
                        {/if}
                        <button type="submit" class="button xe-icon-text" name="DeleteScheduleID" value="{$s.id}" data-confirm="{'Delete this schedule? Its history is kept.'|i18n('design/standard/extract')|wash}">{'Delete'|i18n('design/standard/extract')}</button>
                    </form>
                    {if $can_view_history}<a class="button" href={concat( 'xrowextract/history?schedule=', $s.id )|ezurl}>{'History'|i18n('design/standard/extract')}</a>{/if}
                </div>
            </li>
            {/foreach}
        </ul>
        {/if}
    </section>

    <section class="xe-card" id="xe-schedules-cron" aria-labelledby="xe-card-schedules-cron">
        <header class="xe-card-head">
            <div>
                <h2 id="xe-card-schedules-cron">{'How schedules are started'|i18n('design/standard/extract')}</h2>
                <p>{'Either line in the crontab of the user the site runs as; both can be used together (a run is never started twice at once).'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        <p class="xe-help">{'The cronjob part, every few minutes: starts every due schedule and cleans up old files and history.'|i18n('design/standard/extract')}</p>
        <code class="xe-code-line">{$cronjob_line|wash}</code>
        <p class="xe-help">{'Or one line per schedule (shown with each schedule above): system cron decides when it runs.'|i18n('design/standard/extract')}</p>
        <p class="xe-help">{'Command line: ext:xrowextract:schedule --list, --run=<id>, --enable=<id>, --disable=<id>, --cron, --crontab.'|i18n('design/standard/extract')|wash}</p>
    </section>

    </div>

    </div>
    {* DESIGN: Content END *}</div></div></div>
</div>
<script src={concat( 'javascript/xrowextract.js'|ezdesign( 'no' ), '?v=', $ScriptVersion )|wash}></script>
<script src={concat( 'javascript/xrowextract-schedules.js'|ezdesign( 'no' ), '?v=', $ScheduleScriptVersion )|wash}></script>
