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
} )();
