{* What a page of the extension cannot offer on this server: one notice per feature of that page that misses a PHP
   extension, function, program or writable folder (XrowExtractRequirements; the command line check is
   ext:xrowextract:requirements). $page: 'csv', 'archive', 'import', 'package', 'jobs', 'schedules', 'destinations'
   or 'history'. Nothing is shown when everything the page offers works. *}
{def $xe_requirement_notices = fetch( 'xrowextract', 'requirement_notices', hash( 'page', $page ) )}
{foreach $xe_requirement_notices as $xe_requirement_notice}
<p class="xe-note xe-note-bad xe-requirement-notice" role="status" data-feature="{$xe_requirement_notice.feature|wash}">{'%feature: not available on this server. Missing: %missing.'|i18n( 'design/standard/extract',, hash( '%feature', $xe_requirement_notice.name, '%missing', $xe_requirement_notice.missing ) )|wash} {$xe_requirement_notice.hint|wash}</p>
{/foreach}
{undef $xe_requirement_notices}
