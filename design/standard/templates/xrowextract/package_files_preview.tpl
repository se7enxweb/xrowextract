{* Short preview of a package's own files (#26), embedded on the Import page and the Package tab.
   The caller (modules/xrowextract/import.php, modules/xrowextract/package.php) sets Files (the
   first few rows, see XrowExtractPackage::allPackageFiles()), FilesTotal, FilesOffset (always 0
   here), ViewedFile (always false: this preview never shows a file's own content, only the list)
   and PackageName - the same variable names design:xrowextract/browse.tpl (the full paginated
   page) sets, so package_files_rows.tpl, the actual shared row markup, is the only place that
   markup is written. *}
{if $FilesTotal|gt( 0 )}
<section class="xe-card" aria-labelledby="xe-files-h">
    <header class="xe-card-head">
        <div>
            <h2 id="xe-files-h">{'Package contents'|i18n('design/standard/extract')}</h2>
            <p>{'%count files'|i18n('design/standard/extract',, hash( '%count', $FilesTotal ))}</p>
        </div>
    </header>
    {include uri='design:xrowextract/package_files_rows.tpl'}
    {if $FilesTotal|gt( $Files|count )}
    <p><a href={concat( 'xrowextract/browse/', $PackageName, '/', 0 )|ezurl}>{'Browse all %count files'|i18n('design/standard/extract',, hash( '%count', $FilesTotal ))}</a></p>
    {/if}
</section>
{/if}
