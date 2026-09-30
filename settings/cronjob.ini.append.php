<?php /* #?ini charset="utf-8"?

[CronjobSettings]
ExtensionDirectories[]=xrowextract

# Scheduled exports and imports (xrowextract/schedules): starts every due schedule as a background job,
# then cleans up job folders and history rows past their retention. Run it every few minutes:
#   php runcronjobs.php xrowextract
[CronjobPart-xrowextract]
Scripts[]=xrowextract.php

*/ ?>
