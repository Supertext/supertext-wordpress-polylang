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
		var form = all.closest( 'form' );
		if ( ! form ) {
			return;
		}
		form.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
			box.checked = all.checked;
		} );
	} );
} )();
