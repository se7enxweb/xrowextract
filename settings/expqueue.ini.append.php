<?php /* #?ini charset="utf-8"?

# Export's background jobs in the job queue (expqueue), when it is active (Exponential 6.0.15 and later; the
# kernel's doc/eng-us/features/6.0/transfer-runs.md). The kernel's transfer runner queues a job here when the
# queue can take it and a queue worker is alive (QueueJobType=xrowextract.run in the [Service_transfer.xrowextract]
# block of kernelservices.ini.append.php); otherwise the kernel's content jobs run it, else Export's own background
# process, as before. Without expqueue this block is never read.
#
# One queue job is one Export job (a CSV, archive or package export, an import, a package install), whatever its
# size: the class bulk, one attempt (an export is not repeated by itself; the Jobs page starts it again), and a
# timeout long enough for a site archive or a large package install.
[JobType_xrowextract.run]
Handler=expKernelTransferQueueHandler
Class=bulk
MaxAttempts=1
Timeout=43200
Description=A background job of Export (xrowextract): an export, an import or a package install

*/ ?>
