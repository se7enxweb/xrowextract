{* The three parts of the Schedules tab: $part is 'schedules', 'destinations' or 'history'. Each link
   is only shown to users with its policy (xrowextract/schedule, /destinations, /history). *}
{def $can_schedule = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'schedule' ) )
     $can_destinations = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'destinations' ) )
     $can_history = fetch( 'user', 'has_access_to', hash( 'module', 'xrowextract', 'function', 'history' ) )}
<nav class="xe-subnav" aria-label="{'Schedules'|i18n( 'design/standard/extract' )|wash}">
    {if $can_schedule}<a href={'xrowextract/schedules'|ezurl}{if $part|eq( 'schedules' )} class="xe-subnav-active" aria-current="page"{/if}>{'Schedules'|i18n( 'design/standard/extract' )}</a>{/if}
    {if $can_destinations}<a href={'xrowextract/destinations'|ezurl}{if $part|eq( 'destinations' )} class="xe-subnav-active" aria-current="page"{/if}>{'Destinations'|i18n( 'design/standard/extract' )}</a>{/if}
    {if $can_history}<a href={'xrowextract/history'|ezurl}{if $part|eq( 'history' )} class="xe-subnav-active" aria-current="page"{/if}>{'History'|i18n( 'design/standard/extract' )}</a>{/if}
</nav>
{include uri='design:xrowextract/requirements_notice.tpl' page=$part}
{undef $can_schedule $can_destinations $can_history}
