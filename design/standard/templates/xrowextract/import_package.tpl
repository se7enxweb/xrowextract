{* RETIRED, unreferenced (task #27): a content package's dry run/install now renders through
   design:xrowextract/import_result.tpl instead, the same card XML/CSV/JSON rows use
   (XrowExtractPackage::inspectionToResultRows()) - "just like json, csv, xml", per the owner.
   No {include} of this file remains in import.tpl. Left on disk rather than deleted per this
   project's rm policy (one target per command, explicit approval first); safe to remove once
   that approval is given - nothing references it. *}
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

    {if $IsPackageSample}
    <p class="xe-note">{'This is a sample package built read-only from this site’s own content, for trying the importer - not a file you uploaded. It is not kept in the package repository and is removed again automatically unless you keep it. Installing it writes real content.'|i18n('design/standard/extract')}</p>
    <div class="xe-toolbar">
        <span class="xe-spacer"></span>
        <input class="button" type="submit" name="KeepSamplePackage" value="{'Keep in the repository'|i18n('design/standard/extract')}" />
    </div>
    {/if}

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

    <p class="xe-help">
        {'Class(es)'|i18n('design/standard/extract')}: <strong>{$PackageInspection.summary.classes|implode( ', ' )|wash}</strong>
        {if $PackageInspection.summary.new_objects_parent} · {'new objects go under'|i18n('design/standard/extract')}: <strong>{$PackageInspection.summary.new_objects_parent.path|wash}</strong>{/if}
        {if $PackageInspection.summary.languages|count} · {'language(s)'|i18n('design/standard/extract')}: <strong>{$PackageInspection.summary.languages|implode( ', ' )|wash}</strong>{/if}
        · {'matched by'|i18n('design/standard/extract')}: <strong>{$PackageInspection.summary.match_mode|wash}</strong>
    </p>

    {if $PackageInspection.classes|count}
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table">
            <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Identifier'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Attributes'|i18n('design/standard/extract')}</th><th>{'Attribute changes'|i18n('design/standard/extract')}</th></tr></thead>
            <tbody>
            {foreach $PackageInspection.classes as $classRow}
            <tr>
                <td><span class="xe-badge xe-badge-{$classRow.state}">{cond( $classRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'), 'update'|i18n('design/standard/extract') )}</span></td>
                <td><code>{$classRow.identifier|wash}</code></td>
                <td>{$classRow.name|wash}</td>
                <td>{$classRow.attribute_count}</td>
                <td>
                {if $classRow.diff.has_changes}
                    {if $classRow.diff.added|count}<span class="xe-badge xe-badge-create">+{$classRow.diff.added|count}</span>{/if}
                    {if $classRow.diff.removed|count}<span class="xe-badge xe-badge-error">-{$classRow.diff.removed|count}</span>{/if}
                    {if $classRow.diff.changed|count}<span class="xe-badge xe-badge-update">~{$classRow.diff.changed|count}</span>{/if}
                    <ul class="xe-typelist">
                    {foreach $classRow.diff.added as $addedAttr}<li>{'+ %id (%type)'|i18n('design/standard/extract',, hash( '%id', $addedAttr.identifier, '%type', $addedAttr.datatype ))}</li>{/foreach}
                    {foreach $classRow.diff.removed as $removedAttr}<li>{'- %id (%type)'|i18n('design/standard/extract',, hash( '%id', $removedAttr.identifier, '%type', $removedAttr.datatype ))}</li>{/foreach}
                    {foreach $classRow.diff.changed as $changedAttr}<li>{'~ %id: %old -> %new'|i18n('design/standard/extract',, hash( '%id', $changedAttr.identifier, '%old', $changedAttr.old_datatype, '%new', $changedAttr.new_datatype ))}</li>{/foreach}
                    </ul>
                {elseif $classRow.state|eq( 'update' )}
                    {'no attribute changes'|i18n('design/standard/extract')}
                {/if}
                </td>
            </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {if $PackageInspection.objects|count}
    <div class="xe-scroll" tabindex="0">
        <table class="xe-table">
            <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Class'|i18n('design/standard/extract')}</th><th>{'Languages'|i18n('design/standard/extract')}</th><th>{'Matched existing object'|i18n('design/standard/extract')}</th><th>{'Placement'|i18n('design/standard/extract')}</th><th>{'Field changes'|i18n('design/standard/extract')}</th></tr></thead>
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
                <td>{if $objectRow.existing}{if $objectRow.existing.node_id}<a href={concat( 'content/view/full/', $objectRow.existing.node_id )|ezurl} target="_blank" rel="noopener">{$objectRow.existing.name|wash} (#{$objectRow.existing.id})</a>{else}{$objectRow.existing.name|wash} (#{$objectRow.existing.id}){/if}{else}—{/if}</td>
                <td>{if $objectRow.placement}{$objectRow.placement.path|wash} ({cond( $objectRow.placement.reason|eq( 'new' ), 'new'|i18n('design/standard/extract'), 'current'|i18n('design/standard/extract') )}){else}—{/if}</td>
                <td>
                {if $objectRow.field_changes|count}
                    <ul class="xe-typelist">
                    {foreach $objectRow.field_changes as $change}<li><code>{$change.identifier|wash}</code> ({$change.language|wash}): {$change.old|wash} → {$change.new|wash}</li>{/foreach}
                    </ul>
                {elseif $objectRow.state|eq( 'update' )}
                    {'no field changes among the comparable datatypes'|i18n('design/standard/extract')}
                {/if}
                </td>
            </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {/if}

    {if $PackageInspection.files|count}
    <div class="xe-field">
        <span class="xe-label">{'Files this package carries'|i18n('design/standard/extract')}</span>
        <div class="xe-scroll" tabindex="0">
            <table class="xe-table">
                <thead><tr><th>{'Path'|i18n('design/standard/extract')}</th><th>{'Size'|i18n('design/standard/extract')}</th></tr></thead>
                <tbody>
                {foreach $PackageInspection.files as $packageFile}
                <tr><td><code>{$packageFile.path|wash}</code></td><td>{$packageFile.size|wash} {'bytes'|i18n('design/standard/extract')}</td></tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
    {/if}

    {if $PackageInspection.meta.dependencies|count}
    <div class="xe-field">
        <span class="xe-label">{'Dependencies'|i18n('design/standard/extract')}</span>
        <ul>
        {foreach $PackageInspection.meta.dependencies as $dependency}
            <li>{$dependency.section|wash}: {$dependency.type|wash} <code>{$dependency.name|wash}</code>{if $dependency.value} ({$dependency.value|wash}){/if}</li>
        {/foreach}
        </ul>
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
        <input class="defaultbutton" type="submit" name="InstallPackage" value="{'Install this package'|i18n('design/standard/extract')}"{if $IsPackageSample} data-confirm="{'This is a sample package: installing it writes real content to the site. Continue?'|i18n('design/standard/extract')|wash}"{/if} />
        {if $PackageJobsAvailable}
        <input class="button" type="submit" name="RunPackageInBackground" value="{'Install as a background job'|i18n('design/standard/extract')}"{if $IsPackageSample} data-confirm="{'This is a sample package: installing it writes real content to the site. Continue?'|i18n('design/standard/extract')|wash}"{/if} />
        {/if}
    </div>
    {/if}
</section>
{/if}
