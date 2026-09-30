{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Package'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'An Exponential content package (.ezpkg) is a portable archive: a content class, content objects, or both, that installs the same way anywhere. This page adds inspecting and installing one, and building a rich sample of one, to the same tools CSV/JSON import uses.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Pick a package'|i18n( 'design/standard/extract' )}
        <small>{'Upload an .ezpkg, or choose one already in the repository: Open shows it here, Open in Import reviews and installs it on the Import page instead. Next to the current package: its full package view, browse its files, compare, download .ezpkg, the full install wizard, Open in Import and Forget.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Inspection — nothing written'|i18n( 'design/standard/extract' )}
        <small>{'What the package carries and what installing it would do: create, update, unchanged, or class missing; Check again; the datatype check; Compare with this site or another package. Filter its objects by what the install would do, by class, and by name or remote id, a page at a time.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Install'|i18n( 'design/standard/extract' )}
        <small>{'Parent for new objects, site access, and how an existing object or class is handled; Install this package runs as a background job on the Jobs page, with its progress, log and links to what it installed.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Package template'|i18n( 'design/standard/extract' )}
        <small>{'A class and a variant (class only, content only, class + content); Build the sample package opens the result here for inspection.'|i18n( 'design/standard/extract' )}</small></li>
</ol>
<p class="xe-side-lead">{'Also on the page: Package contents (the package’s files, with Browse all files), Installs of this package (who installed it, when, how, the result, and Export these again), and the Package template reference.'|i18n( 'design/standard/extract' )}</p>

<details open>
    <summary>{'What is inside an .ezpkg'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'package.xml: name, summary, description, version, licence, dependencies, changelog, and the list of install items.'|i18n( 'design/standard/extract' )}</li>
        <li>{'ezcontentclass/*.xml: one content class, every attribute with its datatype and settings.'|i18n( 'design/standard/extract' )}</li>
        <li>{'ezcontentobject/*.xml: one or more content objects, one XML file per object once there are many.'|i18n( 'design/standard/extract' )}</li>
    </ul>
    <p class="xe-side-lead">{'The Package template reference below has the full layout and an annotated real example.'|i18n( 'design/standard/extract' )}</p>
</details>

<details>
    <summary>{'Matching on install'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Classes match by remote id, then by identifier.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Objects match by remote id only.'|i18n( 'design/standard/extract' )}</li>
        <li>{'An object whose class is not on this site, and not carried by the same package, is refused (class missing) rather than half-installed.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Existing class or object'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Classes: skip (default), replace, or keep both (a new copy with a new identifier).'|i18n( 'design/standard/extract' )}</li>
        <li>{'Objects: skip, update in place (default), or keep both (a new copy with a new remote id).'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Package template'|i18n( 'design/standard/extract' )}</summary>
    <p class="xe-side-lead">{'Builds a real sample package for a class you choose: the class definition, 2-3 real content objects with a valid value for every datatype the importer understands (several languages, relations, an image and a file where the class has them), or both. It is built through the kernel package handlers, so it installs like any other package.'|i18n( 'design/standard/extract' )}</p>
</details>

<details>
    <summary>{'Safety'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'Your permissions apply: reading the package, creating below the chosen parent, editing a matched object.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Inspect first: installing writes content objects and possibly a content class immediately, there is no separate preview/apply step.'|i18n( 'design/standard/extract' )}</li>
        <li>{'The datatype check lists every datatype the package uses that this site does not have (an extension not installed or not enabled); installing is still possible, but those values are left out, so the check, the Import review and the install job’s log all say so.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Every install is kept in the install history (the Jobs page, and Installs of this package here): who, when, with which options, and the result, after the job itself is removed.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Command line: ext:xrowextract:package --inspect / --install / --export / --template / --compare.'|i18n( 'design/standard/extract' )}</li>
        <li>{'The full package system (upload, create, export, install wizard, uninstall) is still at package/list, for packages of any kind.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
