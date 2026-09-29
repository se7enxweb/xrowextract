{ezcss_require( 'xrowextract.css' )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Package'|i18n('design/standard/extract')}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='package'}
    <div class="xe-cards">

        {* 1. Pick a package *}
        <section class="xe-card" aria-labelledby="xe-card-pick">
            <header class="xe-card-head">
                <span class="xe-step">1</span>
                <div>
                    <h2 id="xe-card-pick">{'Pick a package'|i18n('design/standard/extract')}</h2>
                    <p>{'Upload an .ezpkg, or choose one already in the repository that carries a content class or content object.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            {if $UploadError}<p class="xe-error">{$UploadError|wash}</p>{/if}

            <form name="eZPackageUpload" method="post" enctype="multipart/form-data" action={'xrowextract/package'|ezurl}>
            <div class="xe-field">
                <label class="xe-label" for="xe-package-file">{'Upload an .ezpkg file'|i18n('design/standard/extract')}</label>
                <input type="file" name="PackageBinaryFile" id="xe-package-file" accept=".ezpkg" />
                <input class="defaultbutton" type="submit" name="UploadPackage" value="{'Upload'|i18n('design/standard/extract')}" />
                <p class="xe-help">{'It is added to the local package repository under its own name, then opened below for inspection - nothing is installed yet.'|i18n('design/standard/extract')}</p>
            </div>
            </form>

            {if $RepositoryPackages|count}
            <div class="xe-field">
                <span class="xe-label">{'Or choose one already in the repository'|i18n('design/standard/extract')}</span>
                <form name="eZPackagePick" method="post" action={'xrowextract/package'|ezurl}>
                <div class="xe-inline">
                    <select name="PackageName" aria-label="{'Package'|i18n('design/standard/extract')|wash}">
                        {foreach $RepositoryPackages as $repoPackage}
                        <option value="{$repoPackage.name|wash}"{if $repoPackage.name|eq( $PackageName )} selected{/if}>{$repoPackage.name|wash}{if $repoPackage.summary} — {$repoPackage.summary|wash}{/if}{if $repoPackage.is_installed} ({'installed'|i18n('design/standard/extract')}){/if}</option>
                        {/foreach}
                    </select>
                    <input class="button" type="submit" name="ChoosePackage" value="{'Open'|i18n('design/standard/extract')}" />
                </div>
                </form>
            </div>
            {/if}

            {if $Package}
            <p class="xe-note">{'Current package: %name'|i18n('design/standard/extract',, hash( '%name', concat( '<strong>', $PackageName|wash, '</strong>' ) ))}
                <a href={concat( 'package/view/full/', $PackageName )|ezurl} target="_blank" rel="noopener">{'full package view'|i18n('design/standard/extract')}</a>
                &middot; <a href={concat( 'package/export/', $PackageName )|ezurl}>{'download .ezpkg'|i18n('design/standard/extract')}</a>
                &middot; <a href={concat( 'package/install/', $PackageName )|ezurl}>{'full install wizard'|i18n('design/standard/extract')}</a>
                <form name="eZPackageForget" method="post" action={'xrowextract/package'|ezurl} style="display:inline">
                    <input class="button" type="submit" name="ForgetPackage" value="{'Forget'|i18n('design/standard/extract')}" />
                </form>
            </p>
            {/if}
        </section>

        {if $Package}
        {* 2. Inspection (dry run) *}
        <section class="xe-card xe-preview" aria-labelledby="xe-card-inspect">
            <header class="xe-preview-head">
                <div class="xe-preview-title">
                    <span class="xe-step">2</span>
                    <h2 id="xe-card-inspect">{'Inspection — nothing written'|i18n('design/standard/extract')}</h2>
                </div>
            </header>

            <div class="xe-field">
                <table class="xe-table" style="width: 100%">
                    <tr><th>{'Name'|i18n('design/standard/extract')}</th><td>{$Inspection.meta.name|wash}</td>
                        <th>{'Version'|i18n('design/standard/extract')}</th><td>{$Inspection.meta.version|wash}{if $Inspection.meta.release}-{$Inspection.meta.release|wash}{/if}</td></tr>
                    <tr><th>{'Summary'|i18n('design/standard/extract')}</th><td colspan="3">{$Inspection.meta.summary|wash}</td></tr>
                    {if $Inspection.meta.description}<tr><th>{'Description'|i18n('design/standard/extract')}</th><td colspan="3" class="xe-long">{$Inspection.meta.description|wash|nl2br}</td></tr>{/if}
                    <tr><th>{'Licence'|i18n('design/standard/extract')}</th><td>{$Inspection.meta.licence|wash}</td>
                        <th>{'Installed already'|i18n('design/standard/extract')}</th><td>{if $Inspection.meta.is_installed}{'yes'|i18n('design/standard/extract')}{else}{'no'|i18n('design/standard/extract')}{/if}</td></tr>
                </table>
            </div>

            {if $Inspection.meta.dependencies|count}
            <details>
                <summary>{'Dependencies'|i18n('design/standard/extract')} ({$Inspection.meta.dependencies|count})</summary>
                <table class="xe-table" style="width: 100%">
                    <thead><tr><th>{'Section'|i18n('design/standard/extract')}</th><th>{'Type'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Value'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    {foreach $Inspection.meta.dependencies as $dependency}
                    <tr><td>{$dependency.section|wash}</td><td><code>{$dependency.type|wash}</code></td><td>{$dependency.name|wash}</td><td>{$dependency.value|wash}</td></tr>
                    {/foreach}
                    </tbody>
                </table>
            </details>
            {/if}

            {if $Inspection.meta.changelog|count}
            <details>
                <summary>{'Changelog'|i18n('design/standard/extract')} ({$Inspection.meta.changelog|count})</summary>
                <ul>
                {foreach $Inspection.meta.changelog as $entry}
                    <li>{if $entry.timestamp}{$entry.timestamp|l10n(shortdate)} — {/if}{$entry.person|wash}: {$entry.changes|implode( '; ' )|wash}</li>
                {/foreach}
                </ul>
            </details>
            {/if}

            {if $Inspection.errors|count}
            {foreach $Inspection.errors as $error}<p class="xe-error">{$error|wash}</p>{/foreach}
            {/if}

            <ul class="xe-stats">
                <li class="xe-badge xe-badge-create"><strong>{$Inspection.counts.classes_create}</strong> {'classes: create'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-update"><strong>{$Inspection.counts.classes_update}</strong> {'classes: update'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-create"><strong>{$Inspection.counts.objects_create}</strong> {'objects: create'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-update"><strong>{$Inspection.counts.objects_update}</strong> {'objects: update'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-unchanged"><strong>{$Inspection.counts.objects_unchanged}</strong> {'objects: unchanged'|i18n('design/standard/extract')}</li>
                <li class="xe-badge xe-badge-error"><strong>{$Inspection.counts.objects_class_missing}</strong> {'objects: class missing'|i18n('design/standard/extract')}</li>
            </ul>

            {if $Inspection.classes|count}
            <div class="xe-scroll" tabindex="0">
                <table class="xe-table">
                    <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Identifier'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Attributes'|i18n('design/standard/extract')}</th><th>{'Remote id'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    {foreach $Inspection.classes as $classRow}
                    <tr>
                        <td><span class="xe-badge xe-badge-{$classRow.state}">{cond( $classRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'), 'update'|i18n('design/standard/extract') )}</span></td>
                        <td><code>{$classRow.identifier|wash}</code></td>
                        <td>{$classRow.name|wash}</td>
                        <td>{$classRow.attribute_count}</td>
                        <td class="xe-long">{$classRow.remote_id|wash}</td>
                    </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
            {/if}

            {if $Inspection.objects|count}
            <div class="xe-scroll" tabindex="0">
                <table class="xe-table">
                    <thead><tr><th>{'State'|i18n('design/standard/extract')}</th><th>{'Name'|i18n('design/standard/extract')}</th><th>{'Class'|i18n('design/standard/extract')}</th><th>{'Languages'|i18n('design/standard/extract')}</th><th>{'Existing object'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    {foreach $Inspection.objects as $objectRow}
                    <tr>
                        <td><span class="xe-badge xe-badge-{cond( $objectRow.state|eq( 'class_missing' ), 'error', $objectRow.state )}">{cond( $objectRow.state|eq( 'create' ), 'create'|i18n('design/standard/extract'),
                                                                                                                                       $objectRow.state|eq( 'update' ), 'update'|i18n('design/standard/extract'),
                                                                                                                                       $objectRow.state|eq( 'unchanged' ), 'unchanged'|i18n('design/standard/extract'),
                                                                                                                                       'class missing'|i18n('design/standard/extract') )}</span></td>
                        <td>{$objectRow.name|wash}</td>
                        <td><code>{$objectRow.class_identifier|wash}</code></td>
                        <td>{$objectRow.languages|implode( ', ' )|wash}</td>
                        <td>{if $objectRow.existing_id}<a href={concat( 'content/view/full/', $objectRow.existing_id )|ezurl} target="_blank" rel="noopener">#{$objectRow.existing_id}</a>{else}—{/if}</td>
                    </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
            {/if}
        </section>
        {/if}

        {if $InstallReport}
        <section class="xe-card xe-preview" aria-labelledby="xe-card-installed">
            <header class="xe-preview-head"><div class="xe-preview-title">
                <h2 id="xe-card-installed">{if $InstallReport.ok}{'Installed'|i18n('design/standard/extract')}{else}{'Install did not finish cleanly'|i18n('design/standard/extract')}{/if}</h2>
            </div></header>
            {foreach $InstallReport.errors as $error}<p class="xe-error">{$error|wash}</p>{/foreach}
            {if $InstallReport.created_classes|count}
            <p>{'Classes'|i18n('design/standard/extract')}:</p>
            <ul>
            {foreach $InstallReport.created_classes as $createdClass}
                <li><code>{$createdClass.identifier|wash}</code> — {$createdClass.name|wash} (<a href={concat( 'class/view/', $createdClass.id )|ezurl} target="_blank" rel="noopener">#{$createdClass.id}</a>)</li>
            {/foreach}
            </ul>
            {/if}
            {if $InstallReport.created_objects|count}
            <p>{'Content objects'|i18n('design/standard/extract')}:</p>
            <ul>
            {foreach $InstallReport.created_objects as $createdObject}
                <li>{$createdObject.name|wash} — {if $createdObject.node_id}<a href={concat( 'content/view/full/', $createdObject.node_id )|ezurl} target="_blank" rel="noopener">{'open'|i18n('design/standard/extract')}</a>{else}#{$createdObject.id}{/if}</li>
            {/foreach}
            </ul>
            {/if}
        </section>
        {/if}

        {if $Package}
        {* 3. Install *}
        <form name="eZPackageInstall" method="post" action={'xrowextract/package'|ezurl}>
        <input type="hidden" name="PackageName" value="{$PackageName|wash}" />
        <section class="xe-card" aria-labelledby="xe-card-install">
            <header class="xe-card-head">
                <span class="xe-step">3</span>
                <div>
                    <h2 id="xe-card-install">{'Install'|i18n('design/standard/extract')}</h2>
                    <p>{'Runs through eZPackage::install(), the same convenience method the kernel package/install view is built on.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            <div class="xe-field">
                <span class="xe-label">{'Parent for new objects'|i18n('design/standard/extract')}</span>
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
                <p class="xe-help">{'Every top-level object the package carries is placed here (browse.ini [ImportParentNode], the same group xrowextract/import uses).'|i18n('design/standard/extract')}</p>
            </div>

            <div class="xe-field">
                <label class="xe-label" for="xe-site-access">{'Site access'|i18n('design/standard/extract')}</label>
                <select name="SiteAccess" id="xe-site-access">
                    {foreach $AvailableSiteAccesses as $availableSiteAccess}
                    <option value="{$availableSiteAccess|wash}"{if $availableSiteAccess|eq( $SiteAccess )} selected{/if}>{$availableSiteAccess|wash}</option>
                    {/foreach}
                </select>
                <p class="xe-help">{'Where a design/template/override this package might carry would map to; content packages built by this tool carry none.'|i18n('design/standard/extract')}</p>
            </div>

            <div class="xe-field">
                <label class="xe-label" for="xe-object-mode">{'Existing objects (matched by remote id)'|i18n('design/standard/extract')}</label>
                <select name="ObjectMode" id="xe-object-mode">
                    <option value="skip"{if $ObjectMode|eq( 'skip' )} selected{/if}>{'Skip'|i18n('design/standard/extract')}</option>
                    <option value="update"{if $ObjectMode|eq( 'update' )} selected{/if}>{'Update in place'|i18n('design/standard/extract')}</option>
                    <option value="new"{if $ObjectMode|eq( 'new' )} selected{/if}>{'Keep both (new copy, new remote id)'|i18n('design/standard/extract')}</option>
                </select>
            </div>

            <div class="xe-field">
                <label class="xe-label" for="xe-class-mode">{'Existing classes (matched by remote id, then identifier)'|i18n('design/standard/extract')}</label>
                <select name="ClassMode" id="xe-class-mode">
                    <option value="skip"{if $ClassMode|eq( 'skip' )} selected{/if}>{'Skip'|i18n('design/standard/extract')}</option>
                    <option value="replace"{if $ClassMode|eq( 'replace' )} selected{/if}>{'Replace'|i18n('design/standard/extract')}</option>
                    <option value="new"{if $ClassMode|eq( 'new' )} selected{/if}>{'Keep both (new copy, new identifier)'|i18n('design/standard/extract')}</option>
                </select>
                <p class="xe-help">{'Replacing a class removes it and every object of it first; the confirmation for that lives in the full install wizard, not here.'|i18n('design/standard/extract')}</p>
            </div>

            <input class="defaultbutton" type="submit" name="Install" value="{'Install this package'|i18n('design/standard/extract')}" />
        </section>
        </form>
        {/if}

        {* 4. Package template *}
        <form name="eZPackageTemplate" method="post" action={'xrowextract/package'|ezurl}>
        <section class="xe-card" aria-labelledby="xe-card-template">
            <header class="xe-card-head">
                <span class="xe-step">4</span>
                <div>
                    <h2 id="xe-card-template">{'Package template'|i18n('design/standard/extract')}</h2>
                    <p>{'Builds a real, installable sample package for a class you choose, through the kernel package handlers.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            {if $TemplateError}<p class="xe-error">{$TemplateError|wash}</p>{/if}

            <div class="xe-field">
                <label class="xe-label" for="xe-template-class">{'Class'|i18n('design/standard/extract')}</label>
                <select name="TemplateClassID" id="xe-template-class">
                    {foreach $ClassChoices as $classChoice}
                    <option value="{$classChoice.id}"{if $classChoice.id|eq( $TemplateClassID )} selected{/if}>{$classChoice.name|wash} ({$classChoice.identifier|wash}) — {$classChoice.count} {'object(s)'|i18n('design/standard/extract')}</option>
                    {/foreach}
                </select>
                <p class="xe-help">{'The same class list the CSV/JSON import uses; when none was chosen yet, the class with the most content is preselected.'|i18n('design/standard/extract')}</p>
            </div>

            <div class="xe-field">
                <span class="xe-label">{'Variant'|i18n('design/standard/extract')}</span>
                <div class="xe-inline">
                    <label><input type="radio" name="TemplateVariant" value="class"{if $TemplateVariant|eq( 'class' )} checked{/if} /> {'Class only'|i18n('design/standard/extract')}</label>
                    <label><input type="radio" name="TemplateVariant" value="content"{if $TemplateVariant|eq( 'content' )} checked{/if} /> {'Content only'|i18n('design/standard/extract')}</label>
                    <label><input type="radio" name="TemplateVariant" value="both"{if $TemplateVariant|eq( 'both' )} checked{/if} /> {'Class + content'|i18n('design/standard/extract')}</label>
                </div>
                <p class="xe-help">{'Content only: the site installing it must already have this class. Class + content: everything needed is in the one package.'|i18n('design/standard/extract')}</p>
            </div>

            <input class="defaultbutton" type="submit" name="BuildTemplate" value="{'Build the sample package'|i18n('design/standard/extract')}" />
            <p class="xe-help">{'2-3 real content objects are created, exported into the package, then removed again; nothing from this step stays in the content tree. The result opens below for inspection.'|i18n('design/standard/extract')}</p>
        </section>
        </form>

        {* 5. Package template reference *}
        <section class="xe-card" aria-labelledby="xe-card-reference">
            <header class="xe-card-head">
                <div>
                    <h2 id="xe-card-reference">{'Package template reference'|i18n('design/standard/extract')}</h2>
                    <p>{'What a content package looks like on disk, every element package.xml carries, and how install-time matching works.'|i18n('design/standard/extract')}</p>
                </div>
            </header>

            <details open>
                <summary>{'Archive layout'|i18n('design/standard/extract')}</summary>
                <pre class="xe-code"><code>{literal}&lt;package-name&gt;/
  package.xml               # metadata + the install/uninstall item list
  ezcontentclass/
    &lt;filename&gt;.xml          # one file per class install item
  ezcontentobject/
    &lt;filename&gt;.xml          # object-list (few objects) or object-files-list
    object-&lt;remote-id&gt;.xml  # one file per object, once there are many
  simplefiles/                # files an ezimage/ezbinaryfile/ezmedia attribute stores
  documents/                 # free-form package documents (readme, licence text, ...)
  .cache/package.php          # a generated cache of the parsed package.xml; never edit
{/literal}</code></pre>
                <p class="xe-help">{'A "class only" template has only ezcontentclass/. A "content only" template has only ezcontentobject/ and simplefiles/. "Class + content" has all of it.'|i18n('design/standard/extract')}</p>
            </details>

            <details>
                <summary>{'package.xml: the elements that matter here'|i18n('design/standard/extract')}</summary>
                <table class="xe-table" style="width: 100%">
                    <thead><tr><th>{'Element'|i18n('design/standard/extract')}</th><th>{'Meaning'|i18n('design/standard/extract')}</th></tr></thead>
                    <tbody>
                    <tr><td><code>&lt;name&gt;</code></td><td>{'The id this package is known by in the repository (package/list, the picker on this page, ext:xrowextract:package). Unique per repository, not shown to the visitor.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;summary&gt;</code>, <code>&lt;description&gt;</code></td><td>{'Shown on the inspect screen and package/view.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;release&gt;&lt;version&gt;</code>, <code>&lt;release-nr&gt;</code></td><td>{'Version and release number; not compared against an installed copy automatically.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;licence&gt;</code>, <code>&lt;maintainers&gt;</code></td><td>{'Free text, shown on the inspect screen and package/view.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;changelog&gt;</code></td><td>{'One or more &lt;change&gt; entries: person, timestamp, and the change text(s). Purely informational.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;dependencies&gt;</code></td><td>{'provides/requires/obsoletes/conflicts. A "requires" of type ezpackage is installed first, automatically, by eZPackage::install(); other types are informational here.'|i18n('design/standard/extract')}</td></tr>
                    <tr><td><code>&lt;install&gt;</code></td><td>{'The ordered list of install items: type (ezcontentclass, ezcontentobject, ...), the file it reads its content from, and whether it also has an &lt;uninstall&gt; counterpart.'|i18n('design/standard/extract')}</td></tr>
                    </tbody>
                </table>
            </details>

            <details>
                <summary>{'Install-time matching and options'|i18n('design/standard/extract')}</summary>
                <ul>
                    <li>{'Classes match by remote id, falling back to identifier; a matched class is skipped, replaced, or kept alongside a new copy, per the class option above.'|i18n('design/standard/extract')}</li>
                    <li>{'Objects match by remote id only; a matched object is skipped, updated in place (its existing content is kept where the package does not touch it), or kept alongside a new copy with a freshly generated remote id.'|i18n('design/standard/extract')}</li>
                    <li>{'Languages: every &lt;object-translation&gt; the object carries is installed; a language not enabled on the installing site is added automatically if valid, otherwise that translation is skipped.'|i18n('design/standard/extract')}</li>
                    <li>{'Relations (ezobjectrelation/ezobjectrelationlist): stored as the remote id of the related object inside the package, so they resolve correctly even though object ids differ between sites. A relation to an object the package does not itself carry, and that does not already exist on the installing site, is silently left empty.'|i18n('design/standard/extract')}</li>
                    <li>{'Files (ezimage/ezbinaryfile/ezmedia): the actual file is copied into the simplefiles/ directory owned by that package, and copied back out on install; nothing is fetched from the exporting site at install time.'|i18n('design/standard/extract')}</li>
                    <li>{'Placement: every top-level object in the package (one with no parent already inside the same package) is created under the parent node chosen at install time; an object whose parent is another object in the same package keeps that relative placement.'|i18n('design/standard/extract')}</li>
                    <li>{'Site access: only relevant when the package also carries template overrides (a package built by this tool never does); it maps the site access name on the exporting site to one on the installing site.'|i18n('design/standard/extract')}</li>
                </ul>
            </details>

            <details>
                <summary>{'Annotated example, from a generated sample package'|i18n('design/standard/extract')}</summary>
                <p class="xe-side-lead">{'A shortened, real ezcontentobject/*.xml as the class + content variant writes it (an ezstring and an ezobjectrelationlist attribute shown; every other importable datatype follows the same shape).'|i18n('design/standard/extract')}</p>
                <pre class="xe-code"><code>{literal}&lt;object-list&gt;
  &lt;object remote_id="xrowextract-pkgtpl-ng_article-20260929120000-ab12cd-2"
          name="Sample title 2 (eng-US)"
          class_remote_id="..."
          ezobject:class_identifier="ng_article"
          ezobject:always_available="0"
          ezobject:modified="2026-09-29 12:00:00"&gt;
    &lt;version-list active_version="1"&gt;
      &lt;version ezobject:version="1" ezobject:status="1"&gt;
        &lt;object-translation language="eng-US"&gt;
          &lt;attribute identifier="title" type="ezstring"&gt;
            &lt;data-text&gt;Sample title 2 (eng-US)&lt;/data-text&gt;
          &lt;/attribute&gt;
          &lt;attribute identifier="related_content" type="ezobjectrelationlist"&gt;
            &lt;!-- resolved by remote id, not by object id --&gt;
            &lt;related-object-list&gt;
              &lt;related-object remote-id="xrowextract-pkgtpl-ng_article-20260929120000-ab12cd-1" /&gt;
            &lt;/related-object-list&gt;
          &lt;/attribute&gt;
        &lt;/object-translation&gt;
      &lt;/version&gt;
    &lt;/version-list&gt;
    &lt;node-assignment-list&gt;
      &lt;!-- no parent-node-remote-id: a top node, placed under the parent chosen at install time --&gt;
      &lt;node-assignment is-main-node="1" name="Sample title 2" node-id="0" remote-id="..." /&gt;
    &lt;/node-assignment-list&gt;
  &lt;/object&gt;
&lt;/object-list&gt;{/literal}</code></pre>
                <p class="xe-help">{'The remote ids the template builder assigns follow xrowextract-pkgtpl-&lt;class identifier&gt;-&lt;timestamp&gt;-&lt;object number&gt;, so a second sample for the same class never collides with the first on remote id.'|i18n('design/standard/extract')}</p>
            </details>

            <details>
                <summary>{'What each datatype gets'|i18n('design/standard/extract')}</summary>
                <ul class="xe-typelist">
                    <li><strong>{'Text'|i18n('design/standard/extract')}</strong> <code>ezstring, eztext, ezidentifier, ezurl, ezemail</code> — {'a short readable sample sentence or address, numbered per object.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Numbers'|i18n('design/standard/extract')}</strong> <code>ezinteger, ezfloat, ezboolean</code> — {'a different valid number/flag per object.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Dates'|i18n('design/standard/extract')}</strong> <code>ezdate, ezdatetime</code> — {'today plus a few days/hours per object.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Choice'|i18n('design/standard/extract')}</strong> <code>ezselection</code> — {'a real option of the class, chosen by id.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Keywords/tags'|i18n('design/standard/extract')}</strong> <code>ezkeyword, eztags</code> — {'a couple of sample keywords/tags (eztags creates them if they do not exist yet).'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Rich text'|i18n('design/standard/extract')}</strong> <code>ezxmltext</code> — {'a short real paragraph.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Files'|i18n('design/standard/extract')}</strong> <code>ezimage, ezbinaryfile, ezmedia</code> — {'a real bundled sample image/document, stored in the package.'|i18n('design/standard/extract')}</li>
                    <li><strong>{'Relations'|i18n('design/standard/extract')}</strong> <code>ezobjectrelation, ezobjectrelationlist</code> — {'the second and third sample object relate to the first, by remote id, when the class allows relating to its own kind.'|i18n('design/standard/extract')}</li>
                    <li>{'Any other datatype on the class keeps its class default; the built package still installs, that attribute just has no sample value.'|i18n('design/standard/extract')}</li>
                </ul>
            </details>
        </section>
    </div>

    {* DESIGN: Content END *}</div></div></div></div></div></div>
</div>
