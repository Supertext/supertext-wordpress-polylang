/**
 * Supertext string tables: "select all" checkbox + bulk-action picker toggling.
 *
 * Mirrors the Posts/Pages bulk-actions bar: choosing "Translate with AI" reveals
 * the target-language picker; "Order human translation" also reveals the service
 * and delivery pickers.
 *
 * @package Supertext_Polylang
 */
( function () {
	'use strict';

	function syncPickers( select ) {
		var form = select.closest( 'form' );
		if ( ! form ) {
			return;
		}
		var value = select.value;
		var showLang = value === 'ai' || value === 'human';
		var showHuman = value === 'human';

		form.querySelectorAll( '.st-picker-lang' ).forEach( function ( el ) {
			el.style.display = showLang ? '' : 'none';
		} );
		form.querySelectorAll( '.st-picker-human' ).forEach( function ( el ) {
			el.style.display = showHuman ? '' : 'none';
		} );
	}

	document.addEventListener( 'change', function ( e ) {
		var target = e.target;

		if ( target.closest && target.closest( '.st-check-all' ) ) {
			var form = target.closest( 'form' );
			if ( form ) {
				form.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
					box.checked = target.checked;
				} );
			}
			return;
		}

		if ( target.closest && target.closest( '.st-bulk-action' ) ) {
			syncPickers( target );
		}
	} );

	// Set the initial picker visibility on load.
	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.st-bulk-action' ).forEach( syncPickers );
	} );
} )();
