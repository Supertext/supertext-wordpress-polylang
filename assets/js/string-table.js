/**
 * Supertext string table:
 *  - "select all" checkbox,
 *  - reveal the human-order pickers (target language / type / delivery) when the
 *    bulk-action dropdown is set to "Supertext Human Translation",
 *  - and — exactly like the Posts/Pages bulk workflow — fetch a live price quote so
 *    the Delivery dropdown lists the real delivery options with their prices.
 *
 * @package Supertext_Polylang
 */
( function () {
	'use strict';

	var cfg = window.SupertextStringTable || {};
	var i18n = cfg.i18n || {};
	var quoteXhr = null;
	var quoteTimer = null;

	function bulkSelect() {
		return document.querySelector( '.st-bulk-action' );
	}

	function isHuman() {
		var sel = bulkSelect();
		return !! sel && sel.value === 'human';
	}

	// Shows/hides the human pickers (and the quote status line) to match the action.
	function syncPickers() {
		var human = isHuman();
		document.querySelectorAll( '.st-picker-human' ).forEach( function ( el ) {
			// Explicit value, not '': the base .st-picker-human rule is display:none.
			el.style.display = human ? 'inline-flex' : 'none';
		} );
		if ( human ) {
			scheduleQuote();
		}
	}

	// Source strings of the checked rows (each checkbox's value is its row index; the
	// source lives in the matching hidden input name="src[<index>]").
	function selectedSources() {
		var out = [];
		document.querySelectorAll( '.st-row-check:checked' ).forEach( function ( box ) {
			var input = document.querySelector( 'input[name="src[' + box.value + ']"]' );
			if ( input && input.value ) {
				out.push( input.value );
			}
		} );
		return out;
	}

	function status( text ) {
		var el = document.querySelector( '.st-quote-status' );
		if ( el ) {
			el.textContent = text || '';
		}
	}

	function formatPrice( n ) {
		return Number( n ).toFixed( 2 );
	}

	// Collapses Delivery to a single placeholder (options depend on the quote).
	function deliveryPlaceholder( text ) {
		var sel = document.getElementById( 'st-express' );
		if ( ! sel ) {
			return;
		}
		sel.innerHTML = '';
		var opt = document.createElement( 'option' );
		opt.value = '';
		opt.textContent = text || i18n.delivery || 'Delivery';
		sel.appendChild( opt );
		sel.value = '';
	}

	// Rebuilds Delivery from the quote's options (name — price currency) and enables it.
	function populateDelivery( deliveries, cur ) {
		var sel = document.getElementById( 'st-express' );
		if ( ! sel ) {
			return;
		}
		if ( ! deliveries || ! deliveries.length ) {
			deliveryPlaceholder( i18n.noOptions || 'No delivery options' );
			return;
		}

		sel.innerHTML = '';
		var placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = i18n.delivery || 'Delivery';
		sel.appendChild( placeholder );

		var toSelect = '';
		deliveries.forEach( function ( d ) {
			var opt = document.createElement( 'option' );
			opt.value = d.delivery_id;
			opt.textContent = d.name + ' — ' + formatPrice( d.price ) + ' ' + cur;
			if ( d.date ) {
				opt.title = d.date;
			}
			sel.appendChild( opt );
			if ( ! toSelect && d.is_default ) {
				toSelect = String( d.delivery_id );
			}
		} );

		sel.value = toSelect;
	}

	function fetchQuote() {
		if ( ! isHuman() ) {
			return;
		}
		var service = document.getElementById( 'st-service' );
		var lang = document.getElementById( 'st-lang' );
		var sources = selectedSources();

		if ( ! service || ! service.value || ! lang || ! lang.value || ! sources.length ) {
			deliveryPlaceholder();
			status( '' );
			return;
		}

		if ( quoteXhr ) {
			quoteXhr.abort();
		}
		deliveryPlaceholder( i18n.quoting || 'Getting price…' );
		status( i18n.quoting || 'Getting price…' );

		var body = new FormData();
		body.append( 'action', cfg.quoteAction );
		body.append( 'nonce', cfg.quoteNonce );
		body.append( 'target_lang', lang.value );
		body.append( 'service_id', service.value );
		sources.forEach( function ( s ) {
			body.append( 'sources[]', s );
		} );

		quoteXhr = new XMLHttpRequest();
		quoteXhr.open( 'POST', cfg.ajaxUrl );
		quoteXhr.onload = function () {
			quoteXhr = null;
			var res = null;
			try {
				res = JSON.parse( this.responseText );
			} catch ( e ) {
				res = null;
			}
			if ( res && res.success ) {
				var cur = res.data.currencySymbol || res.data.currency || '';
				populateDelivery( res.data.deliveries, cur );
				status( '' );
			} else {
				deliveryPlaceholder();
				status( ( res && res.data && res.data.message ) || i18n.quoteFail || 'Could not get a price.' );
			}
		};
		quoteXhr.onerror = function () {
			quoteXhr = null;
			deliveryPlaceholder();
			status( i18n.quoteFail || 'Could not get a price.' );
		};
		quoteXhr.send( body );
	}

	// Debounce so rapid selection/dropdown changes issue a single request.
	function scheduleQuote() {
		clearTimeout( quoteTimer );
		quoteTimer = setTimeout( fetchQuote, 400 );
	}

	document.addEventListener( 'change', function ( e ) {
		var t = e.target;
		if ( ! t.closest ) {
			return;
		}
		if ( t.closest( '.st-check-all' ) ) {
			document.querySelectorAll( '.st-row-check' ).forEach( function ( box ) {
				box.checked = t.checked;
			} );
			scheduleQuote();
			return;
		}
		if ( t.closest( '.st-row-check' ) ) {
			scheduleQuote();
			return;
		}
		if ( t.closest( '.st-bulk-action' ) ) {
			syncPickers();
			return;
		}
		if ( t.id === 'st-lang' || t.id === 'st-service' ) {
			scheduleQuote();
		}
	} );

	// Validate + confirm before Apply places a Human order.
	document.addEventListener( 'click', function ( e ) {
		var apply = e.target.closest ? e.target.closest( '.st-apply' ) : null;
		if ( ! apply || ! isHuman() ) {
			return;
		}
		var lang = document.getElementById( 'st-lang' );
		var service = document.getElementById( 'st-service' );
		var express = document.getElementById( 'st-express' );

		if ( ! selectedSources().length ) {
			e.preventDefault();
			status( i18n.needRows || 'Select at least one row.' );
			return;
		}
		if ( ! lang || ! lang.value ) {
			e.preventDefault();
			status( i18n.needLang || 'Select a target language.' );
			return;
		}
		if ( ! service || ! service.value ) {
			e.preventDefault();
			status( i18n.needService || 'Select a translation type.' );
			return;
		}
		if ( ! express || ! express.value ) {
			e.preventDefault();
			status( i18n.needDelivery || 'Select a delivery option.' );
			return;
		}
		if ( ! window.confirm( apply.getAttribute( 'data-confirm' ) || 'Confirm?' ) ) {
			e.preventDefault();
		}
	} );

	document.addEventListener( 'DOMContentLoaded', syncPickers );
} )();
