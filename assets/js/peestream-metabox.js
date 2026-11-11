/**
 * PeeStream DooPlay Metabox Scripts
 * File: assets/js/peestream-metabox.js
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $container = $('.peestream-mb-container');
		if (!$container.length) return;

		var postId = $container.data('post-id');
		var $alert = $('#pst_mb_alert');

		function showAlert(msg, isSuccess) {
			$alert.removeClass('peestream-mb-alert-success peestream-mb-alert-error')
				.addClass(isSuccess ? 'peestream-mb-alert-success' : 'peestream-mb-alert-error')
				.text(msg)
				.slideDown();
			setTimeout(function() {
				$alert.slideUp();
			}, 6000);
		}

		// Tab navigation
		$('.peestream-mb-tab-btn').on('click', function(e) {
			e.preventDefault();
			var tab = $(this).data('tab');
			$('.peestream-mb-tab-btn').removeClass('active');
			$(this).addClass('active');

			$('.peestream-mb-tab-pane').removeClass('active');
			$('#pst_tab_' + tab).addClass('active');
		});

		// 1. Add Quick Player
		$('#pst_btn_add_quick').on('click', function(e) {
			e.preventDefault();
			var code = $('#pst_quick_code').val().trim();
			var name = $('#pst_quick_name').val().trim();
			var lang = $('#pst_quick_lang').val();
			var quality = $('#pst_quick_quality').val();
			var position = $('#pst_quick_position').val();

			if (!code) {
				showAlert(peestreamMetaboxData.i18n.empty_code, false);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(peestreamMetaboxData.i18n.submitting);

			$.ajax({
				url: peestreamMetaboxData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_add_player',
					nonce: peestreamMetaboxData.nonce,
					post_id: postId,
					code: code,
					name: name,
					language: lang,
					quality: quality,
					position: position
				},
				success: function(res) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-plus-alt2"></span>'))
						.append(document.createTextNode(' Add to DooPlay Players'));
					if (res.success && res.data) {
						$('#pst_servers_table_wrapper').html(res.data.table_html);
						$('#pst_servers_count').text(res.data.count);
						$('#pst_quick_code').val('');
						showAlert(res.data.message, true);
					} else {
						showAlert((res.data && res.data.message) ? res.data.message : 'Error adding player.', false);
					}
				},
				error: function(xhr, status, err) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-plus-alt2"></span>'))
						.append(document.createTextNode(' Add to DooPlay Players'));
					showAlert('AJAX Error: ' + err, false);
				}
			});
		});

		// 2. Submit Remote URL
		$('#pst_btn_submit_remote').on('click', function(e) {
			e.preventDefault();
			var url = $('#pst_remote_url').val().trim();
			var title = $('#pst_remote_title').val().trim();

			if (!url) {
				showAlert(peestreamMetaboxData.i18n.empty_url, false);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(peestreamMetaboxData.i18n.submitting);

			$.ajax({
				url: peestreamMetaboxData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_metabox_remote_upload',
					nonce: peestreamMetaboxData.nonce,
					post_id: postId,
					url: url,
					title: title
				},
				success: function(res) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-cloud-upload"></span>'))
						.append(document.createTextNode(' Upload & Attach Server'));
					if (res.success && res.data && res.data.filecode) {
						// Auto-populate quick code tab and trigger insertion
						$('#pst_quick_code').val(res.data.filecode);
						$('#pst_btn_add_quick').trigger('click');
						$('#pst_remote_url').val('');
					} else {
						showAlert((res.data && res.data.message) ? res.data.message : 'Remote upload failed.', false);
					}
				},
				error: function(xhr, status, err) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-cloud-upload"></span>'))
						.append(document.createTextNode(' Upload & Attach Server'));
					showAlert('AJAX Error: ' + err, false);
				}
			});
		});

		// 3. Submit IMDb Importer
		$('#pst_btn_submit_imdb').on('click', function(e) {
			e.preventDefault();
			var imdb = $('#pst_imdb_id').val().trim();
			var s = $('#pst_imdb_season').val().trim();
			var ep = $('#pst_imdb_episode').val().trim();

			if (!imdb) {
				showAlert(peestreamMetaboxData.i18n.empty_imdb, false);
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true).text(peestreamMetaboxData.i18n.submitting);

			$.ajax({
				url: peestreamMetaboxData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_metabox_imdb_upload',
					nonce: peestreamMetaboxData.nonce,
					post_id: postId,
					imdb: imdb,
					season: s,
					episode: ep
				},
				success: function(res) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-tickets-alt"></span>'))
						.append(document.createTextNode(' Queue IMDb Video & Attach'));
					if (res.success && res.data && res.data.filecode) {
						$('#pst_quick_code').val(res.data.filecode);
						$('#pst_btn_add_quick').trigger('click');
					} else {
						showAlert((res.data && res.data.message) ? res.data.message : 'IMDb import failed.', false);
					}
				},
				error: function(xhr, status, err) {
					$btn.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-tickets-alt"></span>'))
						.append(document.createTextNode(' Queue IMDb Video & Attach'));
					showAlert('AJAX Error: ' + err, false);
				}
			});
		});

		// 4. Remove Player Server from Table
		$(document).on('click', '.pst-btn-remove-row', function(e) {
			e.preventDefault();
			if (!confirm(peestreamMetaboxData.i18n.confirm_remove)) {
				return;
			}

			var index = $(this).data('index');
			var $btn = $(this);
			$btn.prop('disabled', true);

			$.ajax({
				url: peestreamMetaboxData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_remove_player',
					nonce: peestreamMetaboxData.nonce,
					post_id: postId,
					index: index
				},
				success: function(res) {
					if (res.success && res.data) {
						$('#pst_servers_table_wrapper').html(res.data.table_html);
						$('#pst_servers_count').text(res.data.count);
						showAlert(res.data.message, true);
					} else {
						showAlert((res.data && res.data.message) ? res.data.message : 'Failed to remove player.', false);
					}
				},
				error: function(xhr, status, err) {
					showAlert('AJAX Error: ' + err, false);
				}
			});
		});
	});
})(jQuery);
