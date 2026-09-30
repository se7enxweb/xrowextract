{ezcss_require( 'xrowextract.css' )}
<div class="context-block xe-view">

    {* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

    <h1 class="context-title">{'Package contents: %name'|i18n('design/standard/extract',, hash( '%name', $PackageName ))}</h1>

    {* DESIGN: Mainline *}<div class="header-mainline"></div>

    {* DESIGN: Header END *}</div></div></div></div></div></div>

    {* DESIGN: Content START *}<div class="box-ml"><div class="box-mr"><div class="box-content">

    <div class="context-attributes">

    {include uri='design:xrowextract/tabs.tpl' active='package'}

    <p><a href={concat( 'xrowextract/package/', $PackageName )|ezurl}>&laquo; {'Back to %name'|i18n('design/standard/extract',, hash( '%name', $PackageName ))}</a></p>

    <p class="xe-help">{'%count files, %from to %to shown.'|i18n('design/standard/extract',, hash(
        '%count', $FilesTotal, '%from', $FilesShownFrom, '%to', $FilesShownTo ))}</p>

    {if $ViewedFile}
    <section class="xe-card" aria-labelledby="xe-viewer-h">
        <header class="xe-card-head">
            <div>
                <h2 id="xe-viewer-h"><code>{$ViewedFile.path|wash}</code></h2>
                <p>{$ViewedFile.size_human|wash} &middot; {$ViewedFile.kind|wash}</p>
            </div>
        </header>
        {if $ViewedFile.kind|eq( 'image' )}
        <p><img class="xe-file-preview-image" src={concat( 'xrowextract/browse_file/', $PackageName, '/', $ViewedFile.index )|ezurl} alt="{$ViewedFile.path|wash}" /></p>
        {else}
        <pre class="xe-file-preview-text">{$ViewedContent|wash}</pre>
        {/if}
        <p><a class="button" href={concat( 'xrowextract/browse/', $PackageName, '/', $FilesOffset )|ezurl}>{'Close'|i18n('design/standard/extract')}</a></p>
    </section>
    {/if}

    {include uri='design:xrowextract/package_files_rows.tpl'}

    <nav class="xe-pagination">
        {if $FilesHasPrevious}
        <a class="button" href={concat( 'xrowextract/browse/', $PackageName, '/', 0 )|ezurl}>&laquo; {'First'|i18n('design/standard/extract')}</a>
        <a class="button" href={concat( 'xrowextract/browse/', $PackageName, '/', $FilesPreviousOffset )|ezurl}>&lsaquo; {'Previous'|i18n('design/standard/extract')}</a>
        {/if}
        {if $FilesHasNext}
        <a class="button" href={concat( 'xrowextract/browse/', $PackageName, '/', $FilesNextOffset )|ezurl}>{'Next'|i18n('design/standard/extract')} &rsaquo;</a>
        {/if}
    </nav>

    </div>

    {* DESIGN: Content END *}</div></div></div>
</div>
