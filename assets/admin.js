( function ( $ ) {
	'use strict';

	$( function () {
		var $button = $( '#euromail-verify-key' );
		var $result = $( '#euromail-verify-result' );

		if ( ! $button.length || typeof euromailAdmin === 'undefined' ) {
			return;
		}

		$button.on( 'click', function () {
			var originalText = $button.text();

			$button.prop( 'disabled', true ).text( euromailAdmin.verifyingText );
			$result.text( '' );

			$.post( euromailAdmin.ajaxUrl, {
				action: 'euromail_verify_key',
				nonce: euromailAdmin.nonce
			} ).done( function ( response ) {
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
