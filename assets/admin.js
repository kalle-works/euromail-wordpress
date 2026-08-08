( function ( $ ) {
	'use strict';

	$( function () {
		var $button = $( '#euromail-verify-key' );
		var $result = $( '#euromail-verify-result' );
		var $apiKeyField = $( '#euromail_api_key' );

		if ( ! $button.length || typeof euromailAdmin === 'undefined' ) {
			return;
		}

		$button.on( 'click', function () {
			var originalText = $button.text();
			// Verify whatever is currently typed in the field. The server
			// falls back to the saved key on its own when this is empty —
			// it is never sent as an empty string override.
			var typedKey = $.trim( $apiKeyField.val() || '' );
			var payload = {
				action: 'euromail_verify_key',
				nonce: euromailAdmin.nonce
			};

			if ( typedKey ) {
				payload.api_key = typedKey;
			}

			$button.prop( 'disabled', true ).text( euromailAdmin.verifyingText );
			$result.text( '' );

			$.post( euromailAdmin.ajaxUrl, payload ).done( function ( response ) {
				if ( response && response.success ) {
					$result.text( response.data.message ).css( 'color', 'green' );
				} else {
					var message = response && response.data && response.data.message
						? response.data.message
						: euromailAdmin.verifyText;
					$result.text( message ).css( 'color', 'red' );
				}
			} ).fail( function () {
				$result.text( euromailAdmin.verifyText ).css( 'color', 'red' );
			} ).always( function () {
				$button.prop( 'disabled', false ).text( originalText );
			} );
		} );
	} );
} )( jQuery );
