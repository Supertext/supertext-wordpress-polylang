/**
 * Inline per-string AI translation for the Gravity Forms string editor.
 *
 * Each cell has an "AI" button; clicking it asks Supertext (via admin-ajax) to
 * translate that single source string into the cell's language and drops the
 * result into the textarea for review. Nothing is saved until the user submits
 * the form.
 *
 * @package Supertext_Polylang
 */
( function () {
	'use strict';

	var cfg = window.SupertextGFEditor || {};

	function fieldFor( lang, i ) {
		return document.querySelector(
			'textarea[name="tr[' + lang + '][' + i + ']"]'
		);
	}

	function flash( btn, label ) {
		btn.textContent = label;
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.st-gf-ai' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var i = btn.getAttribute( 'data-i' );
		var lang = btn.getAttribute( 'data-lang' );
		var source = btn.getAttribute( 'data-source' );
		var box = fieldFor( lang, i );
		var formId = ( document.querySelector( 'input[name="form_id"]' ) || {} ).value || '';
		var original = btn.textContent;

		if ( btn.disabled ) {
			return;
		}
		btn.disabled = true;
		flash( btn, cfg.i18n ? cfg.i18n.busy : '…' );

		var body = new URLSearchParams();
		body.append( 'action', cfg.action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'form_id', formId );
		body.append( 'lang', lang );
		body.append( 'source', source );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if (
					res &&
					res.success &&
					res.data &&
					typeof res.data.translation === 'string'
				) {
					if ( box ) {
						box.value = res.data.translation;
						box.focus();
					}
					btn.disabled = false;
					flash( btn, original );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : ( cfg.i18n ? cfg.i18n.error : 'Failed' );
					if ( box ) {
						box.setAttribute( 'title', msg );
					}
					flash( btn, cfg.i18n ? cfg.i18n.error : 'Failed' );
					window.setTimeout( function () {
						btn.disabled = false;
						flash( btn, original );
					}, 2000 );
				}
			} )
			.catch( function () {
				flash( btn, cfg.i18n ? cfg.i18n.error : 'Failed' );
				window.setTimeout( function () {
					btn.disabled = false;
					flash( btn, original );
				}, 2000 );
			} );
	} );
} )();
