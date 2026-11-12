<?php
/**
 * Comprehensive Automated Test Suite for PeeStream WordPress & DooPlay Addon
 * File: test_wp_suite.php
 */

$plugin_dir = file_exists(__DIR__ . '/../peestream.php') ? realpath(__DIR__ . '/..') : (file_exists(__DIR__ . '/peestream-wp-dooplay/peestream.php') ? realpath(__DIR__ . '/peestream-wp-dooplay') : '/root/gemini_stuff/peestream-wp-dooplay');
define( 'WP_PLUGIN_DIR_PATH', rtrim($plugin_dir, '/') . '/' );
define( 'ABSPATH', WP_PLUGIN_DIR_PATH );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

class WP_REST_Server {
	const CREATABLE = 'POST';
	const READABLE  = 'GET';
}

// Mock WordPress environment
$mock_options = array(
	'peestream_settings' => array(
		'api_key'             => 'test_api_key_1234567890abcdef',
		'player_domain'       => 'https://peeplayer.com',
		'api_domain'          => 'https://peestream.com',
		'default_folder_id'   => '0',
		'default_server_name' => 'PeeStream Player',
		'default_language'    => 'English',
		'default_quality'     => '1080p',
		'webhook_secret'      => 'test_secret_long_token_123456',
		'auto_create_posts'   => '0',
		'default_post_type'   => 'movies',
	),
);

$mock_postmeta = array();
$mock_posts    = array();
$mock_db_tables = array(
	'wp_peestream_webhooks' => array(),
	'wp_peestream_migration_queue' => array(),
	'wp_peestream_migration_backups' => array(),
	'wp_peestream_migration_queue_archive' => array(),
);

class MockWPDB {
	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $options  = 'wp_options';
	public $insert_id = 1;
	public $in_transaction = false;
	public $transaction_snapshot = null;

	public function esc_like( $text ) {
		return addcslashes( (string)$text, '_%\\' );
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function get_var( $query ) {
		global $mock_postmeta, $mock_posts, $mock_db_tables;
		if ( strpos( $query, "SHOW TABLES LIKE" ) !== false ) {
			if ( preg_match( "#'([^']+)'#", $query, $m ) ) {
				return $m[1];
			}
		}
		if ( strpos( $query, "SELECT status FROM wp_peestream_migration_backups WHERE id =" ) !== false ) {
			if ( preg_match( "#id = (\d+)#", $query, $m ) ) {
				$bid = (int) $m[1];
				global $sim_fail_backup_status;
				if ( ! empty( $sim_fail_backup_status ) ) {
					return 'in_progress';
				}
				foreach ( $mock_db_tables['wp_peestream_migration_backups'] as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $bid ) {
						return $row['status'] ?? 'completed';
					}
				}
			}
			return 'completed';
		}
		if ( strpos( $query, "SELECT status FROM wp_peestream_webhooks WHERE file_code =" ) !== false ) {
			if ( preg_match( "#file_code = '([^']+)' AND event = '([^']+)'#", $query, $m ) ) {
				$fc = $m[1];
				$ev = $m[2];
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as $row ) {
					if ( ( $row['file_code'] ?? '' ) === $fc && ( $row['event'] ?? '' ) === $ev ) {
						return $row['status'] ?? 'processed';
					}
				}
			}
			return 'processed';
		}
		if ( strpos( $query, "SELECT post_id FROM wp_peestream_webhooks WHERE file_code =" ) !== false ) {
			if ( preg_match( "#file_code = '([^']+)' AND event = '([^']+)'#", $query, $m ) ) {
				$fc = $m[1];
				$ev = $m[2];
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as $row ) {
					if ( ( $row['file_code'] ?? '' ) === $fc && ( $row['event'] ?? '' ) === $ev && ! empty( $row['post_id'] ) ) {
						return (int) $row['post_id'];
					}
				}
			}
			return 0;
		}
		if ( strpos( $query, "_peestream_webhook_filecode" ) !== false ) {
			if ( preg_match( "#pm\.meta_value = '([^']+)'#", $query, $m ) ) {
				$fc = $m[1];
				foreach ( $mock_postmeta as $pid => $pm ) {
					if ( ( $pm['_peestream_webhook_filecode'] ?? '' ) === $fc ) {
						return (int) $pid;
					}
				}
			}
			return 0;
		}
		if ( strpos( $query, "COUNT(id) FROM wp_peestream_migration_queue WHERE status = 'completed'" ) !== false ) {
			$cnt = 0;
			foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
				if ( isset( $row['status'] ) && 'completed' === $row['status'] ) $cnt++;
			}
			return $cnt;
		}
		if ( strpos( $query, "COUNT(id) FROM wp_peestream_migration_queue" ) !== false ) {
			$cnt = 0;
			foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
				if ( isset( $row['status'] ) && in_array( $row['status'], array('queued','pending','checking'), true ) ) $cnt++;
			}
			return $cnt;
		}
		if ( strpos( $query, "COUNT(id) FROM wp_peestream_migration_backups WHERE status = 'completed'" ) !== false ) {
			$cnt = 0;
			foreach ( $mock_db_tables['wp_peestream_migration_backups'] as $row ) {
				if ( isset( $row['status'] ) && 'completed' === $row['status'] ) $cnt++;
			}
			return $cnt;
		}
		if ( strpos( $query, "wp_peestream_webhooks" ) !== false && ( strpos( $query, "COUNT(" ) !== false ) ) {
			if ( preg_match( "#ip = '([^']+)'#", $query, $m ) ) {
				$ip = $m[1];
				$cnt = 0;
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as $row ) {
					if ( isset( $row['ip'] ) && $row['ip'] === $ip ) $cnt++;
				}
				return $cnt;
			}
			if ( preg_match( "#file_code = '([^']+)'#", $query, $m ) ) {
				$fc = $m[1];
				$cnt = 0;
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as $row ) {
					if ( isset( $row['file_code'] ) && $row['file_code'] === $fc ) $cnt++;
				}
				return $cnt;
			}
		}
		if ( strpos( $query, "COUNT(post_id) FROM wp_postmeta WHERE meta_key = 'repeatable_fields'" ) !== false ) {
			$cnt = 0;
			foreach ( $mock_postmeta as $pid => $meta ) {
				if ( isset( $meta['repeatable_fields'] ) && ! empty( $meta['repeatable_fields'] ) ) {
					$cnt++;
				}
			}
			return $cnt;
		}
		// Match parent TV show by IMDb
		if ( strpos( $query, "post_type = 'tvshows'" ) !== false ) {
			if ( preg_match( "#pm\.meta_value = '([^']+)'#", $query, $m ) ) {
				$imdb_id = $m[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( ( $p['post_type'] ?? '' ) === 'tvshows' ) {
						if ( ( $mock_postmeta[ $pid ]['imdb_id'] ?? '' ) === $imdb_id || ( $mock_postmeta[ $pid ]['ids'] ?? '' ) === $imdb_id ) {
							return $pid;
						}
					}
				}
			}
		}
		// Match episode within TV show
		if ( strpos( $query, "post_type = 'episodes'" ) !== false ) {
			if ( preg_match( "#pm_s\.meta_value = (\d+)#", $query, $ms ) && preg_match( "#pm_e\.meta_value = (\d+)#", $query, $me ) ) {
				$target_season = (string) $ms[1];
				$target_ep     = (string) $me[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( ( $p['post_type'] ?? '' ) === 'episodes' ) {
						$pm = $mock_postmeta[ $pid ] ?? array();
						if ( ( $pm['temporada'] ?? '' ) === $target_season && ( $pm['episodio'] ?? '' ) === $target_ep ) {
							if ( strpos( $query, 'Breaking Bad' ) !== false && ( $pm['ids'] ?? 0 ) == 301 ) {
								return $pid;
							}
							if ( strpos( $query, 'Better Call Saul' ) !== false && ( $pm['ids'] ?? 0 ) == 302 ) {
								return $pid;
							}
							if ( isset( $pm['ids'] ) && ( $pm['ids'] == 301 || $pm['ids'] == 302 ) ) {
								return $pid;
							}
						}
					}
				}
			}
		}
		return 0;
	}

	public function get_row( $query, $output = OBJECT ) {
		global $mock_db_tables;
		if ( strpos( $query, 'wp_peestream_migration_queue' ) !== false ) {
			$pid = 0;
			$uh  = '';
			$url = '';
			if ( preg_match( '#post_id = (\d+)#', $query, $mpid ) ) {
				$pid = (int) $mpid[1];
			}
			if ( preg_match( "#url_hash = '([^']*)'#", $query, $muh ) ) {
				$uh = $muh[1];
			}
			$is_binary = ( strpos( $query, 'BINARY original_url' ) !== false );
			if ( preg_match( "#(?:BINARY\s+)?original_url = '([^']*)'#", $query, $murl ) ) {
				$url = $murl[1];
			}
			if ( $pid && ( $uh || $url ) ) {
				foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
					if ( (int) ( $row['post_id'] ?? 0 ) === $pid ) {
						$hash_match = ( ! empty( $uh ) && ( $row['url_hash'] ?? '' ) === $uh );
						$url_match  = false;
						if ( ! empty( $url ) ) {
							$row_url = $row['original_url'] ?? '';
							$url_match = $is_binary ? ( $row_url === $url ) : ( strcasecmp( $row_url, $url ) === 0 );
						}
						if ( $hash_match || $url_match ) {
							return ( ARRAY_A === $output ) ? $row : (object) $row;
						}
					}
				}
			}
			if ( preg_match( '#WHERE id = (\d+)#', $query, $m ) ) {
				$id = (int) $m[1];
				foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						return ( ARRAY_A === $output ) ? $row : (object) $row;
					}
				}
			}
		}
		if ( strpos( $query, 'wp_peestream_webhooks' ) !== false ) {
			if ( preg_match( "#file_code = '([^']+)'#", $query, $m ) ) {
				$fc = $m[1];
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as $row ) {
					if ( ( $row['file_code'] ?? '' ) === $fc ) {
						return ( ARRAY_A === $output ) ? $row : (object) $row;
					}
				}
			}
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		global $mock_postmeta, $mock_db_tables;
		if ( strpos( $query, "SHOW INDEX FROM" ) !== false ) {
			global $sim_missing_schema_index;
			if ( preg_match( "#Key_name = '([^']+)'#", $query, $m ) ) {
				$idx_name = $m[1];
				if ( ! empty( $sim_missing_schema_index ) && $sim_missing_schema_index === $idx_name ) {
					return array();
				}
				return array( array( 'Key_name' => $idx_name ) );
			}
			return array();
		}
		if ( strpos( $query, "wp_peestream_migration_backups" ) !== false ) {
			$res = array();
			$status_filter = 'completed';
			if ( strpos( $query, "status = 'in_progress'" ) !== false ) {
				$status_filter = 'in_progress';
			}
			foreach ( $mock_db_tables['wp_peestream_migration_backups'] as $row ) {
				if ( isset( $row['status'] ) && $row['status'] === $status_filter ) {
					$res[] = ( ARRAY_A === $output ) ? $row : (object) $row;
				}
			}
			return $res;
		}
		if ( strpos( $query, "wp_peestream_migration_queue" ) !== false ) {
			$res = array();
			foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
				if ( isset( $row['status'] ) && in_array( $row['status'], array( 'pending', 'queued', 'checking' ), true ) ) {
					$res[] = ( ARRAY_A === $output ) ? $row : (object) $row;
				}
			}
			return $res;
		}
		if ( strpos( $query, "wp_postmeta WHERE meta_key = 'repeatable_fields'" ) !== false ) {
			$res = array();
			foreach ( $mock_postmeta as $pid => $meta ) {
				if ( isset( $meta['repeatable_fields'] ) && ! empty( $meta['repeatable_fields'] ) ) {
					$val = is_array( $meta['repeatable_fields'] ) ? serialize( $meta['repeatable_fields'] ) : $meta['repeatable_fields'];
					$res[] = array(
						'post_id' => $pid,
						'meta_value' => $val,
					);
				}
			}
			return $res;
		}
		return array();
	}

	public function insert( $table, $data ) {
		global $mock_db_tables;
		$clean_table = str_replace( 'wp_', 'wp_', $table );
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		$mock_db_tables[ $clean_table ][] = $data;
		return true;
	}

	public function update( $table, $data, $where ) {
		global $mock_db_tables;
		$clean_table = str_replace( 'wp_', 'wp_', $table );
		if ( isset( $mock_db_tables[ $clean_table ] ) ) {
			foreach ( $mock_db_tables[ $clean_table ] as &$row ) {
				$match = true;
				foreach ( $where as $wk => $wv ) {
					if ( ! isset( $row[ $wk ] ) || $row[ $wk ] != $wv ) {
						$match = false;
						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $dk => $dv ) {
						$row[ $dk ] = $dv;
					}
				}
			}
		}
		return true;
	}

	public function get_col( $query ) {
		global $mock_posts, $mock_postmeta;
		$results = array();

		if ( strpos( $query, "SHOW COLUMNS FROM" ) !== false ) {
			global $sim_missing_schema_column;
			if ( strpos( $query, "wp_peestream_migration_queue_archive" ) !== false ) {
				return array( 'id', 'original_queue_id', 'post_id', 'original_url', 'url_hash', 'filecode', 'mode', 'server_name', 'language', 'quality', 'state', 'retry_count', 'error_msg', 'original_repeatable', 'created_at', 'updated_at', 'archived_at', 'status', 'owner_token' );
			}
			if ( strpos( $query, "wp_peestream_migration_queue" ) !== false ) {
				$cols = array( 'id', 'post_id', 'original_url', 'url_hash', 'filecode', 'mode', 'server_name', 'language', 'quality', 'state', 'status', 'owner_token', 'retry_count', 'error_msg', 'original_repeatable', 'created_at', 'updated_at' );
				if ( ! empty( $sim_missing_schema_column ) ) {
					$cols = array_diff( $cols, array( $sim_missing_schema_column ) );
				}
				return array_values( $cols );
			}
			if ( strpos( $query, "wp_peestream_webhooks" ) !== false ) {
				return array( 'id', 'file_code', 'event', 'post_id', 'ip', 'received_at', 'status' );
			}
			if ( strpos( $query, "wp_peestream_migration_backups" ) !== false ) {
				return array( 'id', 'post_id', 'original_repeatable', 'migrated_url', 'new_code', 'mode', 'backup_time', 'status' );
			}
		}

		if ( strpos( $query, "SELECT DISTINCT q1.id" ) !== false ) {
			global $mock_db_tables;
			$dups = array();
			$seen = array();
			foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
				$key = ( $row['post_id'] ?? 0 ) . ':' . ( ! empty( $row['url_hash'] ) ? $row['url_hash'] : ( $row['original_url'] ?? '' ) );
				if ( isset( $seen[ $key ] ) ) {
					$dups[] = (int) $seen[ $key ];
					$seen[ $key ] = $row['id'] ?? 0;
				} else {
					$seen[ $key ] = $row['id'] ?? 0;
				}
			}
			return array_values( array_unique( $dups ) );
		}

		if ( strpos( $query, "SELECT original_queue_id FROM" ) !== false ) {
			global $mock_db_tables;
			$arch_ids = array();
			foreach ( $mock_db_tables['wp_peestream_migration_queue_archive'] as $arow ) {
				if ( ! empty( $arow['original_queue_id'] ) ) {
					$arch_ids[] = (int) $arow['original_queue_id'];
				}
			}
			return $arch_ids;
		}

		// Parent TV Show matching
		if ( strpos( $query, "post_type = 'tvshows'" ) !== false ) {
			if ( preg_match( "#pm\.meta_value = '([^']+)'#", $query, $m ) ) {
				$val = $m[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( ( $p['post_type'] ?? '' ) === 'tvshows' ) {
						if ( ( $mock_postmeta[ $pid ]['imdb_id'] ?? '' ) === $val || ( $mock_postmeta[ $pid ]['ids'] ?? '' ) === $val ) {
							$results[] = $pid;
						}
					}
				}
			} elseif ( preg_match( "#pm\.meta_value = (\d+)#", $query, $m ) ) {
				$val = (int) $m[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( ( $p['post_type'] ?? '' ) === 'tvshows' ) {
						if ( (int) ( $mock_postmeta[ $pid ]['tmdb_id'] ?? 0 ) === $val || (int) ( $mock_postmeta[ $pid ]['id'] ?? 0 ) === $val ) {
							$results[] = $pid;
						}
					}
				}
			}
			return $results;
		}

		// Episode within show matching
		if ( strpos( $query, "post_type = 'episodes'" ) !== false ) {
			if ( preg_match( "#pm_s\.meta_value = (\d+)#", $query, $ms ) && preg_match( "#pm_e\.meta_value = (\d+)#", $query, $me ) ) {
				$target_season = (string) $ms[1];
				$target_ep     = (string) $me[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( ( $p['post_type'] ?? '' ) === 'episodes' ) {
						$pm = $mock_postmeta[ $pid ] ?? array();
						if ( ( $pm['temporada'] ?? '' ) === $target_season && ( $pm['episodio'] ?? '' ) === $target_ep ) {
							if ( strpos( $query, 'Breaking Bad' ) !== false ) {
								if ( ( $pm['ids'] ?? 0 ) == 301 ) {
									$results[] = $pid;
								}
							} elseif ( strpos( $query, 'Better Call Saul' ) !== false ) {
								if ( ( $pm['ids'] ?? 0 ) == 302 ) {
									$results[] = $pid;
								}
							} elseif ( isset( $pm['ids'] ) ) {
								$results[] = $pid;
							}
						}
					}
				}
			}
			return $results;
		}

		// Movie matching
		if ( strpos( $query, "post_type IN ('movies', 'post')" ) !== false || strpos( $query, "post_type IN ('movies','post')" ) !== false ) {
			if ( preg_match( "#pm\.meta_value = '([^']+)'#", $query, $m ) ) {
				$val = $m[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( in_array( $p['post_type'] ?? '', array( 'movies', 'post' ), true ) ) {
						if ( ( $mock_postmeta[ $pid ]['imdb_id'] ?? '' ) === $val || ( $mock_postmeta[ $pid ]['ids'] ?? '' ) === $val ) {
							$results[] = $pid;
						}
					}
				}
			} elseif ( preg_match( "#pm\.meta_value = (\d+)#", $query, $m ) ) {
				$val = (int) $m[1];
				foreach ( $mock_posts as $pid => $p ) {
					if ( in_array( $p['post_type'] ?? '', array( 'movies', 'post' ), true ) ) {
						if ( (int) ( $mock_postmeta[ $pid ]['tmdb_id'] ?? 0 ) === $val || (int) ( $mock_postmeta[ $pid ]['id'] ?? 0 ) === $val ) {
							$results[] = $pid;
						}
					}
				}
			}
			return $results;
		}

		return $results;
	}

	public function query( $query ) {
		global $mock_db_tables;

		if ( 'START TRANSACTION' === trim( $query ) ) {
			global $sim_fail_start_tx;
			if ( ! empty( $sim_fail_start_tx ) ) {
				return false;
			}
			global $mock_posts, $mock_postmeta, $mock_db_tables;
			$this->in_transaction = true;
			$this->transaction_snapshot = array(
				'posts'     => $mock_posts,
				'postmeta'  => $mock_postmeta,
				'db_tables' => $mock_db_tables,
			);
			return true;
		}
		if ( 'COMMIT' === trim( $query ) ) {
			global $sim_fail_commit_tx;
			if ( ! empty( $sim_fail_commit_tx ) ) {
				return false;
			}
			$this->in_transaction = false;
			$this->transaction_snapshot = null;
			return true;
		}
		if ( 'ROLLBACK' === trim( $query ) ) {
			if ( $this->in_transaction && $this->transaction_snapshot ) {
				global $mock_posts, $mock_postmeta, $mock_db_tables;
				$mock_posts     = $this->transaction_snapshot['posts'];
				$mock_postmeta  = $this->transaction_snapshot['postmeta'];
				$mock_db_tables = $this->transaction_snapshot['db_tables'];
			}
			$this->in_transaction = false;
			$this->transaction_snapshot = null;
			return true;
		}

		if ( strpos( $query, 'wp_peestream_webhooks' ) !== false && strpos( $query, 'INSERT' ) !== false ) {
			if ( preg_match( "#VALUES \('([^']+)', '([^']+)', (\d+), '([^']*)', '([^']+)', '([^']+)'\)#", $query, $m ) ) {
				$fc     = $m[1];
				$ev     = $m[2];
				$pid    = (int) $m[3];
				$ip     = $m[4];
				$rec_at = $m[5];
				$status = $m[6];

				// UNIQUE KEY simulation on (file_code, event)
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as &$row ) {
					if ( ( $row['file_code'] ?? '' ) === $fc && ( $row['event'] ?? '' ) === $ev ) {
						if ( strpos( $query, 'IGNORE' ) !== false ) {
							return 0; // Collided on unique key, 0 rows inserted
						}
						$row['post_id'] = $pid;
						$row['ip'] = $ip;
						$row['received_at'] = $rec_at;
						$row['status'] = $status;
						return 1;
					}
				}
				$mock_db_tables['wp_peestream_webhooks'][] = array(
					'id'          => $this->insert_id++,
					'file_code'   => $fc,
					'event'       => $ev,
					'post_id'     => $pid,
					'ip'          => $ip,
					'received_at' => $rec_at,
					'status'      => $status,
				);
				return 1;
			}
		}
		if ( strpos( $query, 'wp_peestream_migration_queue_archive' ) !== false && strpos( $query, 'INSERT' ) !== false ) {
			global $sim_fail_archive_insert;
			if ( ! empty( $sim_fail_archive_insert ) ) {
				return false;
			}
			return 1;
		}
		if ( strpos( $query, 'wp_peestream_migration_queue' ) !== false && strpos( $query, 'INSERT' ) !== false ) {
			global $sim_fail_queue_insert;
			if ( ! empty( $sim_fail_queue_insert ) ) {
				return false;
			}
			$pid = 0;
			$url = '';
			$url_hash = '';
			if ( preg_match( "#VALUES\s*\(\s*(\d+)\s*,\s*'([^']+)'\s*,\s*'([^']*)'#is", $query, $m ) ) {
				$pid = (int) $m[1];
				$url = $m[2];
				$url_hash = $m[3];
			} elseif ( preg_match( "#VALUES\s*\(\s*(\d+)\s*,\s*'([^']+)'#is", $query, $m ) ) {
				$pid = (int) $m[1];
				$url = $m[2];
			}
			if ( empty( $url_hash ) && ! empty( $url ) ) {
				$url_hash = hash( 'sha256', trim( $url ) );
			}
			// Check UNIQUE KEY collision on (post_id, url_hash)
			if ( $pid && $url_hash ) {
				foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
					if ( (int) ( $row['post_id'] ?? 0 ) === $pid ) {
						if ( ( ! empty( $row['url_hash'] ) && $row['url_hash'] === $url_hash ) || ( ( $row['original_url'] ?? '' ) === $url ) ) {
							return false; // duplicate entry
						}
					}
				}
			}

			$this->insert_id++;
			$new_row = array(
				'id'                  => $this->insert_id,
				'post_id'             => $pid,
				'original_url'        => $url,
				'url_hash'            => $url_hash,
				'filecode'            => '',
				'mode'                => 'primary',
				'server_name'         => 'PeeStream Player',
				'language'            => 'English',
				'quality'             => '1080p',
				'state'               => 'uploading',
				'status'              => 'checking',
				'owner_token'         => '',
				'retry_count'         => 0,
				'error_msg'           => '',
				'original_repeatable' => '',
				'created_at'          => current_time( 'mysql', true ),
				'updated_at'          => current_time( 'mysql', true ),
			);
			if ( preg_match( "#'([^']*)',\s*'([^']*)',\s*'([^']*)',\s*'([^']*)',\s*'uploading'#", $query, $m_opts ) ) {
				$new_row['mode']        = $m_opts[1];
				$new_row['server_name'] = $m_opts[2];
				$new_row['language']    = $m_opts[3];
				$new_row['quality']     = $m_opts[4];
			}
			$mock_db_tables['wp_peestream_migration_queue'][] = $new_row;
			return 1;
		}
		if ( strpos( $query, 'UPDATE wp_peestream_migration_queue' ) !== false ) {
			if ( preg_match( "#SET owner_token = '([^']*)', status = 'checking', state = 'uploading'.*WHERE id = (\d+)#s", $query, $m ) ) {
				$token = $m[1];
				$id    = (int) $m[2];
				foreach ( $mock_db_tables['wp_peestream_migration_queue'] as &$row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						$cur_token = $row['owner_token'] ?? '';
						if ( empty( $cur_token ) || $cur_token === $token ) {
							$row['owner_token'] = $token;
							$row['status'] = 'checking';
							$row['state'] = 'uploading';
							return 1;
						}
						return 0; // locked by another worker!
					}
				}
				return 0;
			}
			if ( preg_match( "#status = 'checking'.*WHERE id = (\d+)#", $query, $m ) ) {
				$id = (int) $m[1];
				foreach ( $mock_db_tables['wp_peestream_migration_queue'] as &$row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						$row['status'] = 'checking';
						return 1;
					}
				}
			}
		}
		if ( strpos( $query, 'UPDATE wp_options' ) !== false ) {
			if ( preg_match( "#option_value = '(.*?)' WHERE option_name = '(.*?)' AND option_value LIKE '(.*?)'#", $query, $m ) ) {
				$new_val = $m[1];
				$opt_name = $m[2];
				$pattern = str_replace( '%', '.*', preg_quote( $m[3], '#' ) );
				global $mock_options;
				if ( isset( $mock_options[ $opt_name ] ) && preg_match( "#^{$pattern}$#", $mock_options[ $opt_name ] ) ) {
					$mock_options[ $opt_name ] = $new_val;
					return 1;
				}
				return 0;
			}
			if ( preg_match( "#option_value = '(.*?)' WHERE option_name = '(.*?)' AND option_value = '(.*?)'#", $query, $m ) ) {
				$new_val = $m[1];
				$opt_name = $m[2];
				$old_val = $m[3];
				global $mock_options;
				if ( isset( $mock_options[ $opt_name ] ) && $mock_options[ $opt_name ] === $old_val ) {
					$mock_options[ $opt_name ] = $new_val;
					return 1;
				}
				return 0;
			}
		}
		if ( strpos( $query, 'DELETE FROM wp_options' ) !== false ) {
			if ( preg_match( "#WHERE option_name = '(.*?)' AND option_value LIKE '(.*?)'#", $query, $m ) ) {
				$opt_name = $m[1];
				$pattern = str_replace( '%', '.*', preg_quote( $m[2], '#' ) );
				global $mock_options;
				if ( isset( $mock_options[ $opt_name ] ) && preg_match( "#^{$pattern}$#", $mock_options[ $opt_name ] ) ) {
					unset( $mock_options[ $opt_name ] );
					return 1;
				}
				return 0;
			}
			if ( preg_match( "#WHERE option_name = '(.*?)'#", $query, $m ) ) {
				$opt_name = $m[1];
				global $mock_options;
				if ( isset( $mock_options[ $opt_name ] ) ) {
					unset( $mock_options[ $opt_name ] );
					return 1;
				}
				return 0;
			}
		}
		if ( strpos( $query, 'UPDATE wp_peestream_webhooks' ) !== false ) {
			if ( preg_match( "#id = (\d+) AND status = '([^']+)' AND received_at = '([^']+)'#", $query, $m ) ) {
				$id = (int) $m[1];
				$expected_status = $m[2];
				$expected_rec_at = $m[3];
				foreach ( $mock_db_tables['wp_peestream_webhooks'] as &$row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						if ( ( $row['status'] ?? '' ) === $expected_status && ( $row['received_at'] ?? '' ) === $expected_rec_at ) {
							$row['status'] = 'processing';
							if ( preg_match( "#ip = '([^']+)'#", $query, $mip ) ) {
								$row['ip'] = $mip[1];
							}
							if ( preg_match( "#received_at = '([^']+)'#", $query, $mrec ) ) {
								$row['received_at'] = $mrec[1];
							}
							return 1;
						}
						return 0;
					}
				}
				return 0;
			}
		}
		if ( strpos( $query, 'wp_peestream_migration_backups' ) !== false && strpos( $query, 'UPDATE' ) !== false ) {
			if ( strpos( $query, "mode = 'unknown'" ) !== false || strpos( $query, "`mode` = 'unknown'" ) !== false ) {
				foreach ( $mock_db_tables['wp_peestream_migration_backups'] as &$row ) {
					if ( empty( $row['mode'] ) ) {
						$row['mode'] = 'unknown';
					}
				}
				return 1;
			}
		}
		return 1;
	}

	public function prepare( $query, ...$args ) {
		if ( is_array( $args[0] ?? null ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? $arg : "'" . addslashes( $arg ) . "'";
			$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 );
		}
		return $query;
	}
}

$wpdb = new MockWPDB();

// Mock WordPress functions
function get_option( $name, $default = false ) {
	global $mock_options;
	return isset( $mock_options[ $name ] ) ? $mock_options[ $name ] : $default;
}
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	global $mock_options;
	if ( isset( $mock_options[ $name ] ) ) {
		return false;
	}
	$mock_options[ $name ] = $value;
	return true;
}
function update_option( $name, $value ) {
	global $mock_options;
	$mock_options[ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	global $mock_options;
	if ( isset( $mock_options[ $name ] ) ) {
		unset( $mock_options[ $name ] );
		return true;
	}
	return false;
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out = array();
	foreach ( $pairs as $name => $default ) {
		if ( array_key_exists( $name, $atts ) ) {
			$out[ $name ] = $atts[ $name ];
		} else {
			$out[ $name ] = $default;
		}
	}
	return $out;
}
$mock_cron_events = array();
function wp_next_scheduled( $hook ) {
	global $mock_cron_events;
	return isset( $mock_cron_events[ $hook ] ) ? $mock_cron_events[ $hook ] : false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	global $mock_cron_events;
	$mock_cron_events[ $hook ] = $timestamp;
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	global $mock_cron_events;
	unset( $mock_cron_events[ $hook ] );
	return true;
}
$mock_transients = array();
function get_transient( $transient ) {
	global $mock_transients;
	return isset( $mock_transients[ $transient ] ) ? $mock_transients[ $transient ] : false;
}
function set_transient( $transient, $value, $expiration = 0 ) {
	global $mock_transients;
	$mock_transients[ $transient ] = $value;
	return true;
}
function delete_transient( $transient ) {
	global $mock_transients;
	unset( $mock_transients[ $transient ] );
	return true;
}
function wp_cache_delete( $key, $group = '' ) {
	return true;
}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_localize_script() {}
function sanitize_html_class( $class, $fallback = '' ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string)$class ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string)$s ); }
function wp_generate_password( $len, $special ) { return substr( md5( uniqid( mt_rand(), true ) ), 0, $len ); }
function sanitize_text_field( $str ) { return strip_tags( trim( (string) $str ) ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_email( $e ) { return filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : ''; }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_SANITIZE_URL ); }
function esc_url( $u ) { return htmlspecialchars( (string) filter_var( $u, FILTER_SANITIZE_URL ), ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string)$s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function absint( $v ) { return abs( intval( $v ) ); }
function wp_parse_url( $u, $component = -1 ) { return parse_url( $u, $component ); }
function current_time( $type ) { return date( 'Y-m-d H:i:s' ); }
function maybe_serialize( $data ) { return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data; }
function maybe_unserialize( $data ) {
	if ( ! is_string( $data ) ) return $data;
	$res = @unserialize( $data );
	return false !== $res ? $res : $data;
}
function get_post_meta( $post_id, $key, $single = false ) {
	global $mock_postmeta;
	if ( isset( $mock_postmeta[ $post_id ][ $key ] ) ) {
		return $mock_postmeta[ $post_id ][ $key ];
	}
	return $single ? '' : array();
}
function update_post_meta( $post_id, $key, $value ) {
	global $mock_postmeta, $sim_fail_meta_update;
	if ( ! empty( $sim_fail_meta_update ) ) {
		return false;
	}
	if ( ! isset( $mock_postmeta[ $post_id ] ) ) {
		$mock_postmeta[ $post_id ] = array();
	}
	$mock_postmeta[ $post_id ][ $key ] = $value;
	return true;
}
function get_the_title( $post_id ) {
	global $mock_posts;
	return isset( $mock_posts[ $post_id ]['post_title'] ) ? $mock_posts[ $post_id ]['post_title'] : "Post #{$post_id}";
}
function get_post( $post_id ) {
	global $mock_posts;
	return isset( $mock_posts[ $post_id ] ) ? (object) $mock_posts[ $post_id ] : null;
}
function wp_insert_post( $args ) {
	global $mock_posts, $sim_wp_insert_post_zero, $sim_wp_insert_post_error;
	if ( ! empty( $sim_wp_insert_post_zero ) ) {
		return 0;
	}
	if ( ! empty( $sim_wp_insert_post_error ) ) {
		return new WP_Error( 'db_error', 'Database write error' );
	}
	static $id_gen = 1000;
	$id = ++$id_gen;
	$mock_posts[ $id ] = array(
		'ID' => $id,
		'post_title' => $args['post_title'] ?? '',
		'post_type' => $args['post_type'] ?? 'post',
		'post_status' => $args['post_status'] ?? 'draft',
		'post_author' => $args['post_author'] ?? 1,
	);
	return $id;
}
function get_users( $args ) {
	return array( (object) array( 'ID' => 42, 'user_login' => 'superadmin' ) );
}
function add_action( $tag, $callback ) {}
function add_filter( $tag, $callback ) {}
function register_setting( $group, $name, $args ) {}
function add_menu_page() {}
function add_submenu_page() {}
function add_meta_box() {}
function add_shortcode() {}
function register_rest_route() {}
function plugin_dir_path( $f ) { return WP_PLUGIN_DIR_PATH; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/peestream-wp-dooplay/'; }
function plugin_basename( $f ) { return 'peestream-wp-dooplay/peestream.php'; }
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function is_admin() { return true; }
function dbDelta( $queries ) { return true; }
function selected( $selected, $current = true, $echo = true ) { return (string) $selected === (string) $current ? 'selected="selected"' : ''; }
function checked( $checked, $current = true, $echo = true ) { return (string) $checked === (string) $current ? 'checked="checked"' : ''; }
function load_plugin_textdomain() {}
function check_ajax_referer() { return true; }
function current_user_can() { return true; }
function wp_create_nonce( $action = -1 ) { return 'mock_nonce_' . $action; }
function wp_verify_nonce( $nonce, $action = -1 ) { return true; }

$last_json_res = null;
function wp_send_json_success( $data = null ) {
	global $last_json_res;
	$last_json_res = array( 'success' => true, 'data' => $data );
	echo json_encode( $last_json_res );
	return $last_json_res;
}
function wp_send_json_error( $data = null ) {
	global $last_json_res;
	$last_json_res = array( 'success' => false, 'data' => $data );
	echo json_encode( $last_json_res );
	return $last_json_res;
}

// Safe mock HTTP responses dispatcher
$mock_http_handler = null;
function wp_safe_remote_get( $url, $args = array() ) {
	global $mock_http_handler;
	if ( is_callable( $mock_http_handler ) ) {
		return call_user_func( $mock_http_handler, $url, $args );
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'mock123' ) ) ),
	);
}
function wp_remote_get( $url, $args = array() ) {
	return wp_safe_remote_get( $url, $args );
}
function wp_remote_retrieve_response_code( $res ) {
	return $res['response']['code'] ?? 200;
}
function wp_remote_retrieve_body( $res ) {
	return $res['body'] ?? '';
}
function is_wp_error( $thing ) {
	return is_a( $thing, 'WP_Error' );
}
class WP_Error {
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) {
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class WP_REST_Request {
	private $params = array();
	private $headers = array();
	private $method = 'POST';
	public function __construct( $params = array(), $method = 'POST', $headers = array() ) {
		$this->params = $params;
		$this->method = $method;
		$this->headers = $headers;
	}
	public function get_method() { return $this->method; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_params() { return $this->params; }
	public function get_json_params() { return $this->params; }
	public function get_body_params() { return $this->params; }
	public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? null; }
}
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data, $status = 200 ) {
		$this->data = $data;
		$this->status = $status;
	}
	public function get_status() { return $this->status; }
	public function get_data() { return $this->data; }
}

// Load plugin files
require_once WP_PLUGIN_DIR_PATH . 'peestream.php';
require_once WP_PLUGIN_DIR_PATH . 'includes/class-peestream-admin.php';
require_once WP_PLUGIN_DIR_PATH . 'includes/class-peestream-metabox.php';
require_once WP_PLUGIN_DIR_PATH . 'includes/class-peestream-migrator.php';
require_once WP_PLUGIN_DIR_PATH . 'includes/class-peestream-webhook.php';
require_once WP_PLUGIN_DIR_PATH . 'includes/class-peestream-shortcode.php';

// Test Runner
$passed = 0;
$failed = 0;

function it( $desc, $assertion ) {
	global $passed, $failed;
	if ( $assertion ) {
		$passed++;
		echo "[PASS] {$desc}\n";
	} else {
		$failed++;
		echo "[FAIL] {$desc}\n";
	}
}

echo "=======================================================\n";
echo "PeeStream WordPress & DooPlay Addon Automated Test Suite\n";
echo "=======================================================\n\n";

// 1. Activation & Table Initialization
PeeStream_Plugin::activate();
it( "Plugin activate initializes peestream_settings default options", get_option( 'peestream_settings' ) !== false );
it( "Default auto_create_posts is 0 (Disabled per P0 security requirement)", get_option( 'peestream_settings' )['auto_create_posts'] === '0' );

// 2. Admin settings sanitizer & HTTPS enforcement
$admin = new PeeStream_Admin();
$clean_settings = $admin->sanitize_settings( array(
	'api_key' => '   new_key_abc123   ',
	'player_domain' => 'http://insecure-domain.com/',
	'api_domain' => 'peestream.com',
	'webhook_secret' => 'short', // should be regenerated to 24 chars
	'auto_create_posts' => '0',
) );
it( "Admin settings trims API key", $clean_settings['api_key'] === 'new_key_abc123' );
it( "Admin settings enforces HTTPS on player_domain", 0 === strpos( $clean_settings['player_domain'], 'https://' ) );
it( "Admin settings enforces HTTPS on api_domain", 0 === strpos( $clean_settings['api_domain'], 'https://' ) );
it( "Admin settings validates webhook secret minimum length (regenerates if < 16 chars)", strlen( $clean_settings['webhook_secret'] ) >= 16 );

// 3. Migrator Hostname Validation & Confirmed Hosters
$migrator = new PeeStream_Migrator();
it( "Streamtape domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://streamtape.com/v/abcd123' ) );
it( "MixDrop domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://mixdrop.co/f/xyz789' ) );
it( "VOE domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://voe.sx/e/voe123' ) );
it( "StreamWish domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://streamwish.to/e/wish123' ) );
it( "EarnVids / VidHide domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://vidhide.com/v/vh123' ) );
it( "PixelDrain domain matches confirmed hoster", false !== $migrator->detect_confirmed_hoster( 'https://pixeldrain.com/u/px123' ) );
it( "PeeStream itself is NEVER matched as competitor", false === $migrator->detect_confirmed_hoster( 'https://peeplayer.com/e/pst123' ) );
it( "Doodstream is excluded from confirmed hosters (fails PeeStream remote upload)", false === $migrator->detect_confirmed_hoster( 'https://doodstream.com/d/dood123' ) );
it( "Filemoon is excluded from confirmed hosters (fails PeeStream remote upload)", false === $migrator->detect_confirmed_hoster( 'https://filemoon.sx/e/fm123' ) );
it( "LuluStream / Vinovo is excluded from confirmed hosters (fails PeeStream remote upload)", false === $migrator->detect_confirmed_hoster( 'https://luluvdo.com/e/lulu123' ) );

// 4. Metabox Duplicate Prevention
$metabox = new PeeStream_Metabox();
$test_post_id = 101;
$mock_posts[ $test_post_id ] = array( 'ID' => $test_post_id, 'post_title' => 'The Matrix' );
$mock_postmeta[ $test_post_id ] = array(
	'repeatable_fields' => array(
		array(
			'name' => 'Server 1',
			'select' => 'iframe',
			'idioma' => 'English',
			'quality' => '1080p',
			'url' => 'https://streamtape.com/v/test_matrix',
		),
	),
);

// Add player first time
$_POST = array(
	'post_id' => $test_post_id,
	'code' => 'matr1x01',
	'name' => 'PeeStream Player',
	'language' => 'English',
	'quality' => '1080p',
	'position' => 'primary',
);
$metabox->ajax_add_player();
it( "Metabox adds new PeeStream player successfully", isset( $last_json_res['success'] ) && $last_json_res['success'] === true );
it( "Post has 2 repeatable_fields after primary add", count( get_post_meta( $test_post_id, 'repeatable_fields', true ) ) === 2 );
it( "PeeStream player was prepended as Server 1", get_post_meta( $test_post_id, 'repeatable_fields', true )[0]['url'] === 'https://peeplayer.com/e/matr1x01' );

// Attempt adding duplicate
$metabox->ajax_add_player();
it( "Metabox blocks duplicate player insertion", isset( $last_json_res['success'] ) && $last_json_res['success'] === false && strpos( $last_json_res['data']['message'], 'already attached' ) !== false );
it( "Post still has exactly 2 repeatable_fields (no duplicate created)", count( get_post_meta( $test_post_id, 'repeatable_fields', true ) ) === 2 );

// 5. Auto-Migrator Safe Polling & Queue Workflow
$mig_post_id = 202;
$mock_posts[ $mig_post_id ] = array( 'ID' => $mig_post_id, 'post_title' => 'Inception' );
$original_servers = array(
	array(
		'name' => 'Streamtape',
		'select' => 'iframe',
		'idioma' => 'English',
		'quality' => '1080p',
		'url' => 'https://streamtape.com/v/inception_st',
	),
	array(
		'name' => 'MixDrop',
		'select' => 'iframe',
		'idioma' => 'English',
		'quality' => '720p',
		'url' => 'https://mixdrop.co/f/inception_md',
	),
);
$mock_postmeta[ $mig_post_id ] = array(
	'repeatable_fields' => $original_servers,
);

// 5a. Dry-Run simulation: Postmeta MUST remain completely untouched!
$_POST = array(
	'post_ids' => array( $mig_post_id ),
	'mode' => 'primary',
	'target_hoster' => 'all',
	'dry_run' => 1,
);
$migrator->ajax_process_batch();
it( "Dry-run reports simulation logs", ! empty( $last_json_res['data']['logs'] ) && strpos( $last_json_res['data']['logs'][0], '[DRY-RUN]' ) !== false );
it( "Dry-run leaves repeatable_fields completely unchanged", get_post_meta( $mig_post_id, 'repeatable_fields', true ) === $original_servers );
it( "Dry-run leaves migration queue empty", empty( $mock_db_tables['wp_peestream_migration_queue'] ) );

// 5b. Remote upload queued, but remote file is still pending (canplay=0): Postmeta MUST NOT be touched!
$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'inc_fc01' ) ) ),
		);
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		// Remote transcoding still processing, canplay=0
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array( 'state' => 'processing', 'canplay' => 0 ) ) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids' => array( $mig_post_id ),
	'mode' => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run' => 0,
);
$migrator->ajax_process_batch();
it( "Migrator records item in persistent queue", ! empty( $mock_db_tables['wp_peestream_migration_queue'] ) );
it( "Migrator item in queue has state 'pending'", $mock_db_tables['wp_peestream_migration_queue'][0]['state'] === 'pending' );
it( "Migrator DOES NOT alter repeatable_fields when canplay=0 (link preserved intact)", get_post_meta( $mig_post_id, 'repeatable_fields', true ) === $original_servers );

// 5c. Remote upload transcode finishes (canplay=1): Real PeeStream API returns result as an array of objects!
$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array(
				'status' => 200,
				'result' => array(
					array( 'filecode' => 'other_fc', 'state' => 'pending', 'canplay' => 0 ),
					array( 'filecode' => 'inc_fc01', 'state' => 'ready', 'canplay' => 1 ),
				),
			) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$migrator->ajax_check_queue();
it( "Queue poller detects ready state (canplay=1) from array result", $last_json_res['data']['ready_count'] === 1 );
it( "Queue poller updates queue item to 'completed'", $mock_db_tables['wp_peestream_migration_queue'][0]['status'] === 'completed' );
it( "Queue poller creates backup before modifying", ! empty( $mock_db_tables['wp_peestream_migration_backups'] ) );

$updated_servers = get_post_meta( $mig_post_id, 'repeatable_fields', true );
it( "Repeatable fields now has PeeStream prepended as Server 1", $updated_servers[0]['url'] === 'https://peeplayer.com/e/inc_fc01' );
it( "Original Streamtape and MixDrop servers are preserved intact in tabs 2 and 3", count( $updated_servers ) === 3 );

// 5d. Re-running migration produces NO duplicate servers (Idempotency)
$_POST = array(
	'post_ids' => array( $mig_post_id ),
	'mode' => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run' => 0,
);
$migrator->ajax_process_batch();
it( "Re-running migration skips already completed queue items", strpos( $last_json_res['data']['logs'][0], '[SKIP]' ) !== false );
it( "Server count remains exactly 3 without duplication", count( get_post_meta( $mig_post_id, 'repeatable_fields', true ) ) === 3 );

// 5e. Surgical Rollback with editorial modifications preserved
// Editor adds an updated server commentary or new server after migration
$editorial_meta = get_post_meta( $mig_post_id, 'repeatable_fields', true );
$editorial_meta[] = array(
	'name'    => 'Editor Added VIP Server',
	'select'  => 'iframe',
	'idioma'  => 'French',
	'quality' => '4K',
	'url'     => 'https://vip-server.com/e/vip123',
);
update_post_meta( $mig_post_id, 'repeatable_fields', $editorial_meta );
it( "Postmeta now has 4 servers before rollback", count( get_post_meta( $mig_post_id, 'repeatable_fields', true ) ) === 4 );

// Perform surgical rollback
$migrator->ajax_rollback();
$rolled_back_servers = get_post_meta( $mig_post_id, 'repeatable_fields', true );
it( "Rollback surgically removes PeeStream primary server", strpos( json_encode( $rolled_back_servers ), 'https://peeplayer.com/e/inc_fc01' ) === false );
it( "Rollback PRESERVED the editor added VIP server", strpos( json_encode( $rolled_back_servers ), 'Editor Added VIP Server' ) !== false );
it( "Backup record is marked 'rolled_back'", $mock_db_tables['wp_peestream_migration_backups'][0]['status'] === 'rolled_back' );

// 5f. Migrator 'replace' mode vs 'primary' mode verification
$replace_post_id = 103;
$mock_posts[ $replace_post_id ] = array( 'ID' => $replace_post_id, 'post_title' => 'Replace Test Movie' );
$mock_postmeta[ $replace_post_id ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Old Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '720p', 'url' => 'https://streamtape.com/v/repl123' ),
		array( 'name' => 'VOE Server', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://voe.sx/e/voe456' ),
	),
);

// Submit with mode 'replace'
$_POST = array(
	'post_ids'      => array( $replace_post_id ),
	'mode'          => 'replace',
	'server_name'   => 'PeeStream Replace Server',
	'language'      => 'Spanish',
	'quality'       => '1080p',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'repl_fc99' ) ) ),
		);
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array(
				'status' => 200,
				'result' => array(
					array( 'filecode' => 'repl_fc99', 'canplay' => 1, 'state' => 'ready' ),
				),
			) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$migrator->ajax_process_batch();
$migrator->ajax_check_queue();
$replace_meta = get_post_meta( $replace_post_id, 'repeatable_fields', true );
it( "Mode 'replace' replaces target server in-place without adding extra servers", count( $replace_meta ) === 2 );
it( "Target server #1 URL is replaced with PeeStream URL", $replace_meta[0]['url'] === 'https://peeplayer.com/e/repl_fc99' );
it( "Target server #1 received user configured server_name", $replace_meta[0]['name'] === 'PeeStream Replace Server' );
it( "Target server #1 received user configured language", $replace_meta[0]['idioma'] === 'Spanish' );
it( "Second VOE server remained completely untouched", $replace_meta[1]['url'] === 'https://voe.sx/e/voe456' );

// 5g. Automated WP-Cron Queue Processor & Bounded Retry
it( "Cron schedule peestream_two_minutes exists", isset( PeeStream_Plugin::add_cron_schedules( array() )['peestream_two_minutes'] ) );

// Add a stuck queue item to test timeout and bounded retry
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 999,
	'post_id'      => 555,
	'original_url' => 'https://streamtape.com/v/stuck123',
	'filecode'     => 'fc_stuck',
	'mode'         => 'replace',
	'server_name'  => 'PeeStream HD',
	'language'     => 'English',
	'quality'      => '1080p',
	'state'        => 'pending',
	'retry_count'  => 20, // max retries reached
	'created_at'   => date( 'Y-m-d H:i:s', time() - 90000 ), // >24h
	'updated_at'   => date( 'Y-m-d H:i:s', time() - 90000 ),
	'status'       => 'pending',
);

PeeStream_Migrator::cron_check_queue();
$stuck_row = null;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $r ) {
	if ( $r['id'] === 999 ) $stuck_row = $r;
}
it( "Cron queue worker moves timed out / max retried items to 'failed' status", $stuck_row['status'] === 'failed' );
it( "Cron queue worker cleans concurrency lock transient upon completion", false === get_transient( 'peestream_queue_cron_lock' ) );

// 6. Hardened REST Webhook Verification
$webhook = new PeeStream_Webhook();

// 6a. Reject non-POST
$get_req = new WP_REST_Request( array(), 'GET' );
$resp_get = $webhook->handle_webhook( $get_req );
it( "Webhook rejects non-POST requests with 405 Method Not Allowed", $resp_get->get_status() === 405 );

// 6b. Reject missing or invalid secret (fail-closed)
$bad_secret_req = new WP_REST_Request( array( 'secret' => 'invalid_secret' ), 'POST' );
$resp_sec = $webhook->handle_webhook( $bad_secret_req );
it( "Webhook rejects invalid secret token with 401 or 403 Unauthorized", $resp_sec->get_status() === 401 || $resp_sec->get_status() === 403 );

// 6c. Reject missing file_code or event
$valid_secret = get_option( 'peestream_settings' )['webhook_secret'];
$missing_param_req = new WP_REST_Request( array( 'secret' => $valid_secret ), 'POST' );
$resp_miss = $webhook->handle_webhook( $missing_param_req );
it( "Webhook rejects missing file_code with 400 Bad Request", $resp_miss->get_status() === 400 );

// 6d. Reject disallowed events
$bad_event_req = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'fc_safe123',
	'event' => 'unsupported_event_type',
), 'POST' );
$resp_ev = $webhook->handle_webhook( $bad_event_req );
it( "Webhook rejects unauthorized event type with 400 Bad Request", $resp_ev->get_status() === 400 );

// 6e. Strict Episode Disambiguation: Two shows having the exact same S01E01!
$showA_id = 301;
$showB_id = 302;
$epA_id   = 401;
$epB_id   = 402;

$mock_posts[ $showA_id ] = array( 'ID' => $showA_id, 'post_title' => 'Breaking Bad', 'post_type' => 'tvshows' );
$mock_postmeta[ $showA_id ] = array( 'imdb_id' => 'tt0903747' );

$mock_posts[ $showB_id ] = array( 'ID' => $showB_id, 'post_title' => 'Better Call Saul', 'post_type' => 'tvshows' );
$mock_postmeta[ $showB_id ] = array( 'imdb_id' => 'tt3032476' );

// Ep A: Breaking Bad S01E01
$mock_posts[ $epA_id ] = array( 'ID' => $epA_id, 'post_title' => 'Breaking Bad 1x1', 'post_type' => 'episodes' );
$mock_postmeta[ $epA_id ] = array(
	'ids' => $showA_id,
	'temporada' => '1',
	'episodio' => '1',
	'repeatable_fields' => array(),
);

// Ep B: Better Call Saul S01E01
$mock_posts[ $epB_id ] = array( 'ID' => $epB_id, 'post_title' => 'Better Call Saul 1x1', 'post_type' => 'episodes' );
$mock_postmeta[ $epB_id ] = array(
	'ids' => $showB_id,
	'temporada' => '1',
	'episodio' => '1',
	'repeatable_fields' => array(),
);

// Send webhook targeted strictly to Breaking Bad S01E01 using IMDb ID tt0903747
$wh_payload = array(
	'secret' => $valid_secret,
	'file_code' => 'bb_s01e01_fc',
	'event' => 'new_episode_ready',
	'imdb_id' => 'tt0903747',
	'season' => '1',
	'episode' => '1',
	'series_title' => 'Breaking Bad',
);
$wh_req = new WP_REST_Request( $wh_payload, 'POST' );
$wh_res = $webhook->handle_webhook( $wh_req );

it( "Webhook processes valid episode ready payload with 200 OK", $wh_res->get_status() === 200 );
it( "Webhook attached player specifically to Breaking Bad S01E01 (ep #401)", ! empty( get_post_meta( $epA_id, 'repeatable_fields', true ) ) );
it( "Webhook DID NOT touch Better Call Saul S01E01 (ep #402, zero cross-contamination)", empty( get_post_meta( $epB_id, 'repeatable_fields', true ) ) );

// 6f. Replay Attack Protection: Sending the exact same payload a second time MUST be rejected / ignored
$wh_replay_res = $webhook->handle_webhook( $wh_req );
it( "Webhook handles replayed request idempotently without duplicating", ( $wh_replay_res->get_status() === 200 && ( $wh_replay_res->get_data()['action'] ?? '' ) === 'replay_ignored' ) || $wh_replay_res->get_status() === 409 );

// 6g. Ambiguous Matching Rejection (Two movies sharing duplicate IMDb/TMDb)
$dup_m1 = 801;
$dup_m2 = 802;
$mock_posts[ $dup_m1 ] = array( 'ID' => $dup_m1, 'post_title' => 'Duplicate Movie 1', 'post_type' => 'movies' );
$mock_postmeta[ $dup_m1 ] = array( 'imdb_id' => 'tt9999999', 'repeatable_fields' => array() );

$mock_posts[ $dup_m2 ] = array( 'ID' => $dup_m2, 'post_title' => 'Duplicate Movie 2', 'post_type' => 'movies' );
$mock_postmeta[ $dup_m2 ] = array( 'imdb_id' => 'tt9999999', 'repeatable_fields' => array() );

$ambig_wh = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'dup_fc_test',
	'event' => 'video_ready',
	'imdb_id' => 'tt9999999',
	'title' => 'Some Duplicate Movie',
), 'POST' );
$ambig_res = $webhook->handle_webhook( $ambig_wh );
it( "Webhook rejects ambiguous movies sharing duplicate IMDb ID with 409 Conflict", $ambig_res->get_status() === 409 );
it( "Ambiguous movie #1 remains untouched", empty( get_post_meta( $dup_m1, 'repeatable_fields', true ) ) );
it( "Ambiguous movie #2 remains untouched", empty( get_post_meta( $dup_m2, 'repeatable_fields', true ) ) );

// 6h. Rate Limiting Protection (simulating 60+ calls from same IP)
$_SERVER['REMOTE_ADDR'] = '192.168.1.100';
for ( $i = 0; $i < 65; $i++ ) {
	$mock_db_tables['wp_peestream_webhooks'][] = array(
		'file_code' => 'flood_' . $i,
		'ip' => '192.168.1.100',
		'received_at' => current_time( 'mysql' ),
		'status' => 'processed',
	);
}
$rate_req = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'flood_blocked',
	'event' => 'video_ready',
), 'POST' );
$rate_res = $webhook->handle_webhook( $rate_req );
it( "Webhook enforces 60 req/min rate limit with 429 Too Many Requests", $rate_res->get_status() === 429 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// 7. Universal Shortcode Output
$shortcode = new PeeStream_Shortcode();
$sc_output = $shortcode->render_shortcode( array(
	'id' => 'xyzEmbed99',
	'responsive' => 'true',
	'autoplay' => 'false',
) );
it( "Shortcode renders responsive player container", strpos( $sc_output, 'peestream-player-container' ) !== false );
it( "Shortcode uses HTTPS peeplayer.com embed domain", strpos( $sc_output, 'https://peeplayer.com/e/xyzEmbed99' ) !== false );
it( "Shortcode sets allowfullscreen and sandbox properties", strpos( $sc_output, 'allowfullscreen' ) !== false );

// 8. License & dbDelta SQL compliance check
$main_php = file_get_contents( ABSPATH . 'peestream.php' );
it( "peestream.php does NOT contain CREATE TABLE IF NOT EXISTS (strict dbDelta rule)", strpos( $main_php, 'CREATE TABLE IF NOT EXISTS' ) === false );
it( "peestream.php uses PRIMARY KEY  (id) with two spaces", strpos( $main_php, 'PRIMARY KEY  (id)' ) !== false );
it( "peestream.php webhooks table defines UNIQUE KEY uq_fc_event", strpos( $main_php, 'UNIQUE KEY uq_fc_event (file_code, event)' ) !== false );
it( "peestream.php queue table defines mode, server_name, language, quality columns", strpos( $main_php, 'mode varchar(20)' ) !== false && strpos( $main_php, 'server_name varchar(100)' ) !== false );
it( "peestream.php backups table defines mode column", strpos( $main_php, 'mode varchar(20)' ) !== false );

$lic_content = file_get_contents( ABSPATH . 'LICENSE' );
it( "LICENSE file contains full GNU General Public License v2 text (> 10,000 bytes)", strlen( $lic_content ) > 10000 && strpos( $lic_content, 'GNU GENERAL PUBLIC LICENSE' ) !== false );

// =======================================================
// 9. Hardening Suite (Final WJunction & Release Blockers)
// =======================================================

// 9a. Strict URL match with two servers of the same hoster in one post
$p_multi_voe = 901;
$mock_posts[ $p_multi_voe ] = array( 'ID' => $p_multi_voe, 'post_title' => 'Multi VOE Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_multi_voe ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'VOE Server 1', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://voe.sx/video1' ),
		array( 'name' => 'VOE Server 2', 'select' => 'iframe', 'idioma' => 'Spanish', 'quality' => '720p', 'url' => 'https://voe.sx/video2' ),
	),
);

$q_multi_id = $wpdb->insert_id++;
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id' => $q_multi_id,
	'post_id' => $p_multi_voe,
	'original_url' => 'https://voe.sx/video1',
	'filecode' => 'fc_voe_1',
	'mode' => 'replace',
	'server_name' => 'PeeStream Player',
	'language' => 'English',
	'quality' => '1080p',
	'state' => 'pending',
	'retry_count' => 0,
	'error_msg' => '',
	'original_repeatable' => serialize( $mock_postmeta[ $p_multi_voe ]['repeatable_fields'] ),
	'created_at' => current_time( 'mysql' ),
	'updated_at' => current_time( 'mysql' ),
	'status' => 'pending',
);

$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, 'fc_voe_1' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array( array( 'filecode' => 'fc_voe_1', 'canplay' => 1 ) ) ) ),
		);
	}
	if ( strpos( $url, 'fc_st_deleted' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array( array( 'filecode' => 'fc_st_deleted', 'canplay' => 1 ) ) ) ),
		);
	}
	if ( strpos( $url, 'fc_missing_item' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array( 'status' => 200, 'result' => array(
				array( 'filecode' => 'fc_other_item_1', 'canplay' => 1 ),
				array( 'filecode' => 'fc_other_item_2', 'canplay' => 0 ),
			) ) ),
		);
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'mock_default' ) ) ),
	);
};

$migrator->process_queue_batch( 10 );
$multi_fields = get_post_meta( $p_multi_voe, 'repeatable_fields', true );
it( "Replace mode strictly replaced only Server 1 (voe.sx/video1)", strpos( $multi_fields[0]['url'], 'fc_voe_1' ) !== false );
it( "Second VOE server (voe.sx/video2) remained completely untouched in slot 2", ( $multi_fields[1]['url'] ?? '' ) === 'https://voe.sx/video2' );

// 9b. Conflict detection when server was edited or deleted while in queue
$p_conflict = 902;
$mock_posts[ $p_conflict ] = array( 'ID' => $p_conflict, 'post_title' => 'Conflict Movie', 'post_type' => 'movies' );
// Editor removed the server that was queued!
$mock_postmeta[ $p_conflict ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Other Server', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://other.com/v/123' ),
	),
);

$q_conflict_id = $wpdb->insert_id++;
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id' => $q_conflict_id,
	'post_id' => $p_conflict,
	'original_url' => 'https://streamtape.com/v/deleted123',
	'filecode' => 'fc_st_deleted',
	'mode' => 'replace',
	'server_name' => 'PeeStream Player',
	'language' => 'English',
	'quality' => '1080p',
	'state' => 'pending',
	'retry_count' => 0,
	'error_msg' => '',
	'created_at' => current_time( 'mysql' ),
	'updated_at' => current_time( 'mysql' ),
	'status' => 'pending',
);

$migrator->process_queue_batch( 10 );
$conflict_q = null;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $crow ) {
	if ( (int)$crow['id'] === $q_conflict_id ) {
		$conflict_q = $crow;
		break;
	}
}
it( "Queue processor flags conflict status when target server was deleted in interim", ( $conflict_q['status'] ?? '' ) === 'conflict' );
$conflict_fields = get_post_meta( $p_conflict, 'repeatable_fields', true );
it( "Conflict post was NOT modified and no extra servers were appended", count( $conflict_fields ) === 1 && $conflict_fields[0]['url'] === 'https://other.com/v/123' );

// 9c. Surgical Rollback distinguishing primary vs replace
// 1. Primary rollback
$p_roll_pri = 903;
$mock_posts[ $p_roll_pri ] = array( 'ID' => $p_roll_pri, 'post_title' => 'Rollback Primary Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_roll_pri ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream HD', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://peeplayer.com/e/fc_pri_roll' ),
		array( 'name' => 'Original Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/orig_pri' ),
		array( 'name' => 'Editor Added Server', 'select' => 'iframe', 'idioma' => 'Spanish', 'quality' => '720p', 'url' => 'https://custom.com/e/editor' ),
	),
);
$mock_db_tables['wp_peestream_migration_backups'][] = array(
	'id' => $wpdb->insert_id++,
	'post_id' => $p_roll_pri,
	'original_repeatable' => serialize( array(
		array( 'name' => 'Original Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/orig_pri' ),
	) ),
	'migrated_url' => 'https://streamtape.com/v/orig_pri',
	'new_code' => 'fc_pri_roll',
	'mode' => 'primary',
	'backup_time' => current_time( 'mysql' ),
	'status' => 'completed',
);

$_POST['post_id'] = $p_roll_pri;
$migrator->ajax_rollback();
$roll_pri_fields = get_post_meta( $p_roll_pri, 'repeatable_fields', true );
it( "Primary rollback removes only PeeStream server (does not duplicate original server)", count( $roll_pri_fields ) === 2 );
it( "Primary rollback keeps original server in slot 1", $roll_pri_fields[0]['url'] === 'https://streamtape.com/v/orig_pri' );
it( "Primary rollback preserves editor added server in slot 2", $roll_pri_fields[1]['url'] === 'https://custom.com/e/editor' );

// 2. Replace rollback
$p_roll_rep = 904;
$mock_posts[ $p_roll_rep ] = array( 'ID' => $p_roll_rep, 'post_title' => 'Rollback Replace Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_roll_rep ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream Player', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://peeplayer.com/e/fc_rep_roll' ),
		array( 'name' => 'Editor VIP Server', 'select' => 'iframe', 'idioma' => 'French', 'quality' => '4K', 'url' => 'https://vip.com/e/vip' ),
	),
);
$orig_rep_item = array( 'name' => 'MixDrop Fast', 'select' => 'iframe', 'idioma' => 'German', 'quality' => '720p', 'url' => 'https://mixdrop.co/e/orig_rep' );
$mock_db_tables['wp_peestream_migration_backups'][] = array(
	'id' => $wpdb->insert_id++,
	'post_id' => $p_roll_rep,
	'original_repeatable' => serialize( array( $orig_rep_item ) ),
	'migrated_url' => 'https://mixdrop.co/e/orig_rep',
	'new_code' => 'fc_rep_roll',
	'mode' => 'replace',
	'backup_time' => current_time( 'mysql' ),
	'status' => 'completed',
);

$_POST['post_id'] = $p_roll_rep;
$migrator->ajax_rollback();
$roll_rep_fields = get_post_meta( $p_roll_rep, 'repeatable_fields', true );
it( "Replace rollback restored original server URL", $roll_rep_fields[0]['url'] === 'https://mixdrop.co/e/orig_rep' );
it( "Replace rollback restored original server name ('MixDrop Fast')", $roll_rep_fields[0]['name'] === 'MixDrop Fast' );
it( "Replace rollback restored original server language ('German')", $roll_rep_fields[0]['idioma'] === 'German' );
it( "Replace rollback restored original server quality ('720p')", $roll_rep_fields[0]['quality'] === '720p' );
it( "Replace rollback preserved editor added VIP server", $roll_rep_fields[1]['url'] === 'https://vip.com/e/vip' );

// 9d. Parallel webhook atomic pre-reservation
$m_parallel = 905;
$mock_posts[ $m_parallel ] = array( 'ID' => $m_parallel, 'post_title' => 'Parallel Webhook Movie', 'post_type' => 'movies' );
$mock_postmeta[ $m_parallel ] = array(
	'imdb_id' => 'tt8888888',
	'repeatable_fields' => array(),
);

$req_par_1 = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'fc_parallel_race',
	'event' => 'movie_ready',
	'imdb_id' => 'tt8888888',
	'title' => 'Parallel Webhook Movie',
), 'POST' );

$req_par_2 = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'fc_parallel_race',
	'event' => 'movie_ready',
	'imdb_id' => 'tt8888888',
	'title' => 'Parallel Webhook Movie',
), 'POST' );

$res_par_1 = $webhook->handle_webhook( $req_par_1 );
$res_par_2 = $webhook->handle_webhook( $req_par_2 );
it( "First parallel webhook request succeeds with 200 OK", $res_par_1->get_status() === 200 );
it( "Second parallel webhook collides on atomic pre-reservation and returns replay_ignored", $res_par_2->get_status() === 200 && ( $res_par_2->get_data()['action'] ?? '' ) === 'replay_ignored' );
$par_fields = get_post_meta( $m_parallel, 'repeatable_fields', true );
it( "Post has exactly 1 player attached (zero duplicate insertion under race condition)", count( $par_fields ) === 1 );

// 9e. WP-Cron and AJAX shared concurrency lock with unique owner token
$lock_tok_1 = PeeStream_Migrator::acquire_lock();
it( "PeeStream_Migrator::acquire_lock() returns unique owner token", ! empty( $lock_tok_1 ) );
it( "PeeStream_Migrator::acquire_lock() returns false while active lock exists", false === PeeStream_Migrator::acquire_lock() );
it( "PeeStream_Migrator::release_lock() with invalid token fails", false === PeeStream_Migrator::release_lock( 'invalid_token' ) );
it( "PeeStream_Migrator::release_lock() with owner token succeeds", true === PeeStream_Migrator::release_lock( $lock_tok_1 ) );
$lock_tok_2 = PeeStream_Migrator::acquire_lock();
it( "PeeStream_Migrator::acquire_lock() succeeds after release_lock()", ! empty( $lock_tok_2 ) );
PeeStream_Migrator::release_lock( $lock_tok_2 );

// 9f. False ambiguity prevention (DISTINCT post matching)
$m_distinct = 906;
$mock_posts[ $m_distinct ] = array( 'ID' => $m_distinct, 'post_title' => 'Distinct Meta Movie', 'post_type' => 'movies' );
// Post has duplicate meta keys pointing to same IMDb ID
$mock_postmeta[ $m_distinct ] = array(
	'ids' => 'tt5555555',
	'imdb_id' => 'tt5555555',
	'repeatable_fields' => array(),
);

$req_distinct = new WP_REST_Request( array(
	'secret' => $valid_secret,
	'file_code' => 'fc_distinct_test',
	'event' => 'movie_ready',
	'imdb_id' => 'tt5555555',
	'title' => 'Distinct Meta Movie',
), 'POST' );
$res_distinct = $webhook->handle_webhook( $req_distinct );
it( "Webhook does NOT falsely flag ambiguity on post having duplicate meta entries", $res_distinct->get_status() === 200 );
it( "Distinct movie was successfully updated", ! empty( get_post_meta( $m_distinct, 'repeatable_fields', true ) ) );

// 9g. Query file info strict match
$ref_q = new ReflectionMethod( 'PeeStream_Migrator', 'query_file_info' );
$ref_q->setAccessible( true );
$res_strict = $ref_q->invoke( $migrator, 'https://peestream.com', 'test_key', 'fc_missing_item' );
it( "query_file_info() strictly returns false when filecode is not in result array (no fallback to result[0])", false === $res_strict );

// 9h. Plugin upgrade / init ensures WP-Cron is scheduled
global $mock_cron_events;
unset( $mock_cron_events['peestream_cron_process_queue'] );
it( "peestream_cron_process_queue is initially not scheduled", ! wp_next_scheduled( 'peestream_cron_process_queue' ) );
PeeStream_Plugin::ensure_cron_scheduled();
it( "PeeStream_Plugin::ensure_cron_scheduled() automatically schedules WP-Cron", (bool) wp_next_scheduled( 'peestream_cron_process_queue' ) );

// =========================================================================
// SECTION 10: Final Review Hardening Invariants & Scenarios
// =========================================================================
echo "\n--- 10. Final Review Hardening Invariants & Scenarios ---\n";

// 10a. Decoupled DB Schema Versioning & Upgrade from 4245c190
it( "PeeStream_Plugin defines decoupled DB_SCHEMA_VERSION = 105", PeeStream_Plugin::DB_SCHEMA_VERSION === 105 );

// Simulate upgrading from 4245c190 (where peestream_db_version = '1.0.1' and peestream_schema_version is unset)
delete_option( 'peestream_schema_version' );
update_option( 'peestream_db_version', '1.0.1' );

// Add a legacy backup row with missing/empty mode
$legacy_backup_id = 9988;
$mock_db_tables['wp_peestream_migration_backups'][$legacy_backup_id] = array(
	'id'                  => $legacy_backup_id,
	'post_id'             => 701,
	'original_repeatable' => serialize( array() ),
	'migrated_url'        => 'https://streamtape.com/v/legacy',
	'new_code'            => 'fc_legacy',
	'mode'                => '',
	'backup_time'         => current_time( 'mysql' ),
	'status'              => 'completed',
);

PeeStream_Plugin::check_db_upgrade();
it( "Upgrade updates peestream_schema_version to 105", (int) get_option( 'peestream_schema_version' ) === 105 );
it( "Legacy backup row mode is set to 'unknown' (NOT automatically 'replace')", $mock_db_tables['wp_peestream_migration_backups'][$legacy_backup_id]['mode'] === 'unknown' );

// 10b. Rollback Unknown Mode Safeguard
// Case A: Post still has original server -> safely resolved as primary
$p_unk_prim = 920;
$mock_posts[ $p_unk_prim ] = array( 'ID' => $p_unk_prim, 'post_title' => 'Unknown Mode Primary Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_unk_prim ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream Player', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://peeplayer.com/e/fc_unk_1' ),
		array( 'name' => 'Original Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/orig_tape' ),
	),
);
$b_unk_1 = array(
	'id'                  => $wpdb->insert_id++,
	'post_id'             => $p_unk_prim,
	'original_repeatable' => serialize( array( array( 'url' => 'https://streamtape.com/v/orig_tape' ) ) ),
	'migrated_url'        => 'https://streamtape.com/v/orig_tape',
	'new_code'            => 'fc_unk_1',
	'mode'                => 'unknown',
	'backup_time'         => current_time( 'mysql' ),
	'status'              => 'completed',
);
$mock_db_tables['wp_peestream_migration_backups'][$b_unk_1['id']] = $b_unk_1;

$_POST['post_id'] = $p_unk_prim;
$migrator->ajax_rollback();
$fields_unk_1 = get_post_meta( $p_unk_prim, 'repeatable_fields', true );
it( "Rollback unknown mode with original server present resolves as primary and removes PeeStream", count( $fields_unk_1 ) === 1 && $fields_unk_1[0]['url'] === 'https://streamtape.com/v/orig_tape' );

// Case B: Post has NO original server and mode is unknown -> flags manual_review, does NOT overwrite
$p_unk_miss = 921;
$mock_posts[ $p_unk_miss ] = array( 'ID' => $p_unk_miss, 'post_title' => 'Unknown Mode Missing Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_unk_miss ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream Player', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://peeplayer.com/e/fc_unk_2' ),
	),
);
$b_unk_2 = array(
	'id'                  => $wpdb->insert_id++,
	'post_id'             => $p_unk_miss,
	'original_repeatable' => serialize( array( array( 'url' => 'https://streamtape.com/v/orig_miss' ) ) ),
	'migrated_url'        => 'https://streamtape.com/v/orig_miss',
	'new_code'            => 'fc_unk_2',
	'mode'                => 'unknown',
	'backup_time'         => current_time( 'mysql' ),
	'status'              => 'completed',
);
$mock_db_tables['wp_peestream_migration_backups'][$b_unk_2['id']] = $b_unk_2;

$_POST['post_id'] = $p_unk_miss;
$migrator->ajax_rollback();
$fields_unk_2 = get_post_meta( $p_unk_miss, 'repeatable_fields', true );
it( "Rollback unknown mode with missing original server flags manual_review", $mock_db_tables['wp_peestream_migration_backups'][$b_unk_2['id']]['status'] === 'manual_review' );
it( "Rollback unknown mode with missing original server does NOT overwrite post servers", count( $fields_unk_2 ) === 1 && $fields_unk_2[0]['url'] === 'https://peeplayer.com/e/fc_unk_2' );

// 10c. Primary Mode Interim Conflict Detection
$p_prim_conf = 922;
$mock_posts[ $p_prim_conf ] = array( 'ID' => $p_prim_conf, 'post_title' => 'Primary Conflict Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_prim_conf ] = array(
	'repeatable_fields' => array(
		// Editor deleted the original MixDrop server in the meantime!
		array( 'name' => 'Editor Server', 'select' => 'iframe', 'idioma' => 'French', 'quality' => '720p', 'url' => 'https://other.com/v/other' ),
	),
);
$q_prim_conf = array(
	'id'           => $wpdb->insert_id++,
	'post_id'      => $p_prim_conf,
	'original_url' => 'https://mixdrop.co/e/mix_deleted_by_editor',
	'filecode'     => 'fc_prim_conflict',
	'mode'         => 'primary',
	'server_name'  => 'PeeStream Player',
	'language'     => 'English',
	'quality'      => '1080p',
	'state'        => 'pending',
	'retry_count'  => 0,
	'status'       => 'pending',
	'created_at'   => current_time( 'mysql' ),
	'updated_at'   => current_time( 'mysql' ),
);
$mock_db_tables['wp_peestream_migration_queue'][$q_prim_conf['id']] = $q_prim_conf;

$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'status' => 200,
				'result' => array(
					array( 'filecode' => 'fc_prim_conflict', 'canplay' => 1 ),
				),
			) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$migrator->process_queue_batch( 5 );
$prim_conf_item = null;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
	if ( ( $row['filecode'] ?? '' ) === 'fc_prim_conflict' ) {
		$prim_conf_item = $row;
		break;
	}
}
it( "Primary mode detects deleted original URL and marks status = conflict", ( $prim_conf_item['status'] ?? '' ) === 'conflict' );
$prim_conf_fields = get_post_meta( $p_prim_conf, 'repeatable_fields', true );
it( "Primary mode does NOT attach player when conflict is detected", count( $prim_conf_fields ) === 1 && $prim_conf_fields[0]['url'] === 'https://other.com/v/other' );

// 10d. Successive Manual AJAX Queue Checks Without Deadlock
$res_ajax_1 = null;
$res_ajax_2 = null;
$_POST['nonce'] = wp_create_nonce( 'peestream_migrator_nonce' );
ob_start();
$migrator->ajax_check_queue();
$out_1 = ob_get_clean();
$data_1 = json_decode( $out_1, true );

ob_start();
$migrator->ajax_check_queue();
$out_2 = ob_get_clean();
$data_2 = json_decode( $out_2, true );

it( "First manual AJAX check succeeds and releases lock", ! empty( $data_1['success'] ) && empty( $data_1['data']['locked'] ) );
it( "Second consecutive manual AJAX check succeeds without lock deadlock", ! empty( $data_2['success'] ) && empty( $data_2['data']['locked'] ) );

// 10e. Webhook Retry for Failed and Unmatched Events
$m_retry = 925;
$mock_posts[ $m_retry ] = array( 'ID' => $m_retry, 'post_title' => 'Retry Webhook Movie', 'post_type' => 'movies' );
$mock_postmeta[ $m_retry ] = array(
	'imdb_id' => 'tt9999991',
	'repeatable_fields' => array(),
);

// Seed a failed webhook row in DB
$mock_db_tables['wp_peestream_webhooks'][] = array(
	'id'          => $wpdb->insert_id++,
	'file_code'   => 'fc_failed_retry',
	'event'       => 'movie_ready',
	'post_id'     => 0,
	'ip'          => '127.0.0.1',
	'received_at' => date( 'Y-m-d H:i:s', time() - 120 ),
	'status'      => 'failed',
);

$req_retry = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_failed_retry',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt9999991',
	'title'     => 'Retry Webhook Movie',
), 'POST' );

$res_retry = $webhook->handle_webhook( $req_retry );
it( "Webhook allows controlled retry on 'failed' status and processes event", $res_retry->get_status() === 200 && ( $res_retry->get_data()['action'] ?? '' ) === 'movie_updated' );
$retry_fields = get_post_meta( $m_retry, 'repeatable_fields', true );
it( "Retry successfully attached PeeStream player to post", count( $retry_fields ) === 1 && strpos( $retry_fields[0]['url'], 'fc_failed_retry' ) !== false );

// =========================================================================
// SECTION 11: Production Verification & Integrity Hardening Invariants
// =========================================================================
echo "\n--- 11. Production Verification & Integrity Hardening Invariants ---\n";

// 11a. Heartbeat lock extension & Active Lease Protection
PeeStream_Migrator::release_lock();
$token_worker_a = PeeStream_Migrator::acquire_lock( 60 );
it( "Worker A acquires initial lock", ! empty( $token_worker_a ) );

// Simulate passage of time and extend lease
$extended = PeeStream_Migrator::extend_lock( $token_worker_a, 180 );
it( "Worker A extends lock lease via heartbeat extend_lock()", $extended === true );

// Worker B attempts to acquire lock while Worker A lease is active
$token_worker_b = PeeStream_Migrator::acquire_lock( 60 );
it( "Worker B is strictly rejected while Worker A lease is active", $token_worker_b === false );

// 11b. Conditional Atomic SQL Release Lock by Owner Token
$fake_release = PeeStream_Migrator::release_lock( 'wrong_fake_token_12345' );
it( "release_lock() with unowned/imposter token returns false and does NOT clear lock", $fake_release === false );
it( "Lock remains active after imposter release attempt", ! empty( get_option( 'peestream_queue_processing_lock' ) ) );

// True owner releases lock
$true_release = PeeStream_Migrator::release_lock( $token_worker_a );
it( "release_lock() with true owner token succeeds", $true_release === true );
it( "Lock option is cleanly deleted by owner release", empty( get_option( 'peestream_queue_processing_lock' ) ) );

// 11c. Unsuccessful update_post_meta Safeguard & Verification
$p_fail_meta = 930;
$mock_posts[ $p_fail_meta ] = array( 'ID' => $p_fail_meta, 'post_title' => 'Meta Failure Movie', 'post_type' => 'movies' );
$orig_meta_safe = array(
	array( 'name' => 'Original Hoster', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/safe_tape' )
);
$mock_postmeta[ $p_fail_meta ] = array( 'repeatable_fields' => $orig_meta_safe );

$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => $wpdb->insert_id++,
	'post_id'      => $p_fail_meta,
	'original_url' => 'https://streamtape.com/v/safe_tape',
	'filecode'     => 'fc_fail_save',
	'mode'         => 'replace',
	'server_name'  => 'PeeStream Player',
	'language'     => 'English',
	'quality'      => '1080p',
	'state'        => 'pending',
	'status'       => 'pending',
	'created_at'   => current_time( 'mysql' ),
	'updated_at'   => current_time( 'mysql' ),
);

// Mock API response for fc_fail_save (canplay = 1)
$mock_http_handler = function( $url ) {
	if ( strpos( $url, 'file_code=fc_fail_save' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode( array(
				'status' => 200,
				'result' => array( array( 'filecode' => 'fc_fail_save', 'canplay' => 1 ) ),
			) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

// Simulate database failure on update_post_meta
global $sim_fail_meta_update;
$sim_fail_meta_update = true;

$lock_t = PeeStream_Migrator::acquire_lock();
$migrator->process_queue_batch( 5 );
PeeStream_Migrator::release_lock( $lock_t );
$sim_fail_meta_update = false;

$failed_q_item = null;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $row ) {
	if ( ( $row['filecode'] ?? '' ) === 'fc_fail_save' ) {
		$failed_q_item = $row;
		break;
	}
}
it( "Failed post_meta update does NOT mark queue item completed", ( $failed_q_item['status'] ?? '' ) !== 'completed' );
it( "Failed post_meta update marks queue item failed with apply_failed error", ( $failed_q_item['status'] ?? '' ) === 'failed' );
$preserved_fields = get_post_meta( $p_fail_meta, 'repeatable_fields', true );
it( "Post repeatable_fields remains 100% intact when update verification fails", $preserved_fields === $orig_meta_safe );

// 11d. Partial Operation Interruption & Recovery
$p_interrupted = 931;
$mock_posts[ $p_interrupted ] = array( 'ID' => $p_interrupted, 'post_title' => 'Interrupted Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_interrupted ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Original Link', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/interrupted' )
	)
);

// Insert an in_progress backup simulating a crash between backup and post update
$interrupted_backup_id = 9944;
$mock_db_tables['wp_peestream_migration_backups'][$interrupted_backup_id] = array(
	'id'                  => $interrupted_backup_id,
	'post_id'             => $p_interrupted,
	'original_repeatable' => serialize( $mock_postmeta[ $p_interrupted ]['repeatable_fields'] ),
	'migrated_url'        => 'https://streamtape.com/v/interrupted',
	'new_code'            => 'fc_interrupted',
	'mode'                => 'replace',
	'backup_time'         => date( 'Y-m-d H:i:s', time() - 300 ),
	'status'              => 'in_progress',
);
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => $wpdb->insert_id++,
	'post_id'      => $p_interrupted,
	'original_url' => 'https://streamtape.com/v/interrupted',
	'filecode'     => 'fc_interrupted',
	'mode'         => 'replace',
	'status'       => 'checking',
	'created_at'   => date( 'Y-m-d H:i:s', time() - 300 ),
	'updated_at'   => date( 'Y-m-d H:i:s', time() - 300 ),
);

// Run recovery
PeeStream_Migrator::recover_partial_failures();
it( "recover_partial_failures() detects interrupted in_progress backup without player and marks apply_failed", $mock_db_tables['wp_peestream_migration_backups'][$interrupted_backup_id]['status'] === 'apply_failed' );

// 11e. Webhook Crash Recovery and Duplicate Draft Prevention
$fc_draft_crash = 'fc_draft_crash_99';
$m_crash_post_id = 940;
$mock_posts[ $m_crash_post_id ] = array(
	'ID'          => $m_crash_post_id,
	'post_title'  => 'Crash Movie Title',
	'post_type'   => 'movies',
	'post_status' => 'draft',
);
$mock_postmeta[ $m_crash_post_id ] = array(
	'_peestream_webhook_filecode' => $fc_draft_crash,
	'_peestream_webhook_event'    => 'movie_ready',
	'repeatable_fields'           => array(),
);

// Webhook table record left in 'processing' state (e.g. timeout after wp_insert_post)
$mock_db_tables['wp_peestream_webhooks'][] = array(
	'id'          => $wpdb->insert_id++,
	'file_code'   => $fc_draft_crash,
	'event'       => 'movie_ready',
	'post_id'     => $m_crash_post_id,
	'ip'          => '127.0.0.1',
	'received_at' => date( 'Y-m-d H:i:s', time() - 120 ),
	'status'      => 'processing',
);

$initial_post_count = count( $mock_posts );

$req_crash = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => $fc_draft_crash,
	'event'     => 'movie_ready',
	'title'     => 'Crash Movie Title',
), 'POST' );

$res_crash = $webhook->handle_webhook( $req_crash );
it( "Webhook crash retry succeeds without error", $res_crash->get_status() === 201 || $res_crash->get_status() === 200 );
it( "Webhook crash retry re-uses existing draft and does NOT create duplicate post", count( $mock_posts ) === $initial_post_count );

// 11f. Historical Backup Misclassification Safe Rollback
$p_hist_replace = 950;
$mock_posts[ $p_hist_replace ] = array( 'ID' => $p_hist_replace, 'post_title' => 'Historical Replace Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_hist_replace ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream Player', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://peeplayer.com/e/fc_hist_rep' ),
		array( 'name' => 'Original Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/hist_tape' ),
	)
);
$b_hist = array(
	'id'                  => $wpdb->insert_id++,
	'post_id'             => $p_hist_replace,
	'original_repeatable' => serialize( array( array( 'url' => 'https://streamtape.com/v/hist_tape' ) ) ),
	'migrated_url'        => 'https://streamtape.com/v/hist_tape',
	'new_code'            => 'fc_hist_rep',
	'mode'                => 'replace', // Legacy auto-assigned replace, but original URL is present!
	'backup_time'         => date( 'Y-m-d H:i:s', time() - 3600 ),
	'status'              => 'completed',
);
$mock_db_tables['wp_peestream_migration_backups'][$b_hist['id']] = $b_hist;

$_POST['post_id'] = $p_hist_replace;
$migrator->ajax_rollback();

$hist_fields = get_post_meta( $p_hist_replace, 'repeatable_fields', true );
it( "Rollback on historical replace backup with original present reclassifies as primary and avoids duplicating original server", count( $hist_fields ) === 1 && $hist_fields[0]['url'] === 'https://streamtape.com/v/hist_tape' );

// =========================================================================
// SECTION 12: Release v1.0.3 Reviewer Requirements Hardening Tests
// =========================================================================
echo "\n--- 12. Release v1.0.3 Reviewer Requirements Hardening Tests ---\n";

// 12a. Manual Migration Lock Protection (ajax_process_batch Concurrency Safeguard)
$lock_active_token = PeeStream_Migrator::acquire_lock( 120 );
it( "Simulate active worker lock acquisition", ! empty( $lock_active_token ) );

$_POST = array(
	'post_ids'      => array( 202 ),
	'mode'          => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Concurrent ajax_process_batch() is rejected with locked = true while active lock is held", ! empty( $last_json_res['data']['locked'] ) );
it( "Concurrent ajax_process_batch() returns error message regarding active process", strpos( $last_json_res['data']['message'], 'currently running' ) !== false );

// Release lock and ensure normal batch process can proceed
PeeStream_Migrator::release_lock( $lock_active_token );

// 12b. Multi-Server Atomicity (Post with 3 Competitor Servers)
$p_multi = 960;
$mock_posts[ $p_multi ] = array( 'ID' => $p_multi, 'post_title' => 'Multi Server Movie', 'post_type' => 'movies' );
$orig_multi_meta = array(
	array( 'name' => 'Streamtape Server', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/multi1' ),
	array( 'name' => 'MixDrop Server',    'select' => 'iframe', 'idioma' => 'English', 'quality' => '720p',  'url' => 'https://mixdrop.co/f/multi2' ),
	array( 'name' => 'VOE Server',        'select' => 'iframe', 'idioma' => 'German',  'quality' => '1080p', 'url' => 'https://voe.sx/multi3' ),
);
$mock_postmeta[ $p_multi ] = array(
	'repeatable_fields' => $orig_multi_meta,
);

// Mock PeeStream APIs to immediately return ready state
$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		if ( strpos( $url, 'multi1' ) !== false ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_multi1' ) ) ) );
		}
		if ( strpos( $url, 'multi2' ) !== false ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_multi2' ) ) ) );
		}
		if ( strpos( $url, 'multi3' ) !== false ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_multi3' ) ) ) );
		}
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode( array(
				'status' => 200,
				'result' => array(
					array( 'filecode' => 'fc_multi1', 'state' => 'ready', 'canplay' => 1 ),
					array( 'filecode' => 'fc_multi2', 'state' => 'ready', 'canplay' => 1 ),
					array( 'filecode' => 'fc_multi3', 'state' => 'ready', 'canplay' => 1 ),
				),
			) ),
		);
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_multi ),
	'mode'          => 'replace',
	'target_hoster' => 'all',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();

$multi_servers_after = get_post_meta( $p_multi, 'repeatable_fields', true );
it( "Multi-server post: all 3 competitor servers replaced with PeeStream", count( $multi_servers_after ) === 3 &&
	strpos( $multi_servers_after[0]['url'], 'fc_multi1' ) !== false &&
	strpos( $multi_servers_after[1]['url'], 'fc_multi2' ) !== false &&
	strpos( $multi_servers_after[2]['url'], 'fc_multi3' ) !== false
);

// Verify distinct backup rows created for each server
$multi_backups = array();
foreach ( $mock_db_tables['wp_peestream_migration_backups'] as $b ) {
	if ( (int) ( $b['post_id'] ?? 0 ) === $p_multi && 'completed' === ( $b['status'] ?? '' ) ) {
		$multi_backups[] = $b;
	}
}
it( "Multi-server post: created exactly 3 distinct completed backup records", count( $multi_backups ) === 3 );

// Verify surgical rollback of single server: rollback server 1 only
// Simulate rolling back the first backup entry
$b1 = $multi_backups[0];
$mock_db_tables['wp_peestream_migration_backups'][$b1['id']]['status'] = 'completed'; // Ensure active
// Temporarily mock get_results to only return backup 1 for this test
$old_backups_table = $mock_db_tables['wp_peestream_migration_backups'];
$mock_db_tables['wp_peestream_migration_backups'] = array( $b1['id'] => $b1 );

$_POST['post_id'] = $p_multi;
$migrator->ajax_rollback();

$restored_b1_fields = get_post_meta( $p_multi, 'repeatable_fields', true );
it( "Surgical rollback on multi-server post: restored Server 1 to original Streamtape", $restored_b1_fields[0]['url'] === 'https://streamtape.com/v/multi1' );
it( "Surgical rollback on multi-server post: Server 2 remains intact PeeStream (fc_multi2)", strpos( $restored_b1_fields[1]['url'], 'fc_multi2' ) !== false );
it( "Surgical rollback on multi-server post: Server 3 remains intact PeeStream (fc_multi3)", strpos( $restored_b1_fields[2]['url'], 'fc_multi3' ) !== false );

// Restore complete backups table
$mock_db_tables['wp_peestream_migration_backups'] = $old_backups_table;

// 12c. Idempotent Pre-Reservation and Duplicate Elimination
$p_idem = 970;
$mock_posts[ $p_idem ] = array( 'ID' => $p_idem, 'post_title' => 'Idempotent Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_idem ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Streamtape', 'select' => 'iframe', 'idioma' => 'English', 'quality' => '1080p', 'url' => 'https://streamtape.com/v/idem_link' )
	)
);

// Pre-insert completed queue item for (p_idem, url)
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'                  => $wpdb->insert_id++,
	'post_id'             => $p_idem,
	'original_url'        => 'https://streamtape.com/v/idem_link',
	'filecode'            => 'fc_idem_done',
	'mode'                => 'primary',
	'server_name'         => 'PeeStream Player',
	'language'            => 'English',
	'quality'             => '1080p',
	'state'               => 'ready',
	'status'              => 'completed',
	'retry_count'         => 0,
	'error_msg'           => '',
	'original_repeatable' => serialize( $mock_postmeta[ $p_idem ]['repeatable_fields'] ),
	'created_at'          => current_time( 'mysql', true ),
	'updated_at'          => current_time( 'mysql', true ),
);

$upload_call_count = 0;
$mock_http_handler = function( $url, $args ) use ( &$upload_call_count ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		$upload_call_count++;
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_should_not_call' ) ) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_idem ),
	'mode'          => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Pre-reserved / already migrated queue item skips remote upload API call", 0 === $upload_call_count );
it( "Pre-reserved / already migrated queue item logs skip message", strpos( $last_json_res['data']['logs'][0], '[SKIP]' ) !== false );

// 12d. Strict DB Schema Verification
global $sim_missing_schema_column, $sim_missing_schema_index;
$sim_missing_schema_column = 'quality';
delete_option( 'peestream_schema_version' );
$schema_res_fail = PeeStream_Plugin::create_tables();
it( "create_tables() fails and returns false when required table column is missing", false === $schema_res_fail );
it( "peestream_schema_version is NOT updated on schema verification failure", false === get_option( 'peestream_schema_version' ) );

$sim_missing_schema_column = null;
$sim_missing_schema_index = 'uq_post_urlhash';
$schema_res_fail_idx = PeeStream_Plugin::create_tables();
it( "create_tables() fails and returns false when UNIQUE index uq_post_urlhash is missing", false === $schema_res_fail_idx );
it( "peestream_schema_version is NOT updated on missing index", false === get_option( 'peestream_schema_version' ) );

$sim_missing_schema_index = null;
$schema_res_pass = PeeStream_Plugin::create_tables();
it( "create_tables() succeeds when all columns and unique keys are verified", true === $schema_res_pass );
PeeStream_Plugin::check_db_upgrade();
it( "peestream_schema_version is updated to 105 on verified schema", (int) get_option( 'peestream_schema_version' ) === 105 );

// 12e. Webhook Verified Server Attachment Failure (HTTP 500 & Failed Audit Status)
$fc_fail_attach = 'fc_fail_attach_123';
$p_fail_attach  = 980;
$mock_posts[ $p_fail_attach ] = array(
	'ID'          => $p_fail_attach,
	'post_title'  => 'Attach Fail Movie',
	'post_type'   => 'movies',
	'post_status' => 'publish',
);
$mock_postmeta[ $p_fail_attach ] = array(
	'imdb_id'           => 'tt9998887',
	'repeatable_fields' => array(),
);

// Simulate failure when saving post meta during webhook processing
global $sim_fail_meta_update;
$sim_fail_meta_update = true;

$req_fail_attach = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => $fc_fail_attach,
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt9998887',
	'title'     => 'Attach Fail Movie',
), 'POST' );

$res_fail_attach = $webhook->handle_webhook( $req_fail_attach );
it( "Webhook returns HTTP 500 when server attachment fails to persist", $res_fail_attach->get_status() === 500 );

$failed_wh_record = null;
foreach ( $mock_db_tables['wp_peestream_webhooks'] as $wh ) {
	if ( ( $wh['file_code'] ?? '' ) === $fc_fail_attach ) {
		$failed_wh_record = $wh;
		break;
	}
}
it( "Webhook audit record is set to 'failed' (NOT 'processed') when post meta save fails", ( $failed_wh_record['status'] ?? '' ) === 'failed' );

$sim_fail_meta_update = false;

echo "\n--- 13. Release v1.0.4 Reviewer Invariant Tests ---\n";

// 13a. Title collision across different movies: draft recovery MUST NOT match title-only
$p_movie_a = 1101;
$mock_posts[ $p_movie_a ] = array(
	'ID'          => $p_movie_a,
	'post_title'  => 'Unbroken',
	'post_type'   => 'movies',
	'post_status' => 'draft',
);
$mock_postmeta[ $p_movie_a ] = array(
	'imdb_id'           => 'tt1111111',
	'ids'               => 'tt1111111',
	'repeatable_fields' => array(),
);

// Enable auto-create posts
update_option( 'peestream_settings', array_merge( $mock_options['peestream_settings'], array( 'auto_create_posts' => '1' ) ) );

$req_movie_b = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_movie_b_unique',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt2222222',
	'title'     => 'Unbroken',
), 'POST' );

$res_movie_b = $webhook->handle_webhook( $req_movie_b );
it( "Webhook processes movie_ready with shared title successfully", in_array( $res_movie_b->get_status(), array( 200, 201 ), true ) );
$data_b = $res_movie_b->get_data();
it( "Webhook did NOT re-use draft of Movie A (tt1111111) for Movie B (tt2222222)", (int) ( $data_b['post_id'] ?? 0 ) !== $p_movie_a );
it( "Movie A draft remains untouched without Movie B player", empty( $mock_postmeta[ $p_movie_a ]['repeatable_fields'] ) );

// 13b. Episodes with same title from different series MUST NOT cross-contaminate
$p_series_a = 1201;
$p_series_b = 1202;
$p_ep_a = 1211;
$mock_posts[ $p_series_a ] = array( 'ID' => $p_series_a, 'post_title' => 'Series Alpha', 'post_type' => 'tvshows' );
$mock_postmeta[ $p_series_a ] = array( 'imdb_id' => 'tt_series_alpha', 'ids' => 'tt_series_alpha' );

$mock_posts[ $p_series_b ] = array( 'ID' => $p_series_b, 'post_title' => 'Series Beta', 'post_type' => 'tvshows' );
$mock_postmeta[ $p_series_b ] = array( 'imdb_id' => 'tt_series_beta', 'ids' => 'tt_series_beta' );

$mock_posts[ $p_ep_a ] = array( 'ID' => $p_ep_a, 'post_title' => 'Pilot', 'post_type' => 'episodes' );
$mock_postmeta[ $p_ep_a ] = array(
	'ids'               => $p_series_a,
	'temporada'         => '1',
	'episodio'          => '1',
	'repeatable_fields' => array(),
);

// Webhook arrives for Series Beta S01E01 ("Pilot")
$req_ep_beta = new WP_REST_Request( array(
	'secret'      => $valid_secret,
	'file_code'   => 'fc_ep_beta_01',
	'event'       => 'episode_ready',
	'series_imdb' => 'tt_series_beta',
	'season'      => '1',
	'episode'     => '1',
	'title'       => 'Pilot',
), 'POST' );
$res_ep_beta = $webhook->handle_webhook( $req_ep_beta );
it( "Episode webhook does not attach Series Beta episode player to Series Alpha episode", empty( $mock_postmeta[ $p_ep_a ]['repeatable_fields'] ) );

// 13c. Interruption between post creation and metadata assignment triggers transaction rollback
$initial_posts_count = count( $mock_posts );
global $sim_fail_meta_update;
$sim_fail_meta_update = true;

$req_crash_draft = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_crash_draft_test',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt7776655',
	'title'     => 'Crash Draft Movie',
), 'POST' );
$res_crash_draft = $webhook->handle_webhook( $req_crash_draft );
it( "Webhook returns 500 when draft metadata update fails", 500 === $res_crash_draft->get_status() );
it( "Transaction rolled back: no orphan post draft left in database", count( $mock_posts ) === $initial_posts_count );

$sim_fail_meta_update = false;

// Test wp_insert_post returning 0
global $sim_wp_insert_post_zero;
$sim_wp_insert_post_zero = true;
$req_zero_draft = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_zero_draft_test',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt8887766',
	'title'     => 'Zero Draft Movie',
), 'POST' );
$res_zero_draft = $webhook->handle_webhook( $req_zero_draft );
it( "Webhook safely aborts with 500 when wp_insert_post returns 0", 500 === $res_zero_draft->get_status() );
$sim_wp_insert_post_zero = false;

// 13d. Queue pre-reservation DB failure strictly halts upload (does not call PeeStream API)
$p_res_fail = 1301;
$mock_posts[ $p_res_fail ] = array( 'ID' => $p_res_fail, 'post_title' => 'Res Fail Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_res_fail ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Competitor', 'url' => 'https://streamtape.com/v/fail_res_url' ),
	),
);

global $sim_fail_queue_insert;
$sim_fail_queue_insert = true;
$upload_called_on_fail = false;
$mock_http_handler = function( $url, $args ) use ( &$upload_called_on_fail ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		$upload_called_on_fail = true;
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_res_fail ),
	'mode'          => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Pre-reservation INSERT failure strictly halts and does NOT call upload API", false === $upload_called_on_fail );
it( "Pre-reservation failure reports error log and incremented failed_count", (int) ( $last_json_res['data']['failed_count'] ?? 0 ) >= 1 );

$sim_fail_queue_insert = false;

// 13e. Concurrent reservation collision defers second process without duplicate upload
$p_conc = 1401;
$conc_url = 'https://streamtape.com/v/concurrent_link';
$mock_posts[ $p_conc ] = array( 'ID' => $p_conc, 'post_title' => 'Conc Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_conc ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Server 1', 'url' => $conc_url ),
	),
);

// Worker A has claimed the queue record with active owner token
$conc_url_hash = hash( 'sha256', $conc_url );
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'                  => 8801,
	'post_id'             => $p_conc,
	'original_url'        => $conc_url,
	'url_hash'            => $conc_url_hash,
	'filecode'            => '',
	'mode'                => 'primary',
	'server_name'         => 'PeeStream Player',
	'language'            => 'English',
	'quality'             => '1080p',
	'state'               => 'uploading',
	'status'              => 'checking',
	'owner_token'         => 'worker_a_active_token',
	'retry_count'         => 0,
	'error_msg'           => '',
	'original_repeatable' => '',
	'created_at'          => current_time( 'mysql' ),
	'updated_at'          => current_time( 'mysql' ),
);

$upload_called_conc = false;
$mock_http_handler = function( $url, $args ) use ( &$upload_called_conc ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		$upload_called_conc = true;
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_conc ),
	'mode'          => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Concurrent worker does not trigger duplicate upload when queue row is locked", false === $upload_called_conc );
it( "Concurrent reservation collision logs BUSY / defer message", strpos( $last_json_res['data']['logs'][0] ?? '', '[BUSY]' ) !== false );

// 13f. Failed backup finalization marks apply_failed and does not complete
$p_bfail = 1501;
$bfail_url = 'https://streamtape.com/v/backup_fail_link';
$mock_posts[ $p_bfail ] = array( 'ID' => $p_bfail, 'post_title' => 'Backup Fail Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_bfail ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Server 1', 'url' => $bfail_url ),
	),
);

global $sim_fail_backup_status;
$sim_fail_backup_status = true;

$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_bfail_test' ) ) ) );
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( array( 'filecode' => 'fc_bfail_test', 'canplay' => 1 ) ) ) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_bfail ),
	'mode'          => 'replace',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Failed backup finalization is reported as failed_count in response", (int) ( $last_json_res['data']['failed_count'] ?? 0 ) === 1 );

$queue_record_bfail = null;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $q ) {
	if ( (int) ( $q['post_id'] ?? 0 ) === $p_bfail ) {
		$queue_record_bfail = $q;
		break;
	}
}
it( "Failed backup finalization sets queue state to apply_failed", ( $queue_record_bfail['state'] ?? '' ) === 'apply_failed' );
it( "Failed backup finalization sets queue status to failed (NOT completed)", ( $queue_record_bfail['status'] ?? '' ) === 'failed' );

$sim_fail_backup_status = false;

// 13g. Long URLs (>191 chars) with shared prefix do not collide due to SHA-256 url_hash
$long_prefix = 'https://streamtape.com/v/' . str_repeat( 'abcdefghij', 20 ); // 225 chars long
$long_url_1 = $long_prefix . '_paramX_1111';
$long_url_2 = $long_prefix . '_paramY_2222';

$hash_1 = hash( 'sha256', trim( $long_url_1 ) );
$hash_2 = hash( 'sha256', trim( $long_url_2 ) );

it( "Long URLs share prefix longer than 191 chars", substr( $long_url_1, 0, 191 ) === substr( $long_url_2, 0, 191 ) );
it( "SHA-256 hashes of long URLs are completely distinct", $hash_1 !== $hash_2 );

$p_long = 1601;
$mock_posts[ $p_long ] = array( 'ID' => $p_long, 'post_title' => 'Long URL Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_long ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'Server 1', 'url' => $long_url_1 ),
		array( 'name' => 'Server 2', 'url' => $long_url_2 ),
	),
);

$long_uploads = 0;
$mock_http_handler = function( $url, $args ) use ( &$long_uploads ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		$long_uploads++;
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_long_' . $long_uploads ) ) ) );
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'canplay' => 0 ) ) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

$_POST = array(
	'post_ids'      => array( $p_long ),
	'mode'          => 'primary',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);
$migrator->ajax_process_batch();
it( "Both long URLs queued without colliding on uq_post_urlhash", 2 === $long_uploads );
it( "Batch reports pending_count = 2 for distinct long URLs", (int) ( $last_json_res['data']['pending_count'] ?? 0 ) === 2 );

// 13h. Admin batch response separates completed, pending, failed, and skipped counters
it( "Admin batch response provides completed_count", isset( $last_json_res['data']['completed_count'] ) );
it( "Admin batch response provides pending_count", isset( $last_json_res['data']['pending_count'] ) );
it( "Admin batch response provides failed_count", isset( $last_json_res['data']['failed_count'] ) );
it( "Admin batch response provides skipped_count", isset( $last_json_res['data']['skipped_count'] ) );

echo "\n--- 14. Release v1.0.4 Rigorous Hardening Invariants (Reviewer Findings) ---\n";

// 14a. P0: Simulated DB error during archiving aborts migration without deleting any queue rows
$sim_missing_schema_index = 'uq_post_urlhash';
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 90001,
	'post_id'      => 8001,
	'original_url' => 'https://streamtape.com/v/dup_test_1',
	'url_hash'     => hash( 'sha256', 'https://streamtape.com/v/dup_test_1' ),
	'status'       => 'completed',
);
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 90002,
	'post_id'      => 8001,
	'original_url' => 'https://streamtape.com/v/dup_test_1',
	'url_hash'     => hash( 'sha256', 'https://streamtape.com/v/dup_test_1' ),
	'status'       => 'pending',
);
$queue_cnt_before = count( $mock_db_tables['wp_peestream_migration_queue'] );
$sim_fail_archive_insert = true;
$res_create_fail = PeeStream_Plugin::create_tables();
it( "create_tables() returns false when archiving fails due to SQL error", false === $res_create_fail );
$queue_cnt_after_fail = count( $mock_db_tables['wp_peestream_migration_queue'] );
it( "Zero queue rows deleted when archiving duplicate fails", $queue_cnt_before === $queue_cnt_after_fail );
$sim_fail_archive_insert = false;
$sim_missing_schema_index = null;

// 14b. P1: Global lock lease loss halts migration and protects postmeta
$p_lock_test = 1701;
$orig_lock_meta = array(
	array( 'name' => 'Original Server', 'url' => 'https://streamtape.com/v/lock_test_stream' ),
);
$mock_posts[ $p_lock_test ] = array( 'ID' => $p_lock_test, 'post_title' => 'Lock Test Movie', 'post_type' => 'movies' );
$mock_postmeta[ $p_lock_test ] = array( 'repeatable_fields' => $orig_lock_meta );

// Setup HTTP mock to report ready video
$mock_http_handler = function( $url, $args ) {
	if ( strpos( $url, '/api/upload/url' ) !== false ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_lock_lost' ) ) ) );
	}
	if ( strpos( $url, '/api/file/info' ) !== false ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => 200, 'result' => array( 'filecode' => 'fc_lock_lost', 'canplay' => 1 ) ) ) );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
};

// Steal lock before batch starts to simulate lost lease / race condition
$usurper_token = 'imposter_worker_token_9999999999';
update_option( 'peestream_queue_processing_lock', $usurper_token . '|' . ( time() + 300 ) );

$_POST = array(
	'post_ids'      => array( $p_lock_test ),
	'mode'          => 'replace',
	'target_hoster' => 'streamtape',
	'dry_run'       => 0,
);

$migrator->ajax_process_batch();
it( "ajax_process_batch() is rejected or halts when global lock is held by another worker", true === ( $last_json_res['data']['locked'] ?? false ) || false === $last_json_res['success'] );
it( "Post repeatable_fields is completely untouched when lock acquisition/lease is lost", $mock_postmeta[ $p_lock_test ]['repeatable_fields'] === $orig_lock_meta );

// Clean lock
delete_option( 'peestream_queue_processing_lock' );

// 14c. P1: Webhook transaction failure hardening (START TRANSACTION, COMMIT)
$sim_fail_start_tx = true;
$req_tx_fail = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_tx_fail_movie',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt9999001',
	'title'     => 'Transaction Failure Movie',
), 'POST' );
$res_tx_fail = $webhook->handle_webhook( $req_tx_fail );
it( "Webhook returns HTTP 500 when START TRANSACTION fails", 500 === $res_tx_fail->get_status() );
$sim_fail_start_tx = false;

$sim_fail_commit_tx = true;
$req_commit_fail = new WP_REST_Request( array(
	'secret'    => $valid_secret,
	'file_code' => 'fc_commit_fail_movie',
	'event'     => 'movie_ready',
	'imdb_id'   => 'tt9999002',
	'title'     => 'Commit Failure Movie',
), 'POST' );
$res_commit_fail = $webhook->handle_webhook( $req_commit_fail );
it( "Webhook returns HTTP 500 when COMMIT fails", 500 === $res_commit_fail->get_status() );
$sim_fail_commit_tx = false;

// 14d. P1: Full Binary URL identity (case-sensitive tokens do not collide when url_hash is empty)
$token_url_upper = 'https://streamtape.com/v/AbCd12';
$token_url_lower = 'https://streamtape.com/v/abcd12';
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 9901,
	'post_id'      => 1801,
	'original_url' => $token_url_upper,
	'url_hash'     => '', // Legacy row without url_hash
	'filecode'     => 'fc_upper',
	'status'       => 'completed',
);

global $wpdb;
$table_queue = 'wp_peestream_migration_queue';
$found_lower = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$table_queue} WHERE post_id = %d AND (url_hash = %s OR (url_hash = '' AND BINARY original_url = %s))",
	1801,
	hash( 'sha256', $token_url_lower ),
	$token_url_lower
), ARRAY_A );
it( "Binary URL check distinguishes case-sensitive token /v/abcd12 from legacy /v/AbCd12", null === $found_lower );

$found_upper = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$table_queue} WHERE post_id = %d AND (url_hash = %s OR (url_hash = '' AND BINARY original_url = %s))",
	1801,
	hash( 'sha256', $token_url_upper ),
	$token_url_upper
), ARRAY_A );
it( "Binary URL check correctly matches exact case token /v/AbCd12", null !== $found_upper && ( $found_upper['filecode'] ?? '' ) === 'fc_upper' );

// 14d-2. P0: create_tables() deduplication query strictly uses BINARY comparison so URLs differing only by case are NOT merged
$p_case_dedup = 1850;
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 9951,
	'post_id'      => $p_case_dedup,
	'original_url' => 'https://streamtape.com/v/CaseDiffToken',
	'url_hash'     => '',
	'status'       => 'pending',
);
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 9952,
	'post_id'      => $p_case_dedup,
	'original_url' => 'https://streamtape.com/v/casedifftoken',
	'url_hash'     => '',
	'status'       => 'pending',
);

$wp_code = file_get_contents( WP_PLUGIN_DIR_PATH . 'peestream.php' );
$expected_binary_dedup = "( (q1.url_hash != '' AND q2.url_hash != '' AND q1.url_hash = q2.url_hash) OR (BINARY q1.original_url = BINARY q2.original_url) )";
it( "create_tables() deduplication SQL enforces BINARY exact URL comparison", strpos( $wp_code, $expected_binary_dedup ) !== false );

$q_dedup_res = PeeStream_Plugin::create_tables();
it( "create_tables() succeeds with case-distinct URLs", false !== $q_dedup_res );

$rem_9951 = false;
$rem_9952 = false;
foreach ( $mock_db_tables['wp_peestream_migration_queue'] as $qr ) {
	if ( ( $qr['id'] ?? 0 ) === 9951 ) $rem_9951 = true;
	if ( ( $qr['id'] ?? 0 ) === 9952 ) $rem_9952 = true;
}
it( "Case-sensitive token URL 1 (/v/CaseDiffToken) is preserved in queue", $rem_9951 );
it( "Case-sensitive token URL 2 (/v/casedifftoken) is preserved in queue", $rem_9952 );

// 14e. P1: Mode-aware partial backup recovery in recover_partial_failures()
$p_rec_replace = 1901;
$mock_posts[ $p_rec_replace ] = array( 'ID' => $p_rec_replace, 'post_title' => 'Recovery Replace Movie', 'post_type' => 'movies' );
// State: New player attached AND old original server replaced (removed)
$mock_postmeta[ $p_rec_replace ] = array(
	'repeatable_fields' => array(
		array( 'name' => 'PeeStream HD', 'url' => 'https://peeplayer.com/e/fc_rec_rep' ),
	),
);
$mock_db_tables['wp_peestream_migration_backups'][] = array(
	'id'                  => 8801,
	'post_id'             => $p_rec_replace,
	'migrated_url'        => 'https://streamtape.com/v/orig_replace_st',
	'new_code'            => 'fc_rec_rep',
	'mode'                => 'replace',
	'backup_time'         => gmdate( 'Y-m-d H:i:s', time() - 300 ),
	'status'              => 'in_progress',
	'original_repeatable' => serialize( array( array( 'name' => 'Server 1', 'url' => 'https://streamtape.com/v/orig_replace_st' ) ) ),
);
$mock_db_tables['wp_peestream_migration_queue'][] = array(
	'id'           => 7701,
	'post_id'      => $p_rec_replace,
	'original_url' => 'https://streamtape.com/v/orig_replace_st',
	'url_hash'     => hash( 'sha256', 'https://streamtape.com/v/orig_replace_st' ),
	'filecode'     => 'fc_rec_rep',
	'status'       => 'pending',
	'owner_token'  => '',
	'updated_at'   => gmdate( 'Y-m-d H:i:s', time() - 300 ),
);

PeeStream_Migrator::recover_partial_failures();

$b_rec_status = '';
foreach ( $mock_db_tables['wp_peestream_migration_backups'] as $b_row ) {
	if ( (int) ( $b_row['id'] ?? 0 ) === 8801 ) {
		$b_rec_status = $b_row['status'] ?? '';
	}
}
it( "recover_partial_failures() validates replace mode and promotes backup to completed", 'completed' === $b_rec_status );

// 14f. P2: Admin JS Reporting 'Queued / Pending encoding'
$js_content = file_get_contents( WP_PLUGIN_DIR_PATH . 'assets/js/peestream-migrator.js' );
it( "peestream-migrator.js checks for totalCompleted === 0 && totalPending > 0", strpos( $js_content, 'totalCompleted === 0 && totalPending > 0' ) !== false );
it( "peestream-migrator.js sets status text to Queued / Pending encoding", strpos( $js_content, 'Queued / Pending encoding.' ) !== false );
it( "peestream-migrator.js logs [QUEUED / PENDING ENCODING]", strpos( $js_content, '[QUEUED / PENDING ENCODING]' ) !== false );

echo "\n=======================================================\n";
echo "Test Results: {$passed} PASSED, {$failed} FAILED\n";
echo "=======================================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
exit( 0 );
