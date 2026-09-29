{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Package'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'An Exponential content package (.ezpkg) is a portable archive: a content class, content objects, or both, that installs the same way anywhere. This page adds inspecting and installing one, and building a rich sample of one, to the same tools CSV/JSON import uses.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'Pick a package'|i18n( 'design/standard/extract' )}
        <small>{'Upload an .ezpkg, or choose one already in the repository (package/list also lists every package, not only content ones).'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Read the inspection'|i18n( 'design/standard/extract' )}
        <small>{'Every class and object it carries, and what installing it would do: create, update, unchanged, or class missing. Nothing is written yet.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Choose where and how'|i18n( 'design/standard/extract' )}
        <small>{'Parent node for the content, the site access its templates/overrides map to, and how to handle a class or object that already exists.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Install'|i18n( 'design/standard/extract' )}
        <small>{'Runs through the same kernel package installer package/install uses; what was created is listed with links.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

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
        <li>{'Command line: ext:xrowextract:package --inspect / --install / --export / --template.'|i18n( 'design/standard/extract' )}</li>
        <li>{'The full package system (upload, create, export, install wizard, uninstall) is still at package/list, for packages of any kind.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
