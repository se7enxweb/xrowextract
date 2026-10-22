<?php /* #?ini charset="utf-8"?

# xrowextract in Exponential's transfer registry (kernel 6.0.15 and later, settings/kernelservices.ini; the kernel's
# doc/eng-us/features/6.0/transfer-registry.md). The kernel lists the tool in the "Which import or export tool do I
# need?" help page, the "Looking for…?" boxes of the other tools, the "Import and export" lists of the Setup menu and
# the left menus, the class page and the administration's search. Older kernels ignore this file.
# The item entries of the Import/Export menu stay in xrowextract's own menu templates (admininterface.ini).

[Service_transfer.xrowextract]
Class=expKernelTransferTool
Factory=expKernelTransferTool::fromSettings
Arguments[]=transfer.xrowextract
Scope=worker
Tags[]=transfer.tool
Description=Download the items of a class, a folder or the whole site as CSV, XML, JSON or an archive, now or on a schedule, and import such files back.
Name=Export (xrowextract)
Direction=both
Scopes[]=site
Scopes[]=subtree
Scopes[]=class
Scopes[]=object
Formats[]=CSV
Formats[]=XML
Formats[]=JSON
Formats[]=ezpkg
Formats[]=ZIP
Formats[]=TAR.GZ
EntryURL=xrowextract/csv
Permission=xrowextract/csv|xrowextract/import
HelpPage=ext/xrowextract/README.md
RoundTrip[]=transfer.xrowextract
RoundTrip[]=transfer.sqliimport
RoundTrip[]=transfer.package
Keywords[]=spreadsheet
Keywords[]=excel
Keywords[]=bulk edit
Keywords[]=archive
Keywords[]=backup of content
Keywords[]=schedule
Keywords[]=migrate
Keywords[]=Tabelle
Keywords[]=Massenbearbeitung
Keywords[]=Sicherung
Actions[]=class|export|Export the objects of this class…|xrowextract/csv/(class)/%classID%|xrowextract/csv
Weight=20
# Background jobs (kernel 6.0.15 and later, transfer.ini; doc/eng-us/features/6.0/transfer-runs.md): the job queue
# runs them as xrowextract.run (settings/expqueue.ini.append.php) when it can, else the kernel's content jobs, else
# Export's own process. Every run is written to the kernel's run history; who has xrowextract/all_jobs sees
# everyone's runs of Export there, everybody else their own.
QueueJobType=xrowextract.run
HistoryAllPermission=xrowextract/all_jobs

*/ ?>
