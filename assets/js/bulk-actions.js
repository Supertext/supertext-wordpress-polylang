( function ( $ ) {
	'use strict';

	var ACTION_AI    = 'supertext_ai_translation';
	var ACTION_HUMAN = 'supertext_human_translation';

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

	$( function () {
		// Place the pickers between the bulk-action select and the Apply button,
		// in order: language, translation type, delivery.
		$( '#supertext-lang-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-service-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );
		$( '#supertext-express-picker' ).insertBefore( '#doaction' ).css( 'margin-right', '4px' );

		$( '#bulk-action-selector-top, #bulk-action-selector-bottom' ).on( 'change', updatePickerVisibility );
		$( '#posts-filter' ).on( 'submit', onSubmit );

		updatePickerVisibility();
	} );

} )( jQuery );
