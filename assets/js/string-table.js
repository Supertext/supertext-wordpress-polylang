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

	// Confirm before Apply places a Fused (human) order.
	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest ? e.target.closest( '.st-apply' ) : null;
		if ( ! apply ) {
			return;
		}
		var sel = document.querySelector( '.st-bulk-action' );
		if ( sel && sel.value === 'human' && ! window.confirm( apply.getAttribute( 'data-confirm' ) || 'Confirm?' ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', syncPickers );
} )();
