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
		var $btnCheckQueue = $('#pst_btn_check_queue');
		var $btnRollback = $('#pst_btn_rollback');
		var $scanLoading = $('#pst_scan_loading');
		var $scanResults = $('#pst_scan_results');
		var $scanTableBody = $('#pst_scan_table_body');
		var $totalCount = $('#pst_total_posts_found');
		var $console = $('#pst_migration_console');
		var $progressBar = $('#pst_progress_bar');
		var $progressPercent = $('#pst_progress_percent');
		var $progressStatus = $('#pst_progress_status');
		var $progressContainer = $('#pst_progress_container');
		var $dryRun = $('#pst_dry_run');

		function log(msg) {
			var time = new Date().toLocaleTimeString();
			var $line = $('<div/>').text('[' + time + '] ' + msg);
			$console.append($line);
			$console.scrollTop($console[0].scrollHeight);
		}

		// 1. Scan Database
		$btnScan.on('click', function(e) {
			e.preventDefault();
			$btnScan.prop('disabled', true);
			$scanLoading.slideDown();
			$scanResults.slideUp();
			$btnStart.prop('disabled', true);
			log('Initiating database scan for confirmed competitor video hosters...');

			candidatePostIds = [];
			scanChunk(0);
		});

		function scanChunk(offset) {
			$.ajax({
				url: peestreamMigratorData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_migrator_scan',
					nonce: peestreamMigratorData.nonce,
					offset: offset,
					limit: 250
				},
				success: function(res) {
					if (res.success && res.data) {
						var chunkIds = res.data.post_ids || [];
						candidatePostIds = candidatePostIds.concat(chunkIds);
						// Deduplicate
						candidatePostIds = candidatePostIds.filter(function(item, pos) {
							return candidatePostIds.indexOf(item) === pos;
						});

						$totalCount.text(candidatePostIds.length);

						if (res.data.has_more && res.data.next_offset) {
							log('Scanned ' + res.data.next_offset + ' of ' + res.data.total_records + ' records...');
							scanChunk(res.data.next_offset);
							return;
						}

						// Finished scanning all chunks
						$btnScan.prop('disabled', false);
						$scanLoading.slideUp();

						var counts = res.data.hoster_counts || {};
						$scanTableBody.empty();

						$.each(counts, function(hoster, count) {
							var $tr = $('<tr>');
							var $tdName = $('<td style="font-weight:600;text-transform:capitalize;">').text(hoster);
							var $tdCount = $('<td>').append($('<span class="peestream-badge-count">').text(count));
							var $tdStatus = $('<td>');

							if (count > 0) {
								$tdStatus.append($('<span style="color:#ef4444;font-weight:600;">').text('Candidate'));
							} else {
								$tdStatus.append($('<span style="color:#94a3b8;">').text('None'));
							}

							$tr.append($tdName).append($tdCount).append($tdStatus);
							$scanTableBody.append($tr);
						});

						$scanResults.slideDown();
						log('Scan completed: Found ' + candidatePostIds.length + ' posts containing confirmed competitor links.');

						if (candidatePostIds.length > 0) {
							$btnStart.prop('disabled', false);
						}
					} else {
						$btnScan.prop('disabled', false);
						$scanLoading.slideUp();
						log('Scan error: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown error'));
					}
				},
				error: function(xhr, status, err) {
					$btnScan.prop('disabled', false);
					$scanLoading.slideUp();
					log('Scan AJAX Error: ' + err);
				}
			});
		}

		var totalCompleted = 0;
		var totalPending = 0;
		var totalFailed = 0;
		var totalSkipped = 0;
		var totalBatchErrors = 0;

		// 2. Start Migration Loop
		$btnStart.on('click', function(e) {
			e.preventDefault();
			if (!candidatePostIds.length) return;

			var isDryRun = $dryRun.is(':checked');
			if (!isDryRun && !confirm(peestreamMigratorData.i18n.confirm_start)) {
				return;
			}

			isMigrating = true;
			currentIndex = 0;
			totalCompleted = 0;
			totalPending = 0;
			totalFailed = 0;
			totalSkipped = 0;
			totalBatchErrors = 0;
			$btnStart.prop('disabled', true);
			$btnScan.prop('disabled', true);
			$progressContainer.slideDown();

			var batchSize = parseInt($('#pst_batch_size').val(), 10) || 5;
			var mode = $('#pst_migration_mode').val();
			var targetHoster = $('#pst_filter_hoster').val();

			log('Starting batch migration... Strategy: ' + mode + ', Target: ' + targetHoster + (isDryRun ? ' [DRY-RUN]' : ''));
			runNextBatch(batchSize, mode, targetHoster, isDryRun);
		});

		function runNextBatch(batchSize, mode, targetHoster, isDryRun) {
			if (!isMigrating || currentIndex >= candidatePostIds.length) {
				finishMigration(isDryRun);
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
					target_hoster: targetHoster,
					dry_run: isDryRun ? 1 : 0
				},
				success: function(res) {
					if (res.success && res.data) {
						if (res.data.logs && res.data.logs.length) {
							$.each(res.data.logs, function(i, l) {
								log(l);
							});
						}
						totalCompleted += parseInt(res.data.completed_count || 0, 10);
						totalPending   += parseInt(res.data.pending_count || 0, 10);
						totalFailed    += parseInt(res.data.failed_count || 0, 10);
						totalSkipped   += parseInt(res.data.skipped_count || 0, 10);
					} else {
						totalBatchErrors++;
						log('[ERROR] Batch failed: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown error'));
					}

					currentIndex += batchSize;
					setTimeout(function() {
						runNextBatch(batchSize, mode, targetHoster, isDryRun);
					}, 250);
				},
				error: function(xhr, status, err) {
					totalBatchErrors++;
					log('[ERROR] AJAX network error in batch: ' + err);
					currentIndex += batchSize;
					setTimeout(function() {
						runNextBatch(batchSize, mode, targetHoster, isDryRun);
					}, 1000);
				}
			});
		}

		function finishMigration(isDryRun) {
			isMigrating = false;
			$progressBar.css('width', '100%');
			$progressPercent.text('100%');
			$btnScan.prop('disabled', false);
			$btnStart.prop('disabled', false);

			if (isDryRun) {
				$progressStatus.text('Dry run finished!');
				log('=== [FINISHED] Dry run preview completed. ===');
				return;
			}

			var summary = 'Processed: ' + totalCompleted + ' completed, ' +
			              totalPending + ' pending encoding, ' +
			              totalSkipped + ' skipped, ' +
			              totalFailed + ' failed/conflict' +
			              (totalBatchErrors > 0 ? (', ' + totalBatchErrors + ' batch errors') : '') + '.';

			if (totalFailed > 0 || totalBatchErrors > 0) {
				$progressStatus.text('Migration finished with issues. ' + summary);
				log('=== [FINISHED WITH ISSUES] ' + summary + ' ===');
			} else if (totalCompleted === 0 && totalPending > 0) {
				$progressStatus.text('Queued / Pending encoding. ' + summary);
				log('=== [QUEUED / PENDING ENCODING] ' + summary + ' ===');
			} else {
				$progressStatus.text('Batch migration complete! ' + summary);
				log('=== [FINISHED] All candidate posts processed successfully. ' + summary + ' ===');
			}
		}

		// 3. Check Pending Encodings
		$btnCheckQueue.on('click', function(e) {
			e.preventDefault();
			$btnCheckQueue.prop('disabled', true);
			log('Checking status of pending PeeStream transcodings in queue...');

			$.ajax({
				url: peestreamMigratorData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_migrator_check_queue',
					nonce: peestreamMigratorData.nonce
				},
				success: function(res) {
					$btnCheckQueue.prop('disabled', false);
					if (res.success && res.data) {
						if (res.data.logs && res.data.logs.length) {
							$.each(res.data.logs, function(i, l) {
								log(l);
							});
						}
						log('Queue check complete: ' + res.data.ready_count + ' new videos are ready and attached.');
					} else {
						log('Queue check error: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown'));
					}
				},
				error: function(xhr, status, err) {
					$btnCheckQueue.prop('disabled', false);
					log('Queue check AJAX error: ' + err);
				}
			});
		});

		// 4. Rollback Migrated Posts
		$btnRollback.on('click', function(e) {
			e.preventDefault();
			if (!confirm(peestreamMigratorData.i18n.confirm_rollback)) {
				return;
			}

			$btnRollback.prop('disabled', true);
			log('Initiating rollback from backup storage...');

			$.ajax({
				url: peestreamMigratorData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_migrator_rollback',
					nonce: peestreamMigratorData.nonce
				},
				success: function(res) {
					$btnRollback.prop('disabled', false);
					if (res.success && res.data) {
						if (res.data.logs && res.data.logs.length) {
							$.each(res.data.logs, function(i, l) {
								log(l);
							});
						}
						log('=== ' + res.data.message + ' ===');
					} else {
						log('Rollback error: ' + ((res.data && res.data.message) ? res.data.message : 'Unknown'));
					}
				},
				error: function(xhr, status, err) {
					$btnRollback.prop('disabled', false);
					log('Rollback AJAX error: ' + err);
				}
			});
		});
	});
})(jQuery);
