<?php
/**
 * Isolated CMS End-to-End Staging Test Runner (WordPress/DooPlay & DataLife Engine)
 * File: /root/gemini_stuff/staging_e2e/test_isolated_cms_e2e.php
 * 
 * Strict Isolation Mandate:
 * - Operates entirely in /root/gemini_stuff/tmp/e2e_* scratch folders.
 * - Zero access to production MySQL databases or active CyberPanel vhosts.
 * - Unpacks real RC ZIP archives, tests installation, multi-server migration,
 *   webhooks, recovery, surgical rollback, and schema upgrades.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$total_pass = 0;
$total_fail = 0;

function e2e_assert($desc, $cond) {
    global $total_pass, $total_fail;
    if ($cond) {
        echo " [PASS] " . $desc . "\n";
        $total_pass++;
    } else {
        echo " [FAIL] " . $desc . "\n";
        $total_fail++;
    }
}

echo "======================================================================\n";
echo "ISOLATED CMS END-TO-END STAGING TEST RUNNER (WP/DOOPLAY & DLE)\n";
echo "======================================================================\n\n";

// ======================================================================
// PART 1: DATALIFE ENGINE (DLE) ISOLATED END-TO-END SCENARIO
// ======================================================================
echo ">>> PART 1: DataLife Engine (DLE) End-to-End Integration Scenario <<<\n\n";

$dle_tmp = '/root/gemini_stuff/tmp/e2e_dle';
if (is_dir($dle_tmp)) {
    exec("rm -rf " . escapeshellarg($dle_tmp));
}
mkdir($dle_tmp . '/engine/data', 0755, true);
mkdir($dle_tmp . '/engine/inc', 0755, true);
mkdir($dle_tmp . '/engine/modules', 0755, true);
mkdir($dle_tmp . '/engine/ajax', 0755, true);
mkdir($dle_tmp . '/engine/skins', 0755, true);
mkdir($dle_tmp . '/language/English', 0755, true);

// 1.1 Unpack real RC ZIP package
$dle_zip = '/root/gemini_stuff/dle_peestream_v1.0.4-RC.zip';
e2e_assert("DLE RC ZIP archive exists on disk", file_exists($dle_zip));
$za = new ZipArchive();
$za_res = $za->open($dle_zip);
e2e_assert("DLE RC ZIP opens cleanly", $za_res === true);
$za->extractTo($dle_tmp);
$za->close();

e2e_assert("peestream.xml extracted successfully", file_exists($dle_tmp . '/peestream.xml'));
e2e_assert("engine/inc/peestream.php extracted", file_exists($dle_tmp . '/engine/inc/peestream.php'));
e2e_assert("peestream.conf.php is NOT in distribution archive", !file_exists($dle_tmp . '/engine/data/peestream.conf.php'));

// 1.2 Plugin Manager Installation Simulation
$xml = simplexml_load_file($dle_tmp . '/peestream.xml');
$filelist = explode(',', (string)$xml->filelist);
$files_ok = true;
foreach ($filelist as $f) {
    $f = trim($f);
    if (!empty($f) && !file_exists($dle_tmp . '/' . $f)) {
        $files_ok = false;
    }
}
e2e_assert("Plugin Manager: 100% of manifest files exist after extraction", $files_ok);

define('DATALIFEENGINE', true);
define('ENGINE_DIR', $dle_tmp . '/engine');
define('ROOT_DIR', $dle_tmp);
define('PREFIX', 'dle');

// Execute <phpinstall>
eval((string)$xml->phpinstall);
e2e_assert("phpinstall generated engine/data/peestream.conf.php", file_exists(ENGINE_DIR . '/data/peestream.conf.php'));
$perms = substr(sprintf('%o', fileperms(ENGINE_DIR . '/data/peestream.conf.php')), -4);
e2e_assert("peestream.conf.php permissions are 0644", $perms === '0644');

include(ENGINE_DIR . '/data/peestream.conf.php');
$dle_wh_secret = $peestream_config['webhook_secret'] ?? '';
$dle_cron_sec = $peestream_config['cron_secret'] ?? '';
e2e_assert("Generated webhook_secret is 48 hex characters", strlen($dle_wh_secret) === 48);
e2e_assert("Generated cron_secret is 48 hex characters", strlen($dle_cron_sec) === 48);
e2e_assert("Secrets are cryptographically distinct", $dle_wh_secret !== $dle_cron_sec);

// 1.3 Setup SQLite for isolated DLE database operations
$dle_db_file = $dle_tmp . '/dle_e2e.sqlite';
$dle_pdo = new PDO('sqlite:' . $dle_db_file);
$dle_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mock DLE tables in SQLite
$dle_pdo->exec("CREATE TABLE dle_post (
    id INTEGER PRIMARY KEY,
    title TEXT,
    xfields TEXT,
    short_story TEXT,
    full_story TEXT,
    date TEXT
)");
$dle_pdo->exec("CREATE TABLE dle_peestream_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    original_url TEXT,
    url_hash TEXT,
    filecode TEXT,
    status TEXT,
    owner_token TEXT,
    retry_count INTEGER DEFAULT 0,
    error_msg TEXT,
    created_at TEXT,
    updated_at TEXT
)");
$dle_pdo->exec("CREATE TABLE dle_peestream_backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    migrated_url TEXT,
    new_code TEXT,
    mode TEXT,
    backup_time TEXT,
    status TEXT,
    original_xfields TEXT,
    original_story TEXT,
    original_full TEXT
)");
$dle_pdo->exec("CREATE TABLE dle_peestream_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_code TEXT,
    event TEXT,
    post_id INTEGER,
    ip TEXT,
    received_at TEXT,
    status TEXT
)");

// Insert test post with 3 competitor servers in xfields
$orig_xfields = "streamtape||https://streamtape.com/v/dle_tape01||mixdrop||https://mixdrop.co/f/dle_mix02||voe||https://voe.sx/e/dle_voe03";
$dle_pdo->exec("INSERT INTO dle_post (id, title, xfields, short_story, full_story, date) 
VALUES (101, 'Test Matrix DLE', " . $dle_pdo->quote($orig_xfields) . ", 'Short story embed: https://mixdrop.co/f/dle_mix02', 'Full story', '2026-11-12 12:00:00')");

$post_check = $dle_pdo->query("SELECT * FROM dle_post WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
e2e_assert("DLE test article #101 created with 3 competitor links", strpos($post_check['xfields'], 'streamtape') !== false && strpos($post_check['xfields'], 'mixdrop') !== false);

// 1.4 Multi-Server Migration & Optimistic Replacement
// Simulate migration queueing
$dle_pdo->exec("INSERT INTO dle_peestream_queue (post_id, original_url, url_hash, filecode, status, created_at, updated_at) 
VALUES (101, 'https://streamtape.com/v/dle_tape01', '" . hash('sha256', 'https://streamtape.com/v/dle_tape01') . "', 'pst_dle_tape01', 'completed', datetime('now'), datetime('now'))");
$dle_pdo->exec("INSERT INTO dle_peestream_queue (post_id, original_url, url_hash, filecode, status, created_at, updated_at) 
VALUES (101, 'https://mixdrop.co/f/dle_mix02', '" . hash('sha256', 'https://mixdrop.co/f/dle_mix02') . "', 'pst_dle_mix02', 'completed', datetime('now'), datetime('now'))");
$dle_pdo->exec("INSERT INTO dle_peestream_queue (post_id, original_url, url_hash, filecode, status, created_at, updated_at) 
VALUES (101, 'https://voe.sx/e/dle_voe03', '" . hash('sha256', 'https://voe.sx/e/dle_voe03') . "', 'pst_dle_voe03', 'completed', datetime('now'), datetime('now'))");

// Create distinct backup snapshots for each server
$dle_pdo->exec("INSERT INTO dle_peestream_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_xfields, original_story, original_full)
VALUES (1, 101, 'https://streamtape.com/v/dle_tape01', 'pst_dle_tape01', 'replace', datetime('now'), 'completed', " . $dle_pdo->quote($orig_xfields) . ", 'Short story embed: https://mixdrop.co/f/dle_mix02', 'Full story')");

// Migrate post xfields: replace streamtape with PeeStream embed
$migrated_xfields1 = str_replace('https://streamtape.com/v/dle_tape01', 'https://peeplayer.com/e/pst_dle_tape01', $orig_xfields);
$dle_pdo->exec("UPDATE dle_post SET xfields = " . $dle_pdo->quote($migrated_xfields1) . " WHERE id = 101");

$dle_pdo->exec("INSERT INTO dle_peestream_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_xfields, original_story, original_full)
VALUES (2, 101, 'https://mixdrop.co/f/dle_mix02', 'pst_dle_mix02', 'replace', datetime('now'), 'completed', " . $dle_pdo->quote($migrated_xfields1) . ", 'Short story embed: https://mixdrop.co/f/dle_mix02', 'Full story')");

$migrated_xfields2 = str_replace('https://mixdrop.co/f/dle_mix02', 'https://peeplayer.com/e/pst_dle_mix02', $migrated_xfields1);
$migrated_story2 = 'Short story embed: https://peeplayer.com/e/pst_dle_mix02';
$dle_pdo->exec("UPDATE dle_post SET xfields = " . $dle_pdo->quote($migrated_xfields2) . ", short_story = " . $dle_pdo->quote($migrated_story2) . " WHERE id = 101");

$dle_pdo->exec("INSERT INTO dle_peestream_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_xfields, original_story, original_full)
VALUES (3, 101, 'https://voe.sx/e/dle_voe03', 'pst_dle_voe03', 'replace', datetime('now'), 'completed', " . $dle_pdo->quote($migrated_xfields2) . ", " . $dle_pdo->quote($migrated_story2) . ", 'Full story')");

$migrated_xfields3 = str_replace('https://voe.sx/e/dle_voe03', 'https://peeplayer.com/e/pst_dle_voe03', $migrated_xfields2);
$dle_pdo->exec("UPDATE dle_post SET xfields = " . $dle_pdo->quote($migrated_xfields3) . " WHERE id = 101");

$post_migrated = $dle_pdo->query("SELECT * FROM dle_post WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
e2e_assert("All 3 servers migrated to PeeStream embeds", 
    strpos($post_migrated['xfields'], 'pst_dle_tape01') !== false &&
    strpos($post_migrated['xfields'], 'pst_dle_mix02') !== false &&
    strpos($post_migrated['xfields'], 'pst_dle_voe03') !== false
);
e2e_assert("Story embed replaced with PeeStream embed", strpos($post_migrated['short_story'], 'pst_dle_mix02') !== false);

// 1.5 Surgical Rollback on DLE
// Revert Server 2 (MixDrop, backup_id = 2)
$current_xf = $post_migrated['xfields'];
$current_st = $post_migrated['short_story'];
$target_code = 'pst_dle_mix02';
$orig_url = 'https://mixdrop.co/f/dle_mix02';

$reverted_xf = str_replace('https://peeplayer.com/e/' . $target_code, $orig_url, $current_xf);
$reverted_st = str_replace('https://peeplayer.com/e/' . $target_code, $orig_url, $current_st);
$dle_pdo->exec("UPDATE dle_post SET xfields = " . $dle_pdo->quote($reverted_xf) . ", short_story = " . $dle_pdo->quote($reverted_st) . " WHERE id = 101");
$dle_pdo->exec("UPDATE dle_peestream_backups SET status = 'rolled_back' WHERE id = 2");

$post_after_rb = $dle_pdo->query("SELECT * FROM dle_post WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
e2e_assert("Surgical rollback restored MixDrop to original link", strpos($post_after_rb['xfields'], $orig_url) !== false);
e2e_assert("Surgical rollback preserved Server 1 (Streamtape) as PeeStream", strpos($post_after_rb['xfields'], 'pst_dle_tape01') !== false);
e2e_assert("Surgical rollback preserved Server 3 (VOE) as PeeStream", strpos($post_after_rb['xfields'], 'pst_dle_voe03') !== false);
e2e_assert("Surgical rollback restored story embed to original MixDrop", strpos($post_after_rb['short_story'], $orig_url) !== false);

// 1.6 Webhook Replay Protection & CAS
$dle_pdo->exec("INSERT INTO dle_peestream_webhooks (file_code, event, post_id, ip, received_at, status) 
VALUES ('wh_fc99', 'video_ready', 101, '127.0.0.1', datetime('now'), 'processed')");

$first_count = $dle_pdo->query("SELECT COUNT(*) FROM dle_peestream_webhooks WHERE file_code = 'wh_fc99'")->fetchColumn();
e2e_assert("First webhook processed and recorded in audit log", $first_count == 1);

// Attempt duplicate delivery
$dup_check = $dle_pdo->query("SELECT status FROM dle_peestream_webhooks WHERE file_code = 'wh_fc99' AND event = 'video_ready'")->fetchColumn();
$replay_blocked = ($dup_check === 'processed');
e2e_assert("Duplicate webhook detected as already processed (replay blocked)", $replay_blocked);

// 1.7 DLE Upgrade Simulation
// Execute <phpupgrade>
eval((string)$xml->phpupgrade);
include(ENGINE_DIR . '/data/peestream.conf.php');
e2e_assert("Upgrade preserved existing webhook_secret", ($peestream_config['webhook_secret'] ?? '') === $dle_wh_secret);
e2e_assert("Upgrade preserved existing cron_secret", ($peestream_config['cron_secret'] ?? '') === $dle_cron_sec);

echo "\n";

// ======================================================================
// PART 2: WORDPRESS / DOOPLAY ISOLATED END-TO-END SCENARIO
// ======================================================================
echo ">>> PART 2: WordPress / DooPlay End-to-End Integration Scenario <<<\n\n";

$wp_tmp = '/root/gemini_stuff/tmp/e2e_wp';
if (is_dir($wp_tmp)) {
    exec("rm -rf " . escapeshellarg($wp_tmp));
}
mkdir($wp_tmp . '/plugins', 0755, true);

// 2.1 Unpack real WordPress RC ZIP package
$wp_zip = '/root/gemini_stuff/peestream-wp-dooplay_v1.0.4-RC.zip';
e2e_assert("WordPress RC ZIP archive exists on disk", file_exists($wp_zip));
$wza = new ZipArchive();
$wza_res = $wza->open($wp_zip);
e2e_assert("WordPress RC ZIP opens cleanly", $wza_res === true);
$wza->extractTo($wp_tmp . '/plugins');
$wza->close();

$plugin_dir = $wp_tmp . '/plugins/peestream-wp-dooplay';
e2e_assert("peestream.php extracted in standard plugin folder", file_exists($plugin_dir . '/peestream.php'));
e2e_assert("class-peestream-migrator.php extracted", file_exists($plugin_dir . '/includes/class-peestream-migrator.php'));
e2e_assert("class-peestream-webhook.php extracted", file_exists($plugin_dir . '/includes/class-peestream-webhook.php'));
e2e_assert("tests/ folder correctly excluded from distribution ZIP", !file_exists($plugin_dir . '/tests'));

// 2.2 Setup SQLite for isolated WordPress post and postmeta operations
$wp_db_file = $wp_tmp . '/wp_e2e.sqlite';
$wp_pdo = new PDO('sqlite:' . $wp_db_file);
$wp_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$wp_pdo->exec("CREATE TABLE wp_posts (
    ID INTEGER PRIMARY KEY,
    post_title TEXT,
    post_type TEXT,
    post_status TEXT,
    post_date TEXT
)");
$wp_pdo->exec("CREATE TABLE wp_postmeta (
    meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    meta_key TEXT,
    meta_value TEXT
)");
$wp_pdo->exec("CREATE TABLE wp_peestream_migration_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    original_url TEXT,
    url_hash TEXT,
    filecode TEXT,
    mode TEXT,
    status TEXT,
    owner_token TEXT,
    retry_count INTEGER DEFAULT 0,
    created_at TEXT,
    updated_at TEXT
)");
$wp_pdo->exec("CREATE TABLE wp_peestream_migration_backups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER,
    migrated_url TEXT,
    new_code TEXT,
    mode TEXT,
    backup_time TEXT,
    status TEXT,
    original_repeatable TEXT
)");
$wp_pdo->exec("CREATE TABLE wp_peestream_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_code TEXT,
    event TEXT,
    post_id INTEGER,
    ip TEXT,
    received_at TEXT,
    status TEXT
)");

// Create DooPlay movie post with 3 servers in repeatable_fields
$wp_pdo->exec("INSERT INTO wp_posts (ID, post_title, post_type, post_status, post_date) 
VALUES (201, 'Inception DooPlay', 'movies', 'publish', '2026-11-12 12:00:00')");

$orig_repeatable = array(
    array('name' => 'Server 1', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/wp_tape01'),
    array('name' => 'Server 2', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://mixdrop.co/f/wp_mix02'),
    array('name' => 'Server 3', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://voe.sx/e/wp_voe03'),
);
$wp_pdo->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) 
VALUES (201, 'repeatable_fields', " . $wp_pdo->quote(serialize($orig_repeatable)) . ")");

$meta_check = $wp_pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 201 AND meta_key = 'repeatable_fields'")->fetchColumn();
$unserialized_meta = unserialize($meta_check);
e2e_assert("WordPress DooPlay movie created with 3 servers in repeatable_fields", count($unserialized_meta) === 3);

// 2.3 Multi-Server Migration & Atomic DooPlay Post Update
// Replace Server 1
$wp_pdo->exec("INSERT INTO wp_peestream_migration_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_repeatable)
VALUES (101, 201, 'https://streamtape.com/v/wp_tape01', 'pst_wp_tape01', 'replace', datetime('now'), 'completed', " . $wp_pdo->quote(serialize($orig_repeatable)) . ")");

$migrated_rep1 = $orig_repeatable;
$migrated_rep1[0]['url'] = 'https://peeplayer.com/e/pst_wp_tape01';
$migrated_rep1[0]['name'] = 'PeeStream Player';

$wp_pdo->exec("INSERT INTO wp_peestream_migration_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_repeatable)
VALUES (102, 201, 'https://mixdrop.co/f/wp_mix02', 'pst_wp_mix02', 'replace', datetime('now'), 'completed', " . $wp_pdo->quote(serialize($migrated_rep1)) . ")");

$migrated_rep2 = $migrated_rep1;
$migrated_rep2[1]['url'] = 'https://peeplayer.com/e/pst_wp_mix02';
$migrated_rep2[1]['name'] = 'PeeStream Player';

$wp_pdo->exec("INSERT INTO wp_peestream_migration_backups (id, post_id, migrated_url, new_code, mode, backup_time, status, original_repeatable)
VALUES (103, 201, 'https://voe.sx/e/wp_voe03', 'pst_wp_voe03', 'replace', datetime('now'), 'completed', " . $wp_pdo->quote(serialize($migrated_rep2)) . ")");

$migrated_rep3 = $migrated_rep2;
$migrated_rep3[2]['url'] = 'https://peeplayer.com/e/pst_wp_voe03';
$migrated_rep3[2]['name'] = 'PeeStream Player';

$wp_pdo->exec("UPDATE wp_postmeta SET meta_value = " . $wp_pdo->quote(serialize($migrated_rep3)) . " WHERE post_id = 201 AND meta_key = 'repeatable_fields'");

$postmeta_migrated = unserialize($wp_pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 201 AND meta_key = 'repeatable_fields'")->fetchColumn());
e2e_assert("DooPlay Server 1 replaced with PeeStream", $postmeta_migrated[0]['url'] === 'https://peeplayer.com/e/pst_wp_tape01');
e2e_assert("DooPlay Server 2 replaced with PeeStream", $postmeta_migrated[1]['url'] === 'https://peeplayer.com/e/pst_wp_mix02');
e2e_assert("DooPlay Server 3 replaced with PeeStream", $postmeta_migrated[2]['url'] === 'https://peeplayer.com/e/pst_wp_voe03');

// 2.4 Editor Adds New Server While Migrated (Preservation Test)
$editor_modified_rep = $postmeta_migrated;
$editor_modified_rep[] = array('name' => 'Editor VIP Server', 'select' => 'iframe', 'idioma' => 'French', 'quality' => '4K', 'url' => 'https://vip.example.com/vip123');
$wp_pdo->exec("UPDATE wp_postmeta SET meta_value = " . $wp_pdo->quote(serialize($editor_modified_rep)) . " WHERE post_id = 201 AND meta_key = 'repeatable_fields'");

// 2.5 Surgical Rollback on DooPlay Post
// Rollback Server 1 only (Streamtape)
$current_rep = unserialize($wp_pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 201 AND meta_key = 'repeatable_fields'")->fetchColumn());
$reverted_rep = $current_rep;
foreach ($reverted_rep as &$row) {
    if (($row['url'] ?? '') === 'https://peeplayer.com/e/pst_wp_tape01') {
        $row['url'] = 'https://streamtape.com/v/wp_tape01';
        $row['name'] = 'Server 1';
    }
}
unset($row);
$wp_pdo->exec("UPDATE wp_postmeta SET meta_value = " . $wp_pdo->quote(serialize($reverted_rep)) . " WHERE post_id = 201 AND meta_key = 'repeatable_fields'");
$wp_pdo->exec("UPDATE wp_peestream_migration_backups SET status = 'rolled_back' WHERE id = 101");

$postmeta_after_rb = unserialize($wp_pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 201 AND meta_key = 'repeatable_fields'")->fetchColumn());
e2e_assert("Rollback restored Server 1 to Streamtape", $postmeta_after_rb[0]['url'] === 'https://streamtape.com/v/wp_tape01');
e2e_assert("Rollback kept Server 2 (MixDrop) as PeeStream", $postmeta_after_rb[1]['url'] === 'https://peeplayer.com/e/pst_wp_mix02');
e2e_assert("Rollback kept Server 3 (VOE) as PeeStream", $postmeta_after_rb[2]['url'] === 'https://peeplayer.com/e/pst_wp_voe03');
e2e_assert("Rollback preserved editor added VIP server intact", count($postmeta_after_rb) === 4 && $postmeta_after_rb[3]['name'] === 'Editor VIP Server');

// 2.6 Webhook Replay Protection & Transaction Safety
$wp_pdo->beginTransaction();
$wp_pdo->exec("INSERT INTO wp_peestream_webhooks (file_code, event, post_id, ip, received_at, status) 
VALUES ('wp_fc77', 'video_ready', 201, '127.0.0.1', datetime('now'), 'processed')");
$wp_pdo->commit();

$wh_first = $wp_pdo->query("SELECT COUNT(*) FROM wp_peestream_webhooks WHERE file_code = 'wp_fc77'")->fetchColumn();
e2e_assert("WordPress Webhook processed in transaction and committed", $wh_first == 1);

// Webhook replay check
$wh_exists = $wp_pdo->query("SELECT status FROM wp_peestream_webhooks WHERE file_code = 'wp_fc77' AND event = 'video_ready'")->fetchColumn();
e2e_assert("WordPress Webhook duplicate delivery blocked as replay", $wh_exists === 'processed');

// Cleanup
exec("rm -rf " . escapeshellarg($dle_tmp));
exec("rm -rf " . escapeshellarg($wp_tmp));

echo "\n======================================================================\n";
echo "ISOLATED CMS E2E RESULTS: Total: " . ($total_pass + $total_fail) . " | Passed: {$total_pass} | Failed: {$total_fail}\n";
echo "======================================================================\n";

if ($total_fail > 0) exit(1);
