{ezcss_require( array( 'xrowextract.css', 'xrowextract-schedules.css' ) )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Extract settings'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='schedules'}
    {include uri='design:xrowextract/schedules_nav.tpl' part='destinations'}

    {if $notice}<p class="xe-note" role="status">{$notice|wash}</p>{/if}
    {if $errors}
    <div class="xe-error" role="alert">
        <strong>{'The destination was not saved:'|i18n('design/standard/extract')}</strong>
        <ul>{foreach $errors as $error}<li>{$error|wash}</li>{/foreach}</ul>
    </div>
    {/if}
    {if $secrets_available|not}
    <p class="xe-note xe-note-bad">{'The PHP sodium extension is not available: passwords and keys cannot be stored.'|i18n('design/standard/extract')}</p>
    {/if}

    <div class="xe-cards">

    {if $edit}
    <form method="post" action={'xrowextract/destinations'|ezurl} autocomplete="off">
    <input type="hidden" name="Destination[id]" value="{$edit.id}" />
    <input type="hidden" name="Destination[type]" value="{$edit.form.type|wash}" />
    <section class="xe-card" id="xe-destination-edit" aria-labelledby="xe-card-destination-edit">
        <header class="xe-card-head">
            <div>
                <h2 id="xe-card-destination-edit">{if $edit.id}{'Change the destination'|i18n('design/standard/extract')}{else}{'New destination'|i18n('design/standard/extract')}{/if}: {$edit.form.type_name|wash}</h2>
                <p>{'Passwords and keys are stored encrypted and never shown again: leave a field empty to keep what is stored.'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        {if $edit.form.unavailable}<p class="xe-note xe-note-bad">{'This kind of destination is not available on this server: %reason'|i18n('design/standard/extract',, hash( '%reason', $edit.form.unavailable ))|wash}</p>{/if}
        {if $edit.form.type|eq( 'ftp' )}<p class="xe-note">{'Plain FTP sends the user, the password and the file unencrypted. Choose FTPS whenever the server offers it, or use SFTP.'|i18n('design/standard/extract')}</p>{/if}
        {if $edit.form.type|eq( 'local' )}<p class="xe-help">{if $local_roots}{'The folder must be below: %roots'|i18n('design/standard/extract',, hash( '%roots', $local_roots|implode( ', ' ) ))|wash}{else}{'No folder is allowed yet: set xrowextract.ini [Destinations] LocalPathRoots[] first.'|i18n('design/standard/extract')|wash}{/if}</p>{/if}
        <div class="xe-grid">
            <div class="xe-field">
                <label class="xe-label" for="xe-destination-name">{'Name'|i18n('design/standard/extract')}</label>
                <input type="text" id="xe-destination-name" name="Destination[name]" value="{$edit.name|wash}" maxlength="150" required="required" class="xe-wide" />
            </div>
            {foreach $edit.form.fields as $field}
            <div class="xe-field">
                {if $field.kind|eq( 'bool' )}
                <span class="xe-label">{$field.label|wash}</span>
                <input type="hidden" name="Destination[config][{$field.name|wash}]" value="0" />
                <label class="xe-check"><input type="checkbox" name="Destination[config][{$field.name|wash}]" value="1"{if $field.value|eq( '1' )} checked="checked"{/if} /> {$field.label|wash}</label>
                {elseif $field.kind|eq( 'select' )}
                <label class="xe-label" for="xe-destination-{$field.name|wash}">{$field.label|wash}</label>
                <select id="xe-destination-{$field.name|wash}" name="Destination[config][{$field.name|wash}]">
                    {foreach $field.options as $option}<option value="{$option.value|wash}"{if $option.value|eq( $field.value )} selected="selected"{/if}>{$option.label|wash}</option>{/foreach}
                </select>
                {else}
                <label class="xe-label" for="xe-destination-{$field.name|wash}">{$field.label|wash}</label>
                <input type="{if $field.kind|eq( 'number' )}number{else}text{/if}" id="xe-destination-{$field.name|wash}" name="Destination[config][{$field.name|wash}]" value="{$field.value|wash}" class="xe-wide" />
                {/if}
            </div>
            {/foreach}
        </div>
        {if $edit.form.secrets}
        <fieldset class="xe-field xe-secrets">
            <legend class="xe-label">{'Credentials'|i18n('design/standard/extract')}</legend>
            <div class="xe-grid">
            {foreach $edit.form.secrets as $secret}
            <div class="xe-field xe-secret">
                <label class="xe-label" for="xe-secret-{$secret.name|wash}">{$secret.label|wash}
                    {if $secret.is_set}<span class="xe-badge xe-state-done">{'set'|i18n('design/standard/extract')}</span>{else}<span class="xe-badge xe-badge-muted">{'not set'|i18n('design/standard/extract')}</span>{/if}</label>
                {if $secret.multiline}
                <textarea id="xe-secret-{$secret.name|wash}" name="Destination[secret][{$secret.name|wash}]" rows="4" class="xe-wide xe-mono" spellcheck="false" placeholder="{if $secret.is_set}{'Paste a new one to replace it'|i18n('design/standard/extract')|wash}{else}{'Paste it here'|i18n('design/standard/extract')|wash}{/if}"></textarea>
                {else}
                <input type="password" id="xe-secret-{$secret.name|wash}" name="Destination[secret][{$secret.name|wash}]" value="" autocomplete="new-password" class="xe-wide" placeholder="{if $secret.is_set}{'Type a new one to replace it'|i18n('design/standard/extract')|wash}{else}{'Set it'|i18n('design/standard/extract')|wash}{/if}" />
                {/if}
                {if $secret.is_set}<label class="xe-check"><input type="checkbox" name="Destination[clear][{$secret.name|wash}]" value="1" /> {'Clear it'|i18n('design/standard/extract')}</label>{/if}
            </div>
            {/foreach}
            </div>
        </fieldset>
        {/if}
        <div class="xe-toolbar">
            <input type="submit" class="defaultbutton" name="SaveDestination" value="{'Save destination'|i18n('design/standard/extract')|wash}" />
            <input type="submit" class="button" name="CancelEdit" value="{'Cancel'|i18n('design/standard/extract')|wash}" formnovalidate="formnovalidate" />
        </div>
    </section>
    </form>
    {/if}

    <section class="xe-card" id="xe-destinations" aria-labelledby="xe-card-destinations">
        <header class="xe-card-head">
            <div>
                <h2 id="xe-card-destinations">{'Destinations'|i18n('design/standard/extract')}</h2>
                <p>{'Where scheduled exports deliver their files, and where a scheduled import can read one from.'|i18n('design/standard/extract')}</p>
            </div>
        </header>
        {if $edit|not}
        <form method="post" action={'xrowextract/destinations'|ezurl} class="xe-inline xe-new-destination">
            <label for="xe-new-destination-type">{'New destination of the kind'|i18n('design/standard/extract')}</label>
            <select id="xe-new-destination-type" name="NewDestinationType">
                {foreach $type_choices as $choice}<option value="{$choice.type|wash}">{$choice.name|wash}{if $choice.unavailable} ({'not available here'|i18n('design/standard/extract')}){/if}</option>{/foreach}
            </select>
            <button type="submit" class="defaultbutton" name="NewDestination" value="1">{'Add'|i18n('design/standard/extract')}</button>
        </form>
        {/if}

        {if $destinations|count|eq( 0 )}
        <p class="xe-columns-empty">{'No destinations yet.'|i18n('design/standard/extract')}</p>
        {else}
        <ul class="xe-jobs xe-destinations">
            {foreach $destinations as $d}
            <li id="destination-{$d.id}" class="xe-job">
                <div class="xe-job-main">
                    <span class="xe-job-type">{$d.type|upcase|wash}</span>
                    <span class="xe-colinfo">
                        <strong>{$d.name|wash}</strong>
                        <small><code>{$d.summary|wash}</code> · {$d.type_name|wash}</small>
                    </span>
                    {if $d.unencrypted}<span class="xe-badge xe-badge-error" title="{'The connection is not encrypted'|i18n('design/standard/extract')|wash}">{'unencrypted'|i18n('design/standard/extract')}</span>{/if}
                    {if $d.last_test}
                    <span class="xe-badge {if $d.last_test.ok}xe-state-done{else}xe-state-failed{/if}" title="{$d.last_test.message|wash}">{if $d.last_test.ok}{'test ok'|i18n('design/standard/extract')}{else}{'test failed'|i18n('design/standard/extract')}{/if} · {$d.last_test.time|l10n( shortdatetime )}</span>
                    {else}
                    <span class="xe-badge xe-badge-muted">{'never tested'|i18n('design/standard/extract')}</span>
                    {/if}
                </div>
                <div class="xe-job-meta">
                    <span>{'Credentials'|i18n('design/standard/extract')}: {if $d.secret_names}{$d.secret_names|implode( ', ' )|wash} ({'set'|i18n('design/standard/extract')}){else}{'none'|i18n('design/standard/extract')}{/if}</span>
                    {if $d.host_key_trusted|ne( null )}<span>{'Host key'|i18n('design/standard/extract')}: {if $d.host_key_trusted}{'trusted'|i18n('design/standard/extract')}{else}<strong>{'not trusted yet'|i18n('design/standard/extract')}</strong>{/if}</span>{/if}
                    {if $d.used_by}<span>{'Used by'|i18n('design/standard/extract')} {$d.used_by|implode( ', ' )|wash}</span>{/if}
                    {if $d.unavailable}<span class="xe-node-missing">{'Not available on this server: %reason'|i18n('design/standard/extract',, hash( '%reason', $d.unavailable ))|wash}</span>{/if}
                </div>
                {if $d.test}
                <p class="xe-note{if $d.test.ok|not} xe-note-bad{/if}" role="status">{$d.test.message|wash}</p>
                {if $d.test.keys}
                <div class="xe-hostkeys">
                    <p>{'Compare the fingerprint with the one the server administrator gives you, then trust it:'|i18n('design/standard/extract')}</p>
                    {foreach $d.test.keys as $key}
                    <form method="post" action={'xrowextract/destinations'|ezurl} class="xe-inline">
                        <code class="xe-code-line">{$key.fingerprint|wash}</code>
                        <input type="hidden" name="HostKeyFingerprint" value="{$key.fingerprint|wash}" />
                        <button type="submit" class="button" name="TrustHostKey" value="{$d.id}">{'Trust this host key'|i18n('design/standard/extract')}</button>
                    </form>
                    {/foreach}
                </div>
                {/if}
                {/if}
                <div class="xe-job-buttons">
                    <form method="post" action={'xrowextract/destinations'|ezurl} class="xe-inline">
                        <button type="submit" class="button" name="TestDestinationID" value="{$d.id}">{'Test connection'|i18n('design/standard/extract')}</button>
                        <button type="submit" class="button" name="EditDestinationID" value="{$d.id}">{'Edit'|i18n('design/standard/extract')}</button>
                        <button type="submit" class="button xe-icon-text" name="DeleteDestinationID" value="{$d.id}" data-confirm="{if $d.used_by}{'Schedules still deliver here. Delete this destination anyway?'|i18n('design/standard/extract')|wash}{else}{'Delete this destination and its stored credentials?'|i18n('design/standard/extract')|wash}{/if}">{'Delete'|i18n('design/standard/extract')}</button>
                    </form>
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
<script src={concat( 'javascript/xrowextract-schedules.js'|ezdesign( 'no' ), '?v=', $ScheduleScriptVersion )|wash}></script>
