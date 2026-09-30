{* A content package's own step on the import page, in place of "Class and matching" and "Column mapping": what the
   package carries, where its objects go, how existing objects and classes are treated, the dry run (at once for a
   small package, on request for a large one) and then the install, as a background job. *}
<section class="xe-card xe-package-review" id="xe-card-package-review" aria-labelledby="xe-card-package-review-h">
    <header class="xe-card-head">
        <span class="xe-step">2</span>
        <div>
            <h2 id="xe-card-package-review-h">{'Review and install the package'|i18n('design/standard/extract')}</h2>
            <p>{'See what the package would create, update or leave alone on this site, choose where it goes, then install it.'|i18n('design/standard/extract')}</p>
        </div>
    </header>

    {if $PackageImportError}
    <p class="xe-error">{$PackageImportError|wash}</p>
    {/if}

    {if $PackageSummary}
    <dl class="xe-import-target">
        <div class="xe-import-target-class">
            <dt>{'Package'|i18n('design/standard/extract')}</dt>
            <dd>
                <span class="xe-class-chip"><strong>{$PackageSummary.name|wash}</strong>
                    <small>{'%classes classes, %objects objects'|i18n('design/standard/extract',, hash( '%classes', $PackageSummary.classes, '%objects', $PackageSummary.objects ))}</small></span>
                {if $PackageSummary.summary|ne( '' )}<small>{$PackageSummary.summary|wash}</small>{/if}
                {if $PackageSummary.class_identifiers|count}<small>{'Classes:'|i18n('design/standard/extract')} {foreach $PackageSummary.class_identifiers as $identifier}<code>{$identifier|wash}</code> {/foreach}</small>{/if}
            </dd>
        </div>
    </dl>
    {* The datatype check: every datatype the package uses that this site does not have *}
    {include uri='design:xrowextract/package_datatype_check.tpl' missing=$PackageSummary.missing_datatypes}
    {* Not for a "Try a sample" package: it is in the repository only for this one request *}
    {if $IsPackageSample|not}
    <p class="xe-help"><a href={concat( 'xrowextract/package/', $PackageSummary.name )|ezurl}>{'Open it on the Package tab'|i18n('design/standard/extract')}</a>
        &middot; <a href={concat( 'xrowextract/compare/', $PackageSummary.name )|ezurl}>{'compare'|i18n('design/standard/extract')}</a>
        &middot; <a href={concat( 'xrowextract/browse/', $PackageSummary.name, '/', 0 )|ezurl}>{'browse its files'|i18n('design/standard/extract')}</a></p>
    {/if}
    {/if}

    <div class="xe-grid">
        <div class="xe-field">
            <span class="xe-label">{'Parent for the package’s objects'|i18n('design/standard/extract')}</span>
            <div class="xe-node">
                {if $ParentNode}
                <span class="xe-node-text">
                    <span class="xe-node-name">{$ParentNode.name|wash}</span>
                    <span class="xe-node-path">{$ParentNode.path|wash}</span>
                </span>
                {else}
                <span class="xe-node-text xe-node-missing">{'No node %id, or you may not read it.'|i18n('design/standard/extract',, hash( '%id', $ParentNodeID ))}</span>
                {/if}
                <input class="button" type="submit" name="BrowseParent" value="{'Change'|i18n('design/standard/extract')}" />
            </div>
            <input name="ParentNodeID" type="hidden" value="{$ParentNodeID|wash}" />
            <p class="xe-help">{'The package’s top-level objects are placed here; the objects below them keep their own structure.'|i18n('design/standard/extract')}</p>
        </div>
        <div class="xe-field">
            <span class="xe-label">{'An object that already exists'|i18n('design/standard/extract')}</span>
            <div class="xe-segmented" role="radiogroup" aria-label="{'An object that already exists'|i18n('design/standard/extract')|wash}">
                <label><input type="radio" name="PkgObjectMode" value="update"{if $PkgObjectMode|eq( 'update' )} checked{/if} /><span>{'Update it'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PkgObjectMode" value="skip"{if $PkgObjectMode|eq( 'skip' )} checked{/if} /><span>{'Leave it'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PkgObjectMode" value="new"{if $PkgObjectMode|eq( 'new' )} checked{/if} /><span>{'Add a copy'|i18n('design/standard/extract')}</span></label>
            </div>
            <p class="xe-help">{'Matched by remote id.'|i18n('design/standard/extract')}</p>
        </div>
        <div class="xe-field">
            <span class="xe-label">{'A class that already exists'|i18n('design/standard/extract')}</span>
            <div class="xe-segmented" role="radiogroup" aria-label="{'A class that already exists'|i18n('design/standard/extract')|wash}">
                <label><input type="radio" name="PkgClassMode" value="skip"{if $PkgClassMode|eq( 'skip' )} checked{/if} /><span>{'Keep the site’s'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PkgClassMode" value="replace"{if $PkgClassMode|eq( 'replace' )} checked{/if} /><span>{'Replace it'|i18n('design/standard/extract')}</span></label>
                <label><input type="radio" name="PkgClassMode" value="new"{if $PkgClassMode|eq( 'new' )} checked{/if} /><span>{'Add as new'|i18n('design/standard/extract')}</span></label>
            </div>
            <p class="xe-help">{'Matched by remote id, else by identifier.'|i18n('design/standard/extract')}</p>
        </div>
    </div>

    <div class="xe-toolbar">
        {if $PackageAutoReview|not}
        <p class="xe-help">{'A large package: the dry run compares every class and object with the site, which can take a minute. Nothing is written.'|i18n('design/standard/extract')}</p>
        {/if}
        <span class="xe-spacer"></span>
        <input class="defaultbutton" type="submit" name="Preview" value="{if or( $Preview, $PackageAutoReview )}{'Review again'|i18n('design/standard/extract')}{else}{'Review the package (dry run)'|i18n('design/standard/extract')}{/if}" />
    </div>
    {if or( $Preview, $Applied )|not}
    <p class="xe-note">{'Next: review the package. The dry run lists every class and object with what installing would do; the install itself then runs in the background, with its progress and log on the Jobs page.'|i18n('design/standard/extract')}</p>
    {/if}
</section>
