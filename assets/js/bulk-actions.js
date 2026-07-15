( function ( $ ) {
	'use strict';

	var ACTION_AI    = 'supertext_ai_translation';
	var ACTION_HUMAN = 'supertext_human_translation';

	var cfg = window.SupertextBulk || {};
	var quoteXhr = null;
	var quoteTimer = null;

	function isSupertextAction( val ) {
		return val === ACTION_AI || val === ACTION_HUMAN;
	}

	// Returns the selected Supertext action from either bulk-action dropdown, or ''.
	function activeAction() {
		var top = $( '#bulk-action-selector-top' ).val();
		if ( isSupertextAction( top ) ) {
			return top;
		}
		var bottom = $( '#bulk-action-selector-bottom' ).val();
		if ( isSupertextAction( bottom ) ) {
			return bottom;
		}
		return '';
	}

	function updatePickerVisibility() {
		var action = activeAction();
		// Language picker shows for both AI and Human.
		$( '#supertext-lang-picker' ).toggle( isSupertextAction( action ) );
		// Translation type + delivery only apply to Human translation.
		$( '#supertext-service-picker' ).toggle( action === ACTION_HUMAN );
		$( '#supertext-express-picker' ).toggle( action === ACTION_HUMAN );
		$( '#supertext-quote-status' ).toggle( action === ACTION_HUMAN );
		if ( action === ACTION_HUMAN ) {
			scheduleQuote();
		}
	}

	function flag( selector ) {
		$( selector ).css( 'outline', '2px solid #d63638' ).focus();
	}

	function onSubmit( e ) {
		var action = activeAction();
		if ( ! action ) {
			return;
		}
		if ( ! $( '#supertext_target_lang' ).val() ) {
			e.preventDefault();
			$( '#supertext-lang-picker' ).show();
			flag( '#supertext_target_lang' );
			return;
		}
		if ( action === ACTION_HUMAN && ! $( '#supertext_service_id' ).val() ) {
			e.preventDefault();
			$( '#supertext-service-picker' ).show();
			flag( '#supertext_service_id' );
			return;
		}
		if ( action === ACTION_HUMAN && ! $( '#supertext_express' ).val() ) {
			e.preventDefault();
			$( '#supertext-express-picker' ).show();
			flag( '#supertext_express' );
		}
	}

	// --- Live quote -------------------------------------------------------------

	function selectedPostIds() {
		var ids = [];
		$( '#the-list input[name="post[]"]:checked' ).each( function () {
			ids.push( $( this ).val() );
		} );
		return ids;
	}

	function formatPrice( n ) {
		return Number( n ).toFixed( 2 );
	}

	function status( text ) {
		$( '#supertext-quote-status' ).text( text || '' );
	}

	// Restore delivery <option> labels to their originals (strip any price).
	function resetDeliveryLabels() {
		$( '#supertext_express option' ).each( function () {
			var $o = $( this );
			if ( $o.data( 'label' ) ) {
				$o.text( $o.data( 'label' ) );
			}
		} );
	}

	function renderQuote( data ) {
		var cur = data.currencySymbol || data.currency || '';
		var byId = {};
		( data.deliveries || [] ).forEach( function ( d ) {
			byId[ String( d.delivery_id ) ] = d;
		} );

		$( '#supertext_express option' ).each( function () {
			var $o = $( this );
			var v  = $o.attr( 'value' );
			if ( ! $o.data( 'label' ) ) {
				$o.data( 'label', $o.text() );
			}
			if ( ! v ) {
				return;
			}
			var d = byId[ String( v ) ];
			$o.text( d ? $o.data( 'label' ) + ' — ' + formatPrice( d.price ) + ' ' + cur : $o.data( 'label' ) );
		} );

		var min = null;
		( data.deliveries || [] ).forEach( function ( d ) {
			if ( min === null || d.price < min ) {
				min = d.price;
			}
		} );

		var parts = [];
		if ( data.wordCount ) {
			parts.push( data.wordCount + ' ' + cfg.i18n.words );
		}
		if ( min !== null ) {
			parts.push( cfg.i18n.from + ' ' + formatPrice( min ) + ' ' + cur );
		}
		status( parts.join( ' · ' ) );
	}

	function fetchQuote() {
		if ( activeAction() !== ACTION_HUMAN ) {
			return;
		}
		var service = $( '#supertext_service_id' ).val();
		var lang    = $( '#supertext_target_lang' ).val();
		var ids     = selectedPostIds();

		if ( ! service || ! lang || ! ids.length ) {
			resetDeliveryLabels();
			status( '' );
			return;
		}

		if ( quoteXhr ) {
			quoteXhr.abort();
		}
		status( cfg.i18n.quoting );

		quoteXhr = $.post( cfg.ajaxUrl, {
			action: cfg.quoteAction,
			nonce: cfg.quoteNonce,
			target_lang: lang,
			service_id: service,
			post_ids: ids
		} ).done( function ( res ) {
			if ( res && res.success ) {
				renderQuote( res.data );
			} else {
				resetDeliveryLabels();
				status( ( res && res.data && res.data.message ) || cfg.i18n.quoteFail );
			}
		} ).fail( function ( xhr, textStatus ) {
			if ( textStatus === 'abort' ) {
				return;
			}
			resetDeliveryLabels();
			status( cfg.i18n.quoteFail );
		} );
	}

	// Debounce so rapid selection/dropdown changes issue a single request.
	function scheduleQuote() {
		clearTimeout( quoteTimer );
		quoteTimer = setTimeout( fetchQuote, 400 );
	}

	$( function () {
		if ( ! cfg.ajaxUrl ) {
			cfg = window.SupertextBulk || {};
		}

		// Place the pickers between the bulk-action select and the Apply button,
		// in order: language, translation type, delivery, then the price readout.
		$( '#supertext-lang-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-service-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-express-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '<span id="supertext-quote-status" style="display:none;margin-left:8px;color:#1d2327;font-style:italic;vertical-align:middle;"></span>' ).insertAfter( '#doaction' );

		$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).on( 'change', updatePickerVisibility );
		$( '#supertext_target_lang, #supertext_service_id' ).on( 'change', scheduleQuote );
		// Selection changes (row checkboxes + the header "select all") re-quote.
		$( document ).on( 'change', '#the-list input[name="post[]"], #cb-select-all-1, #cb-select-all-2', scheduleQuote );
		$( '#posts-filter' ).on( 'submit', onSubmit );

		updatePickerVisibility();
	} );

} )( jQuery );
