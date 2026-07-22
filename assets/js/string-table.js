/**
 * Supertext string table: "select all" + reveal the human-order pickers when the
 * bulk-action dropdown is set to human.
 *
 * @package Supertext_Polylang
 */
( function () {
	'use strict';

	function syncPickers() {
		var sel = document.querySelector( '.st-bulk-action' );
		var human = sel && sel.value === 'human';
		document.querySelectorAll( '.st-picker-human' ).forEach( function ( el ) {
			el.style.display = human ? '' : 'none';
		} );
	}

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( t.closest && t.closest( '.st-check-all' ) ) {
			document.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
				box.checked = t.checked;
			} );
			return;
		}
		if ( t.closest && t.closest( '.st-bulk-action' ) ) {
			syncPickers();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', syncPickers );
} )();
