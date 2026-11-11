/**
 * PeeStream WordPress & DooPlay Addon - Admin Scripts
 * File: assets/js/peestream-admin.js
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $btnCheck = $('#pst-btn-check-api');
		var $feedback = $('#pst-api-feedback');
		var $dot = $('.peestream-status-dot');
		var $statusTitle = $('#pst-status-title');
		var $statusDesc = $('#pst-status-desc');

		$btnCheck.on('click', function(e) {
			e.preventDefault();
			var apiKey = $('#pst_api_key').val().trim();

			if (!apiKey) {
				$feedback.removeClass('peestream-notice-success')
					.addClass('peestream-notice peestream-notice-error')
					.html('<strong>Error:</strong> Please enter an API key first.')
					.slideDown();
				return;
			}

			$btnCheck.prop('disabled', true).html('<span class="dashicons dashicons-update peestream-spin"></span> Connecting...');
			$feedback.slideUp();

			$.ajax({
				url: peestreamAdminData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_test_api',
					nonce: peestreamAdminData.nonce,
					api_key: apiKey
				},
				success: function(response) {
					$btnCheck.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Verify API Connection');
					
					if (response.success && response.data) {
						$dot.addClass('active');
						$statusTitle.text('Connected to PeeStream Cloud');
						$statusDesc.html('Account: <strong>' + response.data.email + '</strong> | Balance: <strong>' + response.data.balance + '</strong> | Storage: <strong>' + response.data.storage_used + '</strong>');

						$feedback.removeClass('peestream-notice-error')
							.addClass('peestream-notice peestream-notice-success')
							.html('<strong>Success!</strong> ' + response.data.message)
							.slideDown();
					} else {
						var err = (response.data && response.data.message) ? response.data.message : 'Unknown API error.';
						$dot.removeClass('active');
						$statusTitle.text('API Connection Failed');
						$statusDesc.text('Could not authenticate with PeeStream API.');

						$feedback.removeClass('peestream-notice-success')
							.addClass('peestream-notice peestream-notice-error')
							.html('<strong>Error:</strong> ' + err)
							.slideDown();
					}
				},
				error: function(xhr, status, error) {
					$btnCheck.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Verify API Connection');
					$dot.removeClass('active');
					$feedback.removeClass('peestream-notice-success')
						.addClass('peestream-notice peestream-notice-error')
						.html('<strong>AJAX Error:</strong> ' + error)
						.slideDown();
				}
			});
		});
	});

	window.pstToggleSecret = function(inputId) {
		var input = document.getElementById(inputId);
		if (input) {
			input.type = (input.type === 'password') ? 'text' : 'password';
		}
	};
})(jQuery);
