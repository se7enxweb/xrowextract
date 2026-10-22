{* "All import and export runs": Export's runs in the run history of every import and export tool (Exponential
   6.0.15 and later, the list of Imports filtered to Export). Shown only when the transfer registry is there and says
   the user may use Imports (transfer.sqliimport); nothing on an older kernel. *}
{if ezini_hasvariable( 'Service_transfer.registry', 'Class', 'kernelservices.ini' )}
{def $transfer_runs_tool = transfer_tool( 'transfer.sqliimport' )}
{if and( $transfer_runs_tool, $transfer_runs_tool.active )}
<p><a href={'sqliimport/list/(tool)/transfer.xrowextract'|ezurl}>{'All import and export runs'|i18n('design/standard/extract')}</a></p>
{/if}
{undef $transfer_runs_tool}
{/if}
