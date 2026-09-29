<?php /* #?ini charset="utf8"?

[General]
ExportableDatatypes[]
ExportableDatatypes[]=ezboolean
ExportableDatatypes[]=eztext
ExportableDatatypes[]=ezinteger
ExportableDatatypes[]=ezstring
ExportableDatatypes[]=eztime
ExportableDatatypes[]=ezurl
ExportableDatatypes[]=ezuser
ExportableDatatypes[]=ezxmltext
ExportableDatatypes[]=ezdate
ExportableDatatypes[]=ezdatetime
ExportableDatatypes[]=ezkeyword
ExportableDatatypes[]=eztags
ExportableDatatypes[]=ezobjectrelation
ExportableDatatypes[]=ezemail
ExportableDatatypes[]=ezfloat
ExportableDatatypes[]=ezidentifier
ExportableDatatypes[]=ezenhancedobjectrelation
ExportableDatatypes[]=ezenhancedselection
ExportableDatatypes[]=ezselection
ExportableDatatypes[]=ezenum
ExportableDatatypes[]=ezcountry
ExportableDatatypes[]=ezimage
ExportableDatatypes[]=ezmedia
ExportableDatatypes[]=ezbinaryfile
ExportableDatatypes[]=ezmatrix
ExportableDatatypes[]=ezobjectrelationlist
ExportableDatatypes[]=hmregexpline
ExportableDatatypes[]=ezprice
ExportableDatatypes[]=xrowmetadata
StripURLText=true
# A cell starting with = + - @ (not a number) gets a leading ' so a
# spreadsheet shows it as text instead of running it as a formula.
# disabled exports the values exactly as stored.
NeutralizeFormulas=enabled
# The password hash and hash type of user accounts (for a migration to
# another system) can be exported by users with the policy
# xrowextract/password_hash; administrators have it. disabled switches it
# off for everyone.
AllowPasswordHashExport=enabled

[Jobs]
# How many days a finished (or failed) background export job is kept before
# ext:xrowextract:job --clean removes its folder.
RetentionDays=7
# The PHP command line binary a background job is run with. Leave empty to
# detect it (PHP_BINARY, mapped from a PHP-FPM/php-cgi binary to the CLI one
# next to it, else "php" on the PATH). Set this when detection guesses wrong.
PhpCli=

# you can place the handler files in your extension
# just enter the full path to the handler

[ezstring]
HandlerFile=extension/xrowextract/classes/parsers/xrowezstringhandler.php
HandlerClass=XroweZStringHandler

[ezenhancedselection]
HandlerFile=extension/xrowextract/classes/parsers/xrowezstringhandler.php
HandlerClass=XroweZStringHandler

[ezinteger]
HandlerFile=extension/xrowextract/classes/parsers/xrowezintegerhandler.php
HandlerClass=XroweZIntegerHandler

[ezxmltext]
HandlerFile=extension/xrowextract/classes/parsers/xrowezxmltexthandler.php
HandlerClass=XroweZXMLTextHandler

[ezidentifier]
HandlerFile=extension/xrowextract/classes/parsers/xrowezidentifierhandler.php
HandlerClass=XroweZIdentifierHandler

[ezfloat]
HandlerFile=extension/xrowextract/classes/parsers/xrowezfloathandler.php
HandlerClass=XroweZFloatHandler

[ezemail]
HandlerFile=extension/xrowextract/classes/parsers/xrowezemailhandler.php
HandlerClass=XroweZEmailHandler

[ezurl]
HandlerFile=extension/xrowextract/classes/parsers/xrowezurlhandler.php
HandlerClass=XroweZURLHandler

[eztext]
HandlerFile=extension/xrowextract/classes/parsers/xroweztexthandler.php
HandlerClass=XroweZTextHandler

[ezenhancedobjectrelation]
HandlerFile=extension/xrowextract/classes/parsers/xrowezenhancedobjectrelationhandler.php
HandlerClass=XroweZenhancedobjectrelationHandler
#false will simply output the IDs of the related objects
OutputRelatedObjectNames=true

[ezselection]
HandlerFile=extension/xrowextract/classes/parsers/xrowezselectionhandler.php
HandlerClass=XroweZSelectionHandler

[ezenum]
HandlerFile=extension/xrowextract/classes/parsers/xrowezenumhandler.php
HandlerClass=XroweZEnumHandler

[ezuser]
HandlerFile=extension/xrowextract/classes/parsers/xrowezuserhandler.php
HandlerClass=XroweZUserHandler

[ezdate]
HandlerFile=extension/xrowextract/classes/parsers/xrowezdatehandler.php
HandlerClass=XroweZDateHandler

[ezboolean]
HandlerFile=extension/xrowextract/classes/parsers/xrowezbooleanhandler.php
HandlerClass=XroweZBooleanHandler

[ezcountry]
HandlerFile=extension/xrowextract/classes/parsers/xrowezselectionhandler.php
HandlerClass=XroweZSelectionHandler

[ezimage]
HandlerFile=extension/xrowextract/classes/parsers/xrowezimagehandler.php
HandlerClass=XroweZImageExportHandler

[ezmedia]
HandlerFile=extension/xrowextract/classes/parsers/xrowezmediahandler.php
HandlerClass=XroweZMediaExportHandler

[ezbinaryfile]
HandlerFile=extension/xrowextract/classes/parsers/xrowezbinaryfilehandler.php
HandlerClass=XroweZBinaryfileExportHandler

[ezmatrix]
HandlerFile=extension/xrowextract/classes/parsers/xrowezmatrixhandler.php
HandlerClass=XroweZMatrixExportHandler

[ezobjectrelationlist]
HandlerFile=extension/xrowextract/classes/parsers/xrowezobjectrelationlisthandler.php
HandlerClass=XroweZObjectRelationListHandler

[hmregexpline]
HandlerFile=extension/xrowextract/classes/parsers/xrowhmregexplinehandler.php
HandlerClass=XrowhmregexplineHandler

[ezprice]
HandlerFile=extension/xrowextract/classes/parsers/xrowezpricehandler.php
HandlerClass=XroweZPriceHandler

[ezdatetime]
HandlerFile=extension/xrowextract/classes/parsers/xrowezdatetimehandler.php
HandlerClass=XroweZDateTimeHandler

[eztime]
HandlerFile=extension/xrowextract/classes/parsers/xroweztimehandler.php
HandlerClass=XroweZTimeHandler

[ezkeyword]
HandlerFile=extension/xrowextract/classes/parsers/xrowezkeywordhandler.php
HandlerClass=XroweZKeywordHandler

[eztags]
HandlerFile=extension/xrowextract/classes/parsers/xrowezkeywordhandler.php
HandlerClass=XroweZKeywordHandler

[ezobjectrelation]
HandlerFile=extension/xrowextract/classes/parsers/xrowezobjectrelationhandler.php
HandlerClass=XroweZObjectRelationHandler

[xrowmetadata]
HandlerFile=extension/xrowextract/classes/parsers/xrowxrowmetadatahandler.php
HandlerClass=XrowxrowmetadataHandler

[Uploads]
# There is no fixed file size limit for an import: a large file uploads in
# chunks (XrowExtractUpload) and is streamed while it is read, independent
# of PHP's own upload_max_filesize/post_max_size. These settings are the
# only real limits that remain.
#
# Hours an unfinished (or finished but never adopted) chunked upload is kept
# before it is cleaned up.
RetentionHours=24
# Free disk space that must remain, beyond the incoming file itself, for an
# upload to be accepted at all.
MinFreeMarginMB=256
# Above this many rows, or this many MB, both the dry run and the apply run
# as a background job (xrowextract/jobs) instead of in the web request that
# started them.
QueueThresholdRows=2000
QueueThresholdMB=20
# A JSON import file above this size is still read in one go (json_decode()
# has no streaming API); XML and CSV files never have this limitation, at
# any size.
JsonOneShotThresholdMB=20

*/ ?>