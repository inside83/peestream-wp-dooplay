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
					.empty()
					.append($('<strong>').text('Error: '))
					.append(document.createTextNode(' Please enter an API key first.'))
					.slideDown();
				return;
			}

			$btnCheck.prop('disabled', true).empty()
				.append($('<span class="dashicons dashicons-update peestream-spin"></span>'))
				.append(document.createTextNode(' Connecting...'));
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
					$btnCheck.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-update"></span>'))
						.append(document.createTextNode(' Verify API Connection'));
					
					if (response.success && response.data) {
						$dot.addClass('active');
						$statusTitle.text('Connected to PeeStream Cloud');
						$statusDesc.empty()
							.append(document.createTextNode('Account: '))
							.append($('<strong>').text(response.data.email || ''))
							.append(document.createTextNode(' | Balance: '))
							.append($('<strong>').text(response.data.balance || ''))
							.append(document.createTextNode(' | Storage: '))
							.append($('<strong>').text(response.data.storage_used || ''));

						$feedback.removeClass('peestream-notice-error')
							.addClass('peestream-notice peestream-notice-success')
							.empty()
							.append($('<strong>').text('Success! '))
							.append(document.createTextNode(response.data.message || ''))
							.slideDown();
					} else {
						var err = (response.data && response.data.message) ? response.data.message : 'Unknown API error.';
						$dot.removeClass('active');
						$statusTitle.text('API Connection Failed');
						$statusDesc.text('Could not authenticate with PeeStream API.');

						$feedback.removeClass('peestream-notice-success')
							.addClass('peestream-notice peestream-notice-error')
							.empty()
							.append($('<strong>').text('Error: '))
							.append(document.createTextNode(err))
							.slideDown();
					}
				},
				error: function(xhr, status, error) {
					$btnCheck.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-update"></span>'))
						.append(document.createTextNode(' Verify API Connection'));
					$dot.removeClass('active');
					$feedback.removeClass('peestream-notice-success')
						.addClass('peestream-notice peestream-notice-error')
						.empty()
						.append($('<strong>').text('AJAX Error: '))
						.append(document.createTextNode(error))
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
