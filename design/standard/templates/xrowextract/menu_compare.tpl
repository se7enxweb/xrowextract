{* DESIGN: Header START *}<div class="box-header"><div class="box-tc"><div class="box-ml"><div class="box-mr"><div class="box-tl"><div class="box-tr">

<h4>{'Compare'|i18n( 'design/standard/extract' )}</h4>

{* DESIGN: Header END *}</div></div></div></div></div></div>

{* DESIGN: Content START *}<div class="box-bc"><div class="box-ml"><div class="box-mr"><div class="box-bl"><div class="box-br"><div class="box-content">

<div class="xe-side">
<p class="xe-side-lead">{'What differs: a package against this site (what installing it would create or change), or two packages against each other. Nothing is written.'|i18n( 'design/standard/extract' )}</p>

<ol class="xe-side-steps">
    <li>{'What is compared'|i18n( 'design/standard/extract' )}
        <small>{'Pick "this site" or another package in the repository and press Compare; Check again works the comparison out anew.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Summary'|i18n( 'design/standard/extract' )}
        <small>{'How many classes and objects are added, removed, changed or the same; pick a count to list only those objects.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Classes'|i18n( 'design/standard/extract' )}
        <small>{'Name and attributes: added, removed, or with another datatype.'|i18n( 'design/standard/extract' )}</small></li>
    <li>{'Objects'|i18n( 'design/standard/extract' )}
        <small>{'Filter by change, class, and a part of the name or remote id; every field that differs, per language.'|i18n( 'design/standard/extract' )}</small></li>
</ol>

<details open>
    <summary>{'How objects are matched'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'By remote id, the same key an install uses; a class without one by its identifier.'|i18n( 'design/standard/extract' )}</li>
        <li>{'Two packages: added means only in the second, removed only in the first, changed in both with a different name, class, modified date or field value.'|i18n( 'design/standard/extract' )}</li>
        <li>{'With this site: the dry run of the Package tab, kept for 15 minutes; text, numbers, checkboxes, e-mail and identifiers are compared field by field, other datatypes as a whole object.'|i18n( 'design/standard/extract' )}</li>
    </ul>
</details>

<details>
    <summary>{'Command line'|i18n( 'design/standard/extract' )}</summary>
    <ul>
        <li>{'ext:xrowextract:package --compare=<a> --with=<b> compares two packages; --compare=<a> alone compares one with this site.'|i18n( 'design/standard/extract' )|wash}</li>
    </ul>
</details>
</div>

{* DESIGN: Content END *}</div></div></div></div></div></div>
