/*
 * The "Subtree of class" submenu of the content tree's Advanced > Export menu (popupmenu/xrowextract_export_submenus.tpl):
 * when it opens, the classes below the item are read from xrowextract/subtree_classes/<node> (JSON, the user's read
 * access) and listed, each opening xrowextract/csv filled in for the subtree and that class, with "More classes…"
 * when there are more. A list read once is kept for the page. Texts and URLs come from the menu's data attributes;
 * class names are put in as text. ezpopupmenu.js calls show() through menuArray['ExportClassMenu']['onshow'] and
 * gives refresh(), which gives the new items their roles and places the menu again; the focus moves to the first
 * class when it was on the "Loading…" item (keyboard).
 */
( function()
{
    if ( window.xrowExtractExportMenu ) return;
    var lists = {};

    function fill( menu, template, values )
    {
        return template.replace( /%nodeID%|%classID%/g, function( key ) { return encodeURIComponent( String( values[key] ) ); } );
    }

    function item( text, href, disabled )
    {
        var a = document.createElement( 'a' );
        a.textContent = text;
        a.setAttribute( 'href', href || '#' );
        if ( disabled )
        {
            a.className = 'menu-item-disabled';
            a.setAttribute( 'aria-disabled', 'true' );
            a.addEventListener( 'click', function( e ) { e.preventDefault(); } );
        }
        return a;
    }

    function render( menu, nodeID, data )
    {
        while ( menu.firstChild ) menu.removeChild( menu.firstChild );
        if ( !data )
        {
            menu.appendChild( item( menu.getAttribute( 'data-failed' ), null, true ) );
            return;
        }
        if ( !data.classes || !data.classes.length )
        {
            menu.appendChild( item( menu.getAttribute( 'data-empty' ), null, true ) );
            return;
        }
        var label = menu.getAttribute( 'data-count' ) || '%name (%count)';
        for ( var i = 0; i < data.classes.length; i++ )
        {
            var cls = data.classes[i];
            var a = item( label.replace( '%name', cls.name ).replace( '%count', String( cls.count ) ),
                          fill( menu, menu.getAttribute( 'data-entry-url' ), { '%nodeID%': nodeID, '%classID%': cls.id } ) );
            a.setAttribute( 'data-class', cls.identifier );
            menu.appendChild( a );
        }
        if ( data.more )
        {
            menu.appendChild( document.createElement( 'hr' ) );
            menu.appendChild( item( menu.getAttribute( 'data-more' ), fill( menu, menu.getAttribute( 'data-more-url' ), { '%nodeID%': nodeID } ) ) );
        }
    }

    function show( menu, values, refresh )
    {
        var nodeID = values && values['%nodeID%'];
        if ( !menu || !/^[1-9][0-9]{0,9}$/.test( String( nodeID ) ) ) return;
        nodeID = String( nodeID );
        menu.setAttribute( 'data-node', nodeID );
        if ( lists.hasOwnProperty( nodeID ) )
        {
            render( menu, nodeID, lists[nodeID] );
            refresh();
            return;
        }
        render( menu, nodeID, { 'classes': [] } );
        menu.firstChild.textContent = menu.getAttribute( 'data-loading' );
        refresh();
        var done = function( data )
        {
            if ( data ) lists[nodeID] = data;
            // another item's list was asked for in the meantime
            if ( menu.getAttribute( 'data-node' ) !== nodeID ) return;
            var hadFocus = menu.contains( document.activeElement );
            render( menu, nodeID, data );
            refresh();
            if ( hadFocus && menu.firstChild && menu.firstChild.focus ) menu.firstChild.focus();
        };
        if ( !window.fetch )
        {
            done( null );
            return;
        }
        window.fetch( fill( menu, menu.getAttribute( 'data-classes-url' ), { '%nodeID%': nodeID } ),
                      { 'credentials': 'same-origin', 'headers': { 'Accept': 'application/json' } } )
            .then( function( response ) { return response.ok ? response.json() : null; } )
            .then( done, function() { done( null ); } );
    }

    window.xrowExtractExportMenu = { 'show': show };

    /*
     * The entries xrowextract adds to the core menus (popupmenu/xrowextract_export_menu.tpl, xrowextract_import_menu.tpl)
     * and its class submenu (xrowextract_export_submenus.tpl) carry no inline code, so they work under a Content
     * Security Policy without 'unsafe-inline'. Here they are put in menuArray (added to, never assigned anew) the way
     * the inline scripts did: an entry's data-menu-url / data-menu-shown-if become menuArray[menu]['elements'][id], a
     * class submenu (data-menu-depth) gets its entry and the onshow above. Pointing at an entry calls the same global
     * functions the onmouseover attributes called: ezpopmenu_showSubLevel() for the one with data-menu-submenu,
     * ezpopmenu_mouseOver() for the others. The event stops at the entry, so a newer ezpopupmenu.js, which acts on
     * data-menu-* by itself, does not open the submenu a second time. Works with the old and the new ezpopupmenu.js;
     * set up once, however often this file is printed.
     */
    function register()
    {
        var menus = window.menuArray;
        if ( !menus || !document.querySelectorAll ) return;
        var i, el, entry;

        var classMenus = document.querySelectorAll( 'div.xrowextract-class-menu[id]' );
        for ( i = 0; i < classMenus.length; i++ )
        {
            el = classMenus[i];
            if ( el.getAttribute( 'data-xrowextract-ready' ) ) continue;
            el.setAttribute( 'data-xrowextract-ready', '1' );
            entry = menus[el.id];
            if ( !entry )
            {
                entry = menus[el.id] = { 'depth': parseInt( el.getAttribute( 'data-menu-depth' ), 10 ) || 0, 'elements': {} };
            }
            if ( !entry['elements'] ) entry['elements'] = {};
            entry['onshow'] = show;
        }

        var items = document.querySelectorAll( 'a[data-xrowextract-menu]' );
        for ( i = 0; i < items.length; i++ )
        {
            el = items[i];
            if ( el.getAttribute( 'data-xrowextract-ready' ) ) continue;
            el.setAttribute( 'data-xrowextract-ready', '1' );
            var menu = menus[el.getAttribute( 'data-xrowextract-menu' )];
            if ( el.id && menu && el.hasAttribute( 'data-menu-url' ) )
            {
                if ( !menu['elements'] ) menu['elements'] = {};
                var item = { 'url': el.getAttribute( 'data-menu-url' ) };
                if ( el.hasAttribute( 'data-menu-shown-if' ) ) item['shown_if'] = el.getAttribute( 'data-menu-shown-if' );
                menu['elements'][el.id] = item;
            }
            el.addEventListener( 'mouseover', pointed );
        }
    }

    function pointed( event )
    {
        var el = event.currentTarget;
        event.stopPropagation();
        var sub = el.getAttribute( 'data-menu-submenu' );
        if ( sub )
        {
            if ( typeof window.ezpopmenu_showSubLevel === 'function' ) window.ezpopmenu_showSubLevel( event, sub, el.id );
        }
        else if ( typeof window.ezpopmenu_mouseOver === 'function' )
        {
            window.ezpopmenu_mouseOver( el.getAttribute( 'data-xrowextract-menu' ) );
        }
    }

    // the menus are at the end of the page, after this file (BackendJavaScriptList, in the head)
    if ( document.readyState === 'loading' )
    {
        document.addEventListener( 'DOMContentLoaded', register );
    }
    else
    {
        register();
    }
    window.addEventListener( 'load', register );
} )();
