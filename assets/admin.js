( function ( $ ) {
	'use strict';

	$( function () {
		if ( typeof euromailAdmin === 'undefined' ) {
			return;
		}

		initVerifyKeyButton();
		initSmtpRelayPreset();
	} );

	function initVerifyKeyButton() {
		var $button = $( '#euromail-verify-key' );
		var $result = $( '#euromail-verify-result' );
		var $apiKeyField = $( '#euromail_api_key' );

		if ( ! $button.length ) {
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
	}

	function initSmtpRelayPreset() {
		var $button = $( '#euromail-smtp-relay-preset' );

		if ( ! $button.length || ! euromailAdmin.relayPreset ) {
			return;
		}

		$button.on( 'click', function () {
			var preset = euromailAdmin.relayPreset;
			var apiKey = $.trim( $( '#euromail_api_key' ).val() || '' );

			$( '#euromail_smtp_host' ).val( preset.host );
			$( '#euromail_smtp_port' ).val( preset.port );
			$( '#euromail_smtp_encryption' ).val( preset.encryption );
			$( '#euromail_smtp_auth' ).prop( 'checked', true );
			$( '#euromail_smtp_username' ).val( preset.username );

			var $password = $( '#euromail_smtp_password' );
			if ( apiKey && ! $password.prop( 'disabled' ) ) {
				$password.val( apiKey );
			}
		} );
	}
} )( jQuery );
