CSV Extract for eZ publish

Developed by
Björn Dieding ( bjoern@xrow.de )

This extenstion delivers tools for extracting/exporting content object data to csv.

It comes with following modules/views

xrowextract/csv

Preview

"Preview" in xrowextract/csv shows the export as a spreadsheet will show
it, before downloading: the same code builds the file, and the preview
reads it back with the chosen separator and quoting. It shows the first
10, 25, 50 or 100 rows (remembered per user), how many rows and columns
the file will have, how full each column is, rows that would shift their
columns and cells that were neutralised as formulas. Rows can be
filtered and sorted, cells expanded or wrapped (remembered), and the rows
shown copied tab separated. None of this changes the download.

Settings (csv.ini, [General])

NeutralizeFormulas=enabled         cells starting with = + - @ get a leading '
AllowPasswordHashExport=disabled   offer the user password hash as a column

Site archive (xrowextract/archive)

The content below one or more nodes as one archive: a CSV file for every
class (object id, remote id, main node, parent node, URL alias, dates,
then every attribute of the class), manifest.json and README.txt.
Ready-made node sets (content and media, content, media, users,
everything), single nodes added from a list with counts or the browse
page, classes ticked or unticked (all with content are ticked). ZIP,
TAR.GZ, TAR.BZ2 and TAR.XZ; 7-Zip and RAR when their programs (7z,
rar) are installed. Objects are read with the user's read access, at
their main location, and written once. Password hashes are never
exported. The archive is written to a private folder in the cache
directory and removed after the download.
