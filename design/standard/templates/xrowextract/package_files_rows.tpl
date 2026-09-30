{* Shared row markup for the package contents browser (#26): one row per file, from $Files (each
   carrying path/size_human/kind/index - see XrowExtractPackage::allPackageFiles() and how
   modules/xrowextract/browse.php adds 'index'). Included by design:xrowextract/browse.tpl (the
   full paginated page) and design:xrowextract/package_files_preview.tpl (the short preview
   embedded on the Import page and the Package tab) alike - the one place this markup is written. *}
<table class="xe-files-table">
    <thead>
        <tr>
            <th>{'File'|i18n('design/standard/extract')}</th>
            <th>{'Kind'|i18n('design/standard/extract')}</th>
            <th>{'Size'|i18n('design/standard/extract')}</th>
            <th>{'Action'|i18n('design/standard/extract')}</th>
        </tr>
    </thead>
    <tbody>
    {foreach $Files as $file}
        <tr{if $ViewedFile|and( $ViewedFile.index|eq( $file.index ) )} class="xe-file-row-active"{/if}>
            <td><code class="xe-file-path">{$file.path|wash}</code></td>
            <td><span class="xe-badge xe-file-kind-{$file.kind|wash}">{$file.kind|wash}</span></td>
            <td>{$file.size_human|wash}</td>
            <td>
                {if $file.kind|ne( 'binary' )}
                <a class="button" href={concat( 'xrowextract/browse/', $PackageName, '/', $FilesOffset, '/', $file.index )|ezurl}>{'View'|i18n('design/standard/extract')}</a>
                {/if}
                <a class="button" href={concat( 'xrowextract/browse_file/', $PackageName, '/', $file.index )|ezurl} target="_blank" rel="noopener">{'Download'|i18n('design/standard/extract')}</a>
            </td>
        </tr>
    {/foreach}
    </tbody>
</table>
