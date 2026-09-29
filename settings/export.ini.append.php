<?php /* #?ini charset="utf8"?

[ExportSettings]
#If ExportClasses is not set or empty the system will make all classes available.
ExportClasses[]
#ExportClasses[]=user

# The node the view starts with; empty: the root node of the default siteaccess
# (content.ini [NodeSettings] RootNode of site.ini DefaultAccess).
StartNodeID=
# The class the view starts with; empty: the class with the most objects in the selection.
DefaultClassID=
PreselectAttributes=true

#Default to unlimited
Limit=0
Offset=0

[SiteArchive]
# The node whose children are the sites of the "Sites" set; empty: the content structure.
SitesParentNodeID=
# The sites the "Sites" set selects (the archive's starting selection); empty: the root node of the
# default siteaccess. For example:
#DefaultSiteNodeIDs[]=89
DefaultSiteNodeIDs[]

*/ ?>