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

[PackageTemplate]
# Where the "Package template" sample-content builder (xrowextract/package, ext:xrowextract:package
# --template) creates its throwaway sample objects while it builds a package. They are created for
# real, exported into the package, then removed again - but for the seconds they exist, they are
# published content like any other, so this must NOT be a node the public site renders, indexes,
# caches or lists in a dynamic collection.
#
# Empty (default): content.ini [NodeSettings] MediaRootNode - the Media/Images-Files-Multimedia
# structure, which none of the shipped layouts, search results or the static/content-view cache
# render for a visitor. It is still a real, permanent node: only use one you are sure carries no
# public rendering for this installation. Never point this at content.ini [NodeSettings] RootNode
# (the public site's front page) or any node a layout, menu or dynamic collection reaches.
ScratchNodeID=

*/ ?>