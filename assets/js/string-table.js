/**
 * "Select all" checkbox for the Supertext string tables.
 *
 * @package Supertext_Polylang
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( e ) {
		var all = e.target.closest ? e.target.closest( '.st-check-all' ) : null;
		if ( ! all ) {
			return;
		}
		// The row checkboxes live in the POST form; the select-all lives in the
		// table card, so scope by the whole document (one table per page).
		document.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
			box.checked = all.checked;
		} );
	} );
} )();
