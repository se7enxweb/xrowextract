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
