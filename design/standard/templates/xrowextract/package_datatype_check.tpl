{* The datatype check of a package (XrowExtractPackage::missingDatatypes()): every datatype its classes or
   objects use that this site does not have, or one line saying all are there. Installing stays possible;
   the kernel leaves out what it cannot read. Included by package.tpl, compare.tpl and
   import_package_review.tpl. Parameter: missing (the rows: datatype, classes, objects, object_classes). *}
{if $missing|count}
<div class="xe-note xe-note-bad xe-datatype-check" role="alert">
    <p><strong>{'This site does not have %count datatype(s) the package uses.'|i18n('design/standard/extract',, hash( '%count', $missing|count ))}</strong>
        {'Installing is still possible, but the values of these attributes are not installed, and a class using one is incomplete. Install or enable the extension that provides the datatype first.'|i18n('design/standard/extract')}</p>
    <ul>
        {foreach $missing as $row}
        <li><code>{$row.datatype|wash}</code>{if $row.classes|count} · {'classes:'|i18n('design/standard/extract')} {foreach $row.classes as $identifier}<code>{$identifier|wash}</code> {/foreach}{/if}{if $row.objects|gt( 0 )} · {'%count object(s)'|i18n('design/standard/extract',, hash( '%count', $row.objects ))}{if $row.object_classes|count} ({$row.object_classes|implode( ', ' )|wash}){/if}{/if}</li>
        {/foreach}
    </ul>
</div>
{else}
<p class="xe-help xe-datatype-check">{'Datatypes: every datatype the package uses exists on this site.'|i18n('design/standard/extract')}</p>
{/if}
