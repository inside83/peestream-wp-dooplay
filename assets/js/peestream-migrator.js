/**
 * PeeStream DooPlay Auto-Migrator Scripts
 * File: assets/js/peestream-migrator.js
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var candidatePostIds = [];
		var currentIndex = 0;
		var isMigrating = false;

		var $btnScan = $('#pst_btn_scan');
		var $btnStart = $('#pst_btn_start_migration');
		var $scanLoading = $('#pst_scan_loading');
		var $scanResults = $('#pst_scan_results');
		var $scanTableBody = $('#pst_scan_table_body');
		var $totalCount = $('#pst_total_posts_found');
		var $console = $('#pst_migration_console');
		var $progressBar = $('#pst_progress_bar');
		var $progressPercent = $('#pst_progress_percent');
		var $progressStatus = $('#pst_progress_status');
		var $progressContainer = $('#pst_progress_container');

		function log(msg) {
			var time = new Date().toLocaleTimeString();
			$console.append('<div>[' + time + '] ' + msg + '</div>');
			$console.scrollTop($console[0].scrollHeight);
		}

		// 1. Scan Database
		$btnScan.on('click', function(e) {
			e.preventDefault();
			$btnScan.prop('disabled', true);
			$scanLoading.slideDown();
			$scanResults.slideUp();
			$btnStart.prop('disabled', true);
			log('Initiating database scan for competitor video hosters...');

			$.ajax({
				url: peestreamMigratorData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_migrator_scan',
					nonce: peestreamMigratorData.nonce
				},
				success: function(res) {
					$btnScan.prop('disabled', false);
					$scanLoading.slideUp();

					if (res.success && res.data) {
						candidatePostIds = res.data.post_ids || [];
						$totalCount.text(res.data.total_posts || 0);

						var counts = res.data.hoster_counts || {};
						var html = '';
						var foundAny = false;

						$.each(counts, function(hoster, count) {
							if (count > 0) foundAny = true;
							html += '<tr>';
							html += '<td style="font-weight:600;text-transform:capitalize;">' + hoster + '</td>';
							html += '<td><span class="peestream-badge-count">' + count + '</span></td>';
							html += '<td>' + (count > 0 ? '<span style="color:#ef4444;font-weight:600;">Candidate</span>' : '<span style="color:#94a3b8;">None</span>') + '</td>';
							html += '</tr>';
						});

						$scanTableBody.html(html);
						$scanResults.slideDown();

						log('Scan finished: Found ' + candidatePostIds.length + ' posts containing competitor links.');

						if (candidatePostIds.length > 0) {
							$btnStart.prop('disabled', false);
						}
					} else {
						log('Scan error: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown'));
					}
				},
				error: function(xhr, status, err) {
					$btnScan.prop('disabled', false);
					$scanLoading.slideUp();
					log('Scan AJAX Error: ' + err);
				}
			});
		});

		// 2. Start Migration Loop
		$btnStart.on('click', function(e) {
			e.preventDefault();
			if (!candidatePostIds.length) return;

			if (!confirm(peestreamMigratorData.i18n.confirm_start)) {
				return;
			}

			isMigrating = true;
			currentIndex = 0;
			$btnStart.prop('disabled', true);
			$btnScan.prop('disabled', true);
			$progressContainer.slideDown();

			var batchSize = parseInt($('#pst_batch_size').val(), 10) || 5;
			var mode = $('#pst_migration_mode').val();
			var targetHoster = $('#pst_filter_hoster').val();

			log('Starting batch migration... Strategy: ' + mode + ', Target: ' + targetHoster);
			runNextBatch(batchSize, mode, targetHoster);
		});

		function runNextBatch(batchSize, mode, targetHoster) {
			if (!isMigrating || currentIndex >= candidatePostIds.length) {
				finishMigration();
				return;
			}

			var chunk = candidatePostIds.slice(currentIndex, currentIndex + batchSize);
			var percent = Math.round((currentIndex / candidatePostIds.length) * 100);
			$progressBar.css('width', percent + '%');
			$progressPercent.text(percent + '%');
			$progressStatus.text('Processing ' + (currentIndex + 1) + ' to ' + Math.min(currentIndex + batchSize, candidatePostIds.length) + ' of ' + candidatePostIds.length + '...');

			$.ajax({
				url: peestreamMigratorData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_migrator_process_batch',
					nonce: peestreamMigratorData.nonce,
					post_ids: chunk,
					mode: mode,
					target_hoster: targetHoster
				},
				success: function(res) {
					if (res.success && res.data) {
						if (res.data.logs && res.data.logs.length) {
							$.each(res.data.logs, function(i, l) {
								log(l);
							});
						}
					} else {
						log('[ERROR] Batch failed: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown error'));
					}

					currentIndex += batchSize;
					// Yield slightly before next cycle
					setTimeout(function() {
						runNextBatch(batchSize, mode, targetHoster);
					}, 300);
				},
				error: function(xhr, status, err) {
					log('[ERROR] AJAX network error in batch: ' + err);
					currentIndex += batchSize;
					setTimeout(function() {
						runNextBatch(batchSize, mode, targetHoster);
					}, 1000);
				}
			});
		}

		function finishMigration() {
			isMigrating = false;
			$progressBar.css('width', '100%');
			$progressPercent.text('100%');
			$progressStatus.text('Migration complete!');
			$btnScan.prop('disabled', false);
			$btnStart.prop('disabled', false);
			log('=== [SUCCESS] Migration finished! All target posts have been processed. ===');
		}
	});
})(jQuery);
