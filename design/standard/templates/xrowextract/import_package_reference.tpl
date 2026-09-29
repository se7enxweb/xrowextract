{* The "Content packages (.ezpkg)" reference details, included from import.tpl's File format
   reference card. Literal example tag names are written as plain text in the i18n source
   (readable for translators) and the whole string is piped through |wash at output, the
   convention this reference card uses everywhere else - never hand-escaped &lt;...&gt;. *}
<p class="xe-help">{'A gzip-compressed tar archive (.ezpkg or .tar.gz - the same format, only the extension differs) carrying a content class, one or more content objects, or both; or upload just one class-definition or content-object XML file on its own - either way it is inspected and installed through the same code as package/install and the xrowextract/package page.'|i18n('design/standard/extract')|wash}</p>

<h4>{'Archive layout'|i18n('design/standard/extract')}</h4>
<pre class="xe-example">package-name/
  package.xml                 # metadata + the ordered install/uninstall item list
  ezcontentclass/
    &lt;filename&gt;.xml            # one file per content-class install item
  ezcontentobject/
    &lt;filename&gt;.xml            # object-list (few objects), or object-files-list + one
    object-&lt;remote-id&gt;.xml    #   object-&lt;remote-id&gt;.xml per object once there are many
  simplefiles/                  # the real files an ezimage/ezbinaryfile/ezmedia attribute stores
  documents/                    # free-form package documents (readme, licence text, ...)
  .cache/package.php            # a generated cache of the parsed package.xml; never hand-edit</pre>
<p class="xe-help">{'A standalone content-class or content-object upload is the same one file, wrapped in a transient package with just that one install item - nothing else in this layout exists for it.'|i18n('design/standard/extract')|wash}</p>

<h4>{'package.xml'|i18n('design/standard/extract')}</h4>
<div class="xe-scroll" tabindex="0">
<table class="xe-table" style="width: 100%">
    <thead><tr><th>{'Element'|i18n('design/standard/extract')}</th><th>{'Meaning'|i18n('design/standard/extract')}</th></tr></thead>
    <tbody>
    <tr><td><code>&lt;name&gt;</code></td><td>{'The id the package is known by in the repository (package/list, xrowextract/package, ext:xrowextract:package). Unique per repository, never shown to a visitor.'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;summary&gt;</code>, <code>&lt;description&gt;</code></td><td>{'Free text, shown on the inspect screen and package/view.'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;release&gt;&lt;version&gt;</code>, <code>&lt;release-nr&gt;</code></td><td>{'Version and release number; informational, not compared against an installed copy automatically.'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;licence&gt;</code>, <code>&lt;maintainers&gt;</code>, <code>&lt;vendor&gt;</code></td><td>{'Free text, shown on the inspect screen and package/view.'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;changelog&gt;</code></td><td>{'One or more <change> entries: person, timestamp, and the change text(s). Purely informational.'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;dependencies&gt;</code></td><td>{'provides/requires/obsoletes/conflicts. A <requires> of type ezpackage is installed first, automatically, by eZPackage::install(); a <requires> of type ezcontentclass is informational only - it is not fetched, only stated (the installing site must already carry that class, for a content-only package).'|i18n('design/standard/extract')|wash}</td></tr>
    <tr><td><code>&lt;install&gt;</code>, <code>&lt;uninstall&gt;</code></td><td>{'The ordered list of install/uninstall items: type (ezcontentclass, ezcontentobject, ...), the file it reads its content from and its sub-directory.'|i18n('design/standard/extract')|wash}</td></tr>
    </tbody>
</table>
</div>

<h4>{'The content-class definition XML (ezcontentclass/*.xml)'|i18n('design/standard/extract')|wash}</h4>
<ul>
    <li>{'Root <content-class>: is-container, always-available and the sort field/order it opens with.'|i18n('design/standard/extract')|wash}</li>
    <li>{'<identifier>, <remote-id>: the class identifier and its remote id - remote id is the install-time match key (falls back to identifier when no class has that remote id yet).'|i18n('design/standard/extract')|wash}</li>
    <li>{'<serialized-name-list>, <serialized-description-list>: PHP-serialized per-language maps (one entry per language the class name/description is translated to), not plain text - never hand-write these; build the class in the class editor or copy them from a real export.'|i18n('design/standard/extract')|wash}</li>
    <li>{'<remote><groups>: which content-class groups (Content, Users, Media, ...) the class belongs to, each named by its own remote id.'|i18n('design/standard/extract')|wash}</li>
    <li>{'<attributes><attribute>: one per class attribute - identifier, type (the datatype string, e.g. ezstring), name (per language), is-required/is-searchable/is-translatable/is-information-collector, category, and a <content> block whose shape is entirely datatype-specific (ezinteger carries min/max/default; ezselection carries the option list and multi/single; ezobjectrelation(list) carries the class/group constraint and selection type; ezimage carries the max file size; and so on) - this is exactly what eZDataType::serializeContentClassAttribute() for that datatype writes, and only that datatype’s own fromString()/unserializeContentClassAttribute() can read it back, so hand-writing an attribute’s <content> reliably means copying it from a real export of the same datatype, not composing it from this reference alone.'|i18n('design/standard/extract')|wash}</li>
</ul>

<h4>{'The content-object XML (ezcontentobject/*.xml)'|i18n('design/standard/extract')|wash}</h4>
<ul>
    <li>{'Root <object>: name, remote_id (the install-time match key), class_remote_id/class_identifier, always_available, and the timestamps a package install can optionally restore (published/modified).'|i18n('design/standard/extract')|wash}</li>
    <li>{'<version-list active_version="n">: one <version> per version the object carries (almost always just one, the published version); each has its own status and an ordered <object-translation language="..."> per language.'|i18n('design/standard/extract')|wash}</li>
    <li>{'Inside an <object-translation>, one <attribute identifier="..." type="..."> per class attribute, serialized exactly as that datatype’s own serialize() writes it - the shape differs by datatype (see the table below); an attribute the translation does not carry (a non-translatable one, already set on another language) is simply absent.'|i18n('design/standard/extract')|wash}</li>
    <li>{'<node-assignment-list>: one <node-assignment> per location. A node with no parent-node-remote-id attribute is a "top node" - on install it goes under the parent node chosen at install time (or, for xrowextract/package’s own install, the "Parent for new objects" field above); one that does have it is placed under whichever node in the same package (or already on the installing site) carries that remote id.'|i18n('design/standard/extract')|wash}</li>
    <li>{'A relation attribute (ezobjectrelation/ezobjectrelationlist) stores the related object’s remote id, not its numeric id - so it resolves correctly even though ids differ between the exporting and installing site; a relation to an object neither carried by the same package nor already on the installing site is left empty, silently.'|i18n('design/standard/extract')|wash}</li>
    <li>{'ezimage/ezbinaryfile/ezmedia store the real file under the package’s own simplefiles/, copied back out on install - nothing is fetched from the exporting site at install time.'|i18n('design/standard/extract')|wash}</li>
</ul>

<p class="xe-help">{'A real example, from this extension’s own "Package template" sample builder (xrowextract/package) - one attribute of each datatype family it fills a real value for, and the relation from a second sample object to the first:'|i18n('design/standard/extract')|wash}</p>
<pre class="xe-example">&lt;object remote_id="xrowextract-pkgtpl-article-20260929145755-3f05a3-2"
        name="Sample Short title 2 (eng-US)"
        class_remote_id="c15b600eb9198b1924063b5a68758232"
        ezobject:class_identifier="article"
        ezobject:always_available="0"
        ezobject:modified="2026-09-29 14:57:55 GMT"&gt;
  &lt;version-list active_version="1"&gt;
    &lt;version ezobject:version="1" ezobject:status="1"&gt;
      &lt;object-translation language="eng-US" object_name="Sample Short title 2 (eng-US)"&gt;
        &lt;attribute ezobject:identifier="title" type="ezstring"&gt;
          &lt;text&gt;Sample Title 2 (eng-US)&lt;/text&gt;
        &lt;/attribute&gt;
        &lt;attribute ezobject:identifier="intro" type="ezxmltext"&gt;
          &lt;section&gt;&lt;paragraph&gt;Sample rich text paragraph 2 for Intro (eng-US).&lt;/paragraph&gt;&lt;/section&gt;
        &lt;/attribute&gt;
        &lt;attribute ezobject:identifier="enable_comments" type="ezboolean"&gt;
          &lt;value&gt;0&lt;/value&gt;
        &lt;/attribute&gt;
        &lt;attribute ezobject:identifier="image" type="ezobjectrelation"&gt;
          &lt;related-object-list&gt;
            &lt;related-object remote-id="xrowextract-pkgtpl-article-20260929145755-3f05a3-1" /&gt;
          &lt;/related-object-list&gt;
        &lt;/attribute&gt;
      &lt;/object-translation&gt;
    &lt;/version&gt;
  &lt;/version-list&gt;
  &lt;node-assignment-list&gt;
    &lt;!-- no parent-node-remote-id: a top node, placed under the chosen parent on install --&gt;
    &lt;node-assignment is-main-node="1" name="Sample Short title 2" node-id="381" remote-id="..." /&gt;
  &lt;/node-assignment-list&gt;
&lt;/object&gt;</pre>

<h4>{'Every importable datatype, with an example'|i18n('design/standard/extract')|wash}</h4>
<div class="xe-scroll" tabindex="0">
<table class="xe-table" style="width: 100%">
    <thead><tr><th>{'Datatype'|i18n('design/standard/extract')}</th><th>{'Serialized as'|i18n('design/standard/extract')}</th><th>{'Example'|i18n('design/standard/extract')}</th></tr></thead>
    <tbody>
    <tr><td>ezstring, eztext</td><td><code>&lt;text&gt;</code></td><td>Sample Title 2 (eng-US)</td></tr>
    <tr><td>ezinteger</td><td><code>&lt;value&gt;</code></td><td>42</td></tr>
    <tr><td>ezfloat</td><td><code>&lt;value&gt;</code></td><td>3.5</td></tr>
    <tr><td>ezboolean</td><td><code>&lt;value&gt;</code> (0 or 1)</td><td>1</td></tr>
    <tr><td>ezdate, ezdatetime</td><td>a Unix timestamp attribute</td><td>ezdate="1790380800"</td></tr>
    <tr><td>ezselection</td><td>the chosen option id(s)</td><td>selection_value="0"</td></tr>
    <tr><td>ezkeyword</td><td>a comma-separated <code>&lt;keywords&gt;</code></td><td>sample, keyword 1</td></tr>
    <tr><td>eztags</td><td>{'one &lt;tag remote-id keyword-string parent-remote-id&gt; per tag'|wash}</td><td>&lt;tag keyword-string="Running" /&gt;</td></tr>
    <tr><td>ezxmltext</td><td>{'a full &lt;section&gt; tree (the same internal XML the rich text editor stores)'|wash}</td><td>&lt;section&gt;&lt;paragraph&gt;...&lt;/paragraph&gt;&lt;/section&gt;</td></tr>
    <tr><td>ezimage, ezbinaryfile, ezmedia</td><td>{'&lt;related-file&gt; naming a file under the package’s simplefiles/'|wash}</td><td>&lt;related-file filename="sample.jpg" /&gt;</td></tr>
    <tr><td>ezobjectrelation, ezobjectrelationlist</td><td>{'one/many &lt;related-object remote-id="..."&gt;'|wash}</td><td>&lt;related-object remote-id="..." /&gt;</td></tr>
    <tr><td>ezauthor</td><td>&lt;ezauthor&gt;&lt;authors&gt;&lt;author id name email&gt;</td><td>&lt;author id="14" name="Admin User" email="admin@example.com" /&gt;</td></tr>
    </tbody>
</table>
</div>
<p class="xe-help">{'A datatype not listed here still exports and round-trips through package/create and package/install normally (this list only names the ones the sample builder fills a value for); it just has no sample generated for it by the Package template builder.'|i18n('design/standard/extract')|wash}</p>

<h4>{'What happens on install'|i18n('design/standard/extract')|wash}</h4>
<ul>
    <li>{'Classes match by remote id, falling back to identifier; a match is skipped, replaced, or kept alongside a new copy (a new identifier), per the class option offered here.'|i18n('design/standard/extract')|wash}</li>
    <li>{'Objects match by remote id only; a match is skipped, updated in place (existing content is kept wherever the package does not touch it), or kept alongside a new copy with a freshly generated remote id.'|i18n('design/standard/extract')|wash}</li>
    <li>{'An object whose class is on neither this site nor carried by the same package is refused outright (class missing) rather than half-installed - inspect it first and it is called out exactly as that.'|i18n('design/standard/extract')|wash}</li>
    <li>{'A language the object carries but this site does not have yet is added automatically, if it is a valid locale; otherwise that one translation (only that one) is skipped.'|i18n('design/standard/extract')|wash}</li>
    <li>{'A "requires" dependency of type ezpackage is installed first, automatically; of type ezcontentclass it is informational only - a content-only package still fails per-object with class missing if that class is not already there.'|i18n('design/standard/extract')|wash}</li>
</ul>

<h4>{'Building one by hand or from the command line'|i18n('design/standard/extract')|wash}</h4>
<ul>
    <li>{'A single class or object XML file: export it from package/create (content class / content object export), or take one file out of an existing .ezpkg (tar tzf/tar xzf) - then upload that one file here directly, no archive needed.'|i18n('design/standard/extract')|wash}</li>
    <li>{'A full .ezpkg: package/create’s wizard, or ext:xrowextract:package --export --node=<id> [--subtree] [--class=<id>] --file=<out.ezpkg> for a plain node/subtree, or --template --class=<id> --variant=both --file=<out.ezpkg> for a ready-made sample of a class (see the Package page).'|i18n('design/standard/extract')|wash}</li>
    <li>{'ext:xrowextract:package --inspect=<name> shows exactly what a package carries and what installing it would do, the same dry run this page runs after an upload.'|i18n('design/standard/extract')|wash}</li>
</ul>
