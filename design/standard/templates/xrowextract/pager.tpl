{* One pager: "from-to of total", first/previous/next/last and the per-page choice. Query-only links
   (href="?..."), so the page keeps its own path; $query carries every filter (url-encoded, starting
   with &, or empty). Included by package.tpl (the Inspection objects) and compare.tpl.
   Parameters: pager (page, pages, prev, next, from, to, total, per_page, choices), query, label. *}
<nav class="xe-pager" aria-label="{$label|wash}">
    <span class="xe-pager-range">{'%from-%to of %total objects'|i18n('design/standard/extract',, hash( '%from', $pager.from, '%to', $pager.to, '%total', $pager.total ))}</span>
    {if $pager.pages|gt( 1 )}
    <span class="xe-pager-links">
        {if $pager.page|gt( 1 )}<a class="button" href="?page=1&amp;per_page={$pager.per_page|wash}{$query|wash}">&laquo; {'First'|i18n('design/standard/extract')}</a>
        <a class="button" href="?page={$pager.prev}&amp;per_page={$pager.per_page|wash}{$query|wash}">&lsaquo; {'Previous'|i18n('design/standard/extract')}</a>{/if}
        <span>{'Page %page of %pages'|i18n('design/standard/extract',, hash( '%page', $pager.page, '%pages', $pager.pages ))}</span>
        {if $pager.page|lt( $pager.pages )}<a class="button" href="?page={$pager.next}&amp;per_page={$pager.per_page|wash}{$query|wash}">{'Next'|i18n('design/standard/extract')} &rsaquo;</a>
        <a class="button" href="?page={$pager.pages}&amp;per_page={$pager.per_page|wash}{$query|wash}">{'Last'|i18n('design/standard/extract')} &raquo;</a>{/if}
    </span>
    {/if}
    <span class="xe-pager-size">{'Per page:'|i18n('design/standard/extract')}
        {foreach $pager.choices as $choice}{if $choice|eq( $pager.per_page )}<strong>{if $choice|eq( 'all' )}{'all'|i18n('design/standard/extract')}{else}{$choice}{/if}</strong>{else}<a href="?page=1&amp;per_page={$choice|wash}{$query|wash}">{if $choice|eq( 'all' )}{'all'|i18n('design/standard/extract')}{else}{$choice}{/if}</a>{/if} {/foreach}
    </span>
</nav>
