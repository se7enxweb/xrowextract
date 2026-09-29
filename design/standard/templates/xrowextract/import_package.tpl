{* A content package (.ezpkg/.tar.gz), or a standalone content-class/content-object XML file
   (wrapped as a transient package - see XrowExtractPackage::wrapStandaloneXML()), uploaded
   through the same "File" step as a row CSV/XML/JSON file. Self-contained: renders nothing
   unless $PackageMode is set, so this is the only line the Import view itself needs
   ({include uri='design:xrowextract/import_package.tpl'}). Reuses the "Parent for new
   objects" field from the "Class and matching" card above for where a package's own
   top-level objects go; everything else (site access, existing-class/object handling,
   inspection, install, background job) lives here. *}
{if $PackageMode}
<section class="xe-card xe-preview" aria-labelledby="xe-card-package">
    <header class="xe-preview-head">
        <div class="xe-preview-title">
            <h2 id="xe-card-package">{cond( $PackageKind|eq( 'package' ), 'Content package (.ezpkg)'|i18n('design/standard/extract'),
                                              $PackageKind|eq( 'contentclass' ), 'Content class definition'|i18n('design/standard/extract'),
                                              'Content object(s)'|i18n('design/standard/extract') )}</h2>
        </div>
    </header>

    {if $PackageJobError}<p class="xe-error">{$PackageJobError|wash}</p>{/if}

    {if $PackageInspection}
    <div class="xe-field">
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table" style="width: 100%">
            <tr><th>{'Name'|i18n('design/standard/extract')}</th><td class="xe-long">{$PackageInspection.meta.name|wash}</td>
                <th>{'Version'|i18n('design/standard/extract')}</th><td>{$PackageInspection.meta.version|wash}{if $PackageInspection.meta.release}-{$PackageInspection.meta.release|wash}{/if}</td></tr>
            <tr><th>{'Summary'|i18n('design/standard/extract')}</th><td colspan="3" class="xe-long">{$PackageInspection.meta.summary|wash}</td></tr>
        </table>
    </div>
    </div>

    {if $PackageInspection.errors|count}
    {foreach $PackageInspection.errors as $error}<p class="xe-error">{$error|wash}</p>{/foreach}
    {/if}

    <ul class="xe-stats">
        <li class="xe-badge xe-badge-create"><strong>{$PackageInspection.counts.classes_create}</strong> {'classes: create'|i18n('design/standard/extract')}</li>
        <li class="xe-badge xe-badge-update"><strong>{$PackageInspection.counts.classes_update}</strong> {'classes: update'|i18n('design/standard/extract')}</li>
        <li class="xe-badge xe-badge-create"><strong>{$PackageInspection.counts.objects_create}</strong> {'objects: create'|i18n('design/standard/extract')}</li>
        <li class="xe-badge xe-badge-update"><strong>{$PackageInspection.counts.objects_update}</strong> {'objects: update'|i18n('design/standard/extract')}</li>
        <li class="xe-badge xe-badge-unchanged"><strong>{$PackageInspection.counts.objects_unchanged}</strong> {'objects: unchanged'|i18n('design/standard/extract')}</li>
        <li class="xe-badge xe-badge-error"><strong>{$PackageInspection.counts.objects_class_missing}</strong> {'objects: class missing'|i18n('design/standard/extract')}</li>
    </ul>

    {if $PackageInspection.classes|count}
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table">
            <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Identifier'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Attributes'|i18n('design/standard/extract')}</th></tr></thead>
            <tbody>
            {foreach $PackageInspection.classes as $classRow}
            <tr>
                <td><span class="xe-badge xe-badge-{$classRow.state}">{cond( $classRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'), 'update'|i18n('design/standard/extract') )}</span></td>
                <td><code>{$classRow.identifier|wash}</code></td>
                <td>{$classRow.name|wash}</td>
                <td>{$classRow.attribute_count}</td>
            </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {if $PackageInspection.objects|count}
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table">
            <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Class'|i18n('design/standard/extract')}</th><th>{'Languages'|i18n('design/standard/extract')}</th></tr></thead>
            <tbody>
            {foreach $PackageInspection.objects as $objectRow}
            <tr>
                <td><span class="xe-badge xe-badge-{cond( $objectRow.state|eq( 'class_missing' ), 'error', $objectRow.state )}">{cond( $objectRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'),
                                                                                                                                       $objectRow.state|eq( 'update' ), 'update'|i18n('design/standard/extract'),
                                                                                                                                       $objectRow.state|eq( 'unchanged' ), 'unchanged'|i18n('design/standard/extract'),
                                                                                                                                       'class missing'|i18n('design/standard/extract') )}</span></td>
                <td>{$objectRow.name|wash}</td>
                <td><code>{$objectRow.class_identifier|wash}</code></td>
                <td>{$objectRow.languages|implode( ', ' )|wash}</td>
            </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}
    {else}
    <p class="xe-error">{'Could not open %name as a package.'|i18n('design/standard/extract',, hash( '%name', $UploadedName|wash ))}</p>
    {/if}

    {if $PackageInstallReport}
    <div class="xe-field">
        <p><strong>{if $PackageInstallReport.ok}{'Installed'|i18n('design/standard/extract')}{else}{'Install did not finish cleanly'|i18n('design/standard/extract')}{/if}</strong></p>
        {foreach $PackageInstallReport.errors as $error}<p class="xe-error">{$error|wash}</p>{/foreach}
        {if $PackageInstallReport.created_classes|count}
        <p>{'Classes'|i18n('design/standard/extract')}:</p>
        <ul>
        {foreach $PackageInstallReport.created_classes as $createdClass}
            <li><code>{$createdClass.identifier|wash}</code> — {$createdClass.name|wash} (<a href={concat( 'class/view/', $createdClass.id )|ezurl} target="_blank" rel="noopener">#{$createdClass.id}</a>)</li>
        {/foreach}
        </ul>
        {/if}
        {if $PackageInstallReport.created_objects|count}
        <p>{'Content objects'|i18n('design/standard/extract')}:</p>
        <ul>
        {foreach $PackageInstallReport.created_objects as $createdObject}
            <li>{$createdObject.name|wash} — {if $createdObject.node_id}<a href={concat( 'content/view/full/', $createdObject.node_id )|ezurl} target="_blank" rel="noopener">{'open'|i18n('design/standard/extract')}</a>{else}#{$createdObject.id}{/if}</li>
        {/foreach}
        </ul>
        {/if}
    </div>
    {/if}

    {if $Package}
    <div class="xe-grid">
        <div class="xe-field">
            <label class="xe-label" for="xe-package-site-access">{'Site access'|i18n('design/standard/extract')}</label>
            <select name="PackageSiteAccess" id="xe-package-site-access">
                {foreach $PackageAvailableSiteAccesses as $availableSiteAccess}
                <option value="{$availableSiteAccess|wash}"{if $availableSiteAccess|eq( $PackageSiteAccess )} selected{/if}>{$availableSiteAccess|wash}</option>
                {/foreach}
            </select>
            <p class="xe-help">{'Where a design/template/override this package might carry would map to.'|i18n('design/standard/extract')}</p>
        </div>

        <div class="xe-field">
            <span class="xe-label" id="xe-package-object-mode-label">{'Existing objects (matched by remote id)'|i18n('design/standard/extract')}</span>
            <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-package-object-mode-label">
                <label><input type="radio" name="PackageObjectMode" value="skip"{if $PackageObjectMode|eq( 'skip' )} checked{/if} /><span>{'Skip'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PackageObjectMode" value="update"{if $PackageObjectMode|eq( 'update' )} checked{/if} /><span>{'Update'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PackageObjectMode" value="new"{if $PackageObjectMode|eq( 'new' )} checked{/if} /><span>{'Keep both'|i18n('design/standard/extract')}</span></label>
            </div>
        </div>

        <div class="xe-field">
            <span class="xe-label" id="xe-package-class-mode-label">{'Existing classes (matched by remote id, then identifier)'|i18n('design/standard/extract')}</span>
            <div class="xe-segmented" role="radiogroup" aria-labelledby="xe-package-class-mode-label">
                <label><input type="radio" name="PackageClassMode" value="skip"{if $PackageClassMode|eq( 'skip' )} checked{/if} /><span>{'Skip'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PackageClassMode" value="replace"{if $PackageClassMode|eq( 'replace' )} checked{/if} /><span>{'Replace'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PackageClassMode" value="new"{if $PackageClassMode|eq( 'new' )} checked{/if} /><span>{'Keep both'|i18n('design/standard/extract')}</span></label>
            </div>
        </div>
    </div>
    <div class="xe-toolbar">
        <p class="xe-help xe-spacer">{'Uses the parent chosen in "Class and matching" above for any top-level object the package carries.'|i18n('design/standard/extract')}</p>
        <input class="defaultbutton" type="submit" name="InstallPackage" value="{'Install this package'|i18n('design/standard/extract')}" />
        {if $PackageJobsAvailable}
        <input class="button" type="submit" name="RunPackageInBackground" value="{'Install as a background job'|i18n('design/standard/extract')}" />
        {/if}
    </div>
    {/if}
</section>
{/if}
