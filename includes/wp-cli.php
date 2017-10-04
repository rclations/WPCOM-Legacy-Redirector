<?php

class WPCOM_Legacy_Redirector_CLI extends WP_CLI_Command {

	/**
	 * Find domains redirected to, useful to populate the allowed_redirect_hosts filter.
	 *
	 * @subcommand find-domains
	 */
	function find_domains( $args, $assoc_args ) {
		global $wpdb;

		$posts_per_page = 500;
		$paged = 0;

		$domains = array();

		$total_redirects = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( ID ) FROM $wpdb->posts WHERE post_type = %s AND post_excerpt LIKE %s",
				WPCOM_Legacy_Redirector::POST_TYPE,
				'http%'
			)
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Finding domains', (int) $total_redirects );
		do {
			$redirect_urls = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_excerpt FROM $wpdb->posts WHERE post_type = %s AND post_excerpt LIKE %s ORDER BY ID ASC LIMIT %d, %d",
					WPCOM_Legacy_Redirector::POST_TYPE,
					'http%',
					( $paged * $posts_per_page ),
					$posts_per_page
				)
			);

			foreach ( $redirect_urls as $redirect_url ) {
				$progress->tick();
				if ( ! empty( $redirect_url ) ) {
					$redirect_host = parse_url( $redirect_url, PHP_URL_HOST );
					if ( $redirect_host ) {
						$domains[] = $redirect_host;
					}
				}
			}

			// Pause.
			sleep( 1 );
			$paged++;
		} while ( count( $redirect_urls ) );

		$progress->finish();

		$domains = array_unique( $domains );

		WP_CLI::line( sprintf( 'Found %s unique outbound domains', number_format( count( $domains ) ) ) );

		foreach ( $domains as $domain ) {
			WP_CLI::line( $domain );
		}
	}

	/**
	 * Insert a single redirect
	 *
	 * @subcommand insert-redirect
	 * @synopsis <from_url> <to_url>
	 */
	function insert_redirect( $args, $assoc_args ) {
		$from_url = esc_url_raw( $args[0] );

		if ( is_numeric( $args[1] ) ) {
			$to_url = absint( $args[1] );
		} else {
			$to_url = esc_url_raw( $args[1] );
		}

		$inserted = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from_url, $to_url );

		if ( ! $inserted || is_wp_error( $inserted ) ) {
			WP_CLI::warning( sprintf( "Couldn't insert %s -> %s", $from_url, $to_url ) );
		}

		WP_CLI::success( sprintf( "Inserted %s -> %s", $from_url, $to_url ) );
	}

	/**
	 * Bulk import redirects from URLs stored as meta values for posts.
	 *
	 * @subcommand import-from-meta
	 * @synopsis --meta_key=<name-of-meta-key> [--start=<start-offset>] [--end=<end-offset>] [--skip_dupes=<skip-dupes>] [--dry_run]
	 */
	function import_from_meta( $args, $assoc_args ) {
		define( 'WP_IMPORTING', true );

		global $wpdb;
		$offset = isset( $assoc_args['start'] ) ? intval( $assoc_args['start'] ) : 0;
		$end_offset = isset( $assoc_args['end'] ) ? intval( $assoc_args['end'] ) : 99999999;;
		$meta_key = isset( $assoc_args['meta_key'] ) ? sanitize_key( $assoc_args['meta_key'] ) : '';
		$skip_dupes = isset( $assoc_args['skip_dupes'] ) ? (bool) intval( $assoc_args['skip_dupes'] ) : false;
		$dry_run = isset( $assoc_args['dry_run'] ) ? true : false;

		if ( true === $dry_run ) {
			WP_CLI::line( "---Dry Run---" );
		} else {
			WP_CLI::line( "---Live Run--" );
		}

		do {
			$redirects = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s ORDER BY post_id ASC LIMIT %d, 1000", $meta_key, $offset ) );
			$i = 0;
			$total = count( $redirects );
			WP_CLI::line( "Found $total entries" );

			foreach ( $redirects as $redirect ) {
				$i++;
				WP_CLI::line( "Adding redirect for {$redirect->post_id} from {$redirect->meta_value}" );
				WP_CLI::line( "-- $i of $total (starting at offset $offset)" );

				if ( true === $skip_dupes && 0 !== WPCOM_Legacy_Redirector::get_redirect_post_id( parse_url( $redirect->meta_value, PHP_URL_PATH ) ) ) {
					WP_CLI::line( "Redirect for {$redirect->post_id} from {$redirect->meta_value} already exists. Skipping" );
					continue;
				}

				if ( false === $dry_run ) {
					WPCOM_Legacy_Redirector::insert_legacy_redirect( $redirect->meta_value, $redirect->post_id );
				}

				if ( 0 == $i % 100 ) {
					if ( function_exists( 'stop_the_insanity' ) ) {
						stop_the_insanity();
					}
					sleep( 1 );
				}
			}
			$offset += 1000;
		} while ( $redirects && $offset < $end_offset );
	}

	/**
	 * Bulk import redirects from a CSV file matching the following structure:
	 *
	 * redirect_from_path,(redirect_to_post_id|redirect_to_path|redirect_to_url)
	 *
	 * @subcommand import-from-csv
	 * @synopsis --csv=<path-to-csv>
	 */
	function import_from_csv( $args, $assoc_args ) {
		define( 'WP_IMPORTING', true );

		if ( empty( $assoc_args['csv'] ) || ! file_exists( $assoc_args['csv'] ) ) {
			WP_CLI::error( "Invalid 'csv' file" );
		}

		global $wpdb;
		$row = 0;
		if ( ( $handle = fopen( $assoc_args['csv'], "r" ) ) !== FALSE ) {
			while ( ( $data = fgetcsv( $handle, 2000, "," ) ) !== FALSE ) {
				$row++;
				$redirect_from = $data[ 0 ];
				$redirect_to = $data[ 1 ];
				WP_CLI::line( "Adding (CSV) redirect for {$redirect_from} to {$redirect_to}" );
				WP_CLI::line( "-- at $row" );
				WPCOM_Legacy_Redirector::insert_legacy_redirect( $redirect_from, $redirect_to );

				if ( 0 == $row % 100 ) {
					if ( function_exists( 'stop_the_insanity' ) ) {
						stop_the_insanity();
					}
					sleep( 1 );
				}
			}
			fclose( $handle );
		}
	}

	/**
	 * Verify a redirect using curl.
	 *
	 * ## EXAMPLES
	 *
	 * wp wpcom-legacy-redirector verify-redirects
	 *
	 * @subcommand verify-redirect-curl
	 * @synopsis --url=<url>
	 */
	function verify_redirect_curl( $args, $assoc_args ) {
		if ( ! function_exists('curl_version') ) {
			return 'false';
		}

		$url = \WP_CLI\Utils\get_flag_value( $assoc_args, 'url' );

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url); //set url
		curl_setopt($ch, CURLOPT_HEADER, true); //get header
		curl_setopt($ch, CURLOPT_NOBODY, true); //do not include response body
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //do not show in browser the response
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); //follow any redirects
		curl_exec($ch);
		$new_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); //extract the url from the header response
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
	}


	/**
	 * Verify our redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]:
	 * : Filter by verification status.
	 * ---
	 * default: unverified
	 * options:
	 *   - unverified
	 *   - verified
	 *   - all
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: csv
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--verbose]
	 * : Print notifications to the console for passing URLs.
	 *
	 * ## EXAMPLES
	 *
	 * wp wpcom-legacy-redirector verify-redirects
	 *
	 * @subcommand verify-redirects
	 * @synopsis [--status=<status>] [--format=<format>] [--verbose]
	 */
	function verify_redirects( $args, $assoc_args ) {

		if ( ! function_exists('curl_version') ) {
			WP_CLI::error( "Curl is not installed. Please install before using the verify-redirects command." );
		}

		global $wpdb;
		$post_types = get_post_types( array( 'public' => true ) );

		$status = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status' );
		switch ( $status ) {
			case 'unverified':
				$status = 'draft';
				break;
			case 'verified':
				$status = 'publish';
				break;
		}
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format' );

		$posts_per_page = 500;
		$paged = 0;
		$total_processed_count = 0;
		$notices = array();

		if ( 'all' === $status ) {
			$total_redirects = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT( ID ) FROM $wpdb->posts WHERE post_type = %s",
					WPCOM_Legacy_Redirector::POST_TYPE
				)
			);
		} else {
			$total_redirects = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT( ID ) FROM $wpdb->posts WHERE post_type = %s AND post_status = %s",
					WPCOM_Legacy_Redirector::POST_TYPE,
					$status
				)
			);
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Verifying ' . $total_redirects . ' redirects', (int) $total_redirects );

		do {
			if ( 'all' === $status ) {
				$redirects = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT a.ID, a.post_title, a.post_excerpt, a.post_parent, a.post_status,
							b.ID AS 'parent_id', b.post_status as 'parent_status', b.post_type as 'parent_post_type'
						FROM $wpdb->posts a
						LEFT JOIN $wpdb->posts b
							ON a.post_parent = b.ID
						WHERE a.post_type = %s
						ORDER BY a.post_parent ASC
						LIMIT %d, %d",
						WPCOM_Legacy_Redirector::POST_TYPE,
						( $paged * $posts_per_page ),
						$posts_per_page
					)
				);
			} else {
				$redirects = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT a.ID, a.post_title, a.post_excerpt, a.post_parent, a.post_status,
							b.ID AS 'parent_id', b.post_status as 'parent_status', b.post_type as 'parent_post_type'
						FROM $wpdb->posts a
						LEFT JOIN $wpdb->posts b
							ON a.post_parent = b.ID
						WHERE a.post_type = %s
							AND a.post_status = %s
						ORDER BY a.post_parent ASC
						LIMIT %d, %d",
						WPCOM_Legacy_Redirector::POST_TYPE,
						$status,
						( $paged * $posts_per_page ),
						$posts_per_page
					)
				);
			}

			$redirects_to_check = array();

			// Validation checks before verifying the redirect
			foreach ( $redirects as $redirect ) {
				$total_processed_count++;

				$from = array(
					'path' => $redirect->post_title,
					'url' => home_url( $redirect->post_title ),
				);
				$to = array();

				if ( ! empty( $redirect->post_excerpt ) ) {

					// If redirecting to a URL

					$to = array(
						'raw' => $redirect->post_excerpt,
						'formatted' => $redirect->post_excerpt,
					);

					// Is this a valid URL, and an allowed host?
					if ( ! wp_validate_redirect( $to_url['formatted'], false ) ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from['path'],
							'to_url'    => $to['raw'],
							'message'   => 'failed wp_validate_redirect()',
						);
						if ( 'draft' !== $redirect->post_status ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						$progress->tick();
						continue;
					}

				} else {

					// If redirecting to a post_id

					$to['raw'] = $redirect->post_parent;

					// Check if post_parent is not set.
					if ( ! $redirect->parent_id > 0 ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from['path'],
							'to_url'    => $to['raw'],
							'message'   => 'Attempting to redirect to a nonexistent post id.',
						);
						if ( 'draft' !== $redirect->post_status ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						$progress->tick();
						continue;
					} elseif ( 'publish' !== $redirect->parent_status && 'attachment' !== $redirect->parent_post_type ) {

						// Check if the post we're redirecting to is published, or is an attachement.

						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from['path'],
							'to_url'    => $to['raw'],
							'message'   => 'Attempting to redirect to an unpublished post.',
						);
						if ( 'draft' !== $redirect->post_status ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						$progress->tick();
						continue;

					} elseif ( ! in_array( $redirect->parent_post_type, $post_types ) ) {

						// Check if the post we're redirecting to is in a publicly accessible post type.

						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from['path'],
							'to_url'    => $to['raw'],
							'message'   => 'Attempting to redirect to a private post type: ' . $redirect->parent_post_type,
						);
						if ( 'draft' !== $redirect->post_status ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						$progress->tick();
						continue;
					}

					// Reuse parent post object in case of multiple redirects to same post ID.
					if ( ! isset( $parent->ID ) || intval( $redirect->parent_id ) !== $parent->ID ) {
						$parent = get_post( $redirect->post_parent );
					}

					$to['formatted'] = get_permalink( $parent );

				} // End if().

				$redirects_to_check[ $redirect->ID ] = array(
					'from' => $from,
					'to' => $to,
					'poststatus' => $redirect->post_status
				);
			} // End foreach().
			$redirect = '';

			if ( count( $redirects_to_check ) > 0 ) {

				$mh = curl_multi_init();

				foreach( $redirects_to_check as $key => $redirect ) {
					$ch[ $key ] = curl_init( $redirect['from']['url'] );

					// max simultaneously open connections - see https://curl.haxx.se/libcurl/c/CURLMOPT_MAX_TOTAL_CONNECTIONS.html
					curl_multi_setopt( $mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 100 );

					// try HTTP/1 pipelining and HTTP/2 multiplexing - see https://curl.haxx.se/libcurl/c/CURLMOPT_PIPELINING.html
					curl_multi_setopt( $mh, CURLMOPT_PIPELINING, CURLPIPE_HTTP1 | CURLPIPE_MULTIPLEX );

					curl_setopt( $ch[ $key ], CURLOPT_URL, $redirect['from']['url'] );  // URL to work on.
//					curl_setopt( $ch[ $key ], CURLOPT_VERBOSE, true );
					curl_setopt( $ch[ $key ], CURLOPT_SSL_VERIFYSTATUS, false );
					curl_setopt( $ch[ $key ], CURLOPT_HEADER, true );                   // Include the header in the body output.
					curl_setopt( $ch[ $key ], CURLOPT_NOBODY, true );                   // Do not get the body contents.
					curl_setopt( $ch[ $key ], CURLOPT_FOLLOWLOCATION, true );           // follow HTTP 3xx redirects
					curl_setopt( $ch[ $key ], CURLOPT_MAXCONNECTS, 100 );
					curl_setopt( $ch[ $key ], CURLOPT_RETURNTRANSFER, true );

					curl_multi_add_handle( $mh, $ch[ $key ] );
				}

				$active = null;
				do {
					$mrc = curl_multi_exec( $mh, $active );
				} while ( $mrc == CURLM_CALL_MULTI_PERFORM );


				$thread_count = 0;

				while ( $active && $mrc == CURLM_OK ) {
					// Wait for activity on any curl-connection
					if ( curl_multi_select( $mh ) == -1 ) {
						sleep( 1 );
					}

					// Continue to exec until curl is ready to give us more data
					do {
						$mrc = curl_multi_exec( $mh, $active );

						// Monitor progress and report back to WP_CLI progressbar
						if ( ( count( $ch ) - $active ) != $thread_count ) {
							$thread_count++;
							$progress->tick();
						}
					} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
				}

				foreach ( array_keys( $ch ) as $key ) {
					$redirect_status    = curl_getinfo( $ch[ $key ], CURLINFO_HTTP_CODE );
					$redirect_count     = curl_getinfo( $ch[ $key ], CURLINFO_REDIRECT_COUNT );
					$resulting_url      = curl_getinfo( $ch[ $key ], CURLINFO_EFFECTIVE_URL );

					if ( 0 === $redirect_count ) {

						// No redirect occurred.
						$notices[] = array(
							'id'        => $key,
							'from_url'  => $redirects_to_check[ $key ]['from']['url'],
							'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
							'message'   => sprintf( 'Did not redirect.' ) . $resulting_url . ' vs ' . $redirects_to_check[ $key ]['to']['formatted'],
						);

						if ( 'draft' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['draft'][] = $key;
						}

					} elseif ( 1 < $redirect_count ) {

						// More than 1 redirect occurred.
						$notices[] = array(
							'id'        => $key,
							'from_url'  => $redirects_to_check[ $key ]['from']['url'],
							'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
							'message'   => sprintf( 'Redirected %s times', $redirect_count ),
						);

						if ( 'draft' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['draft'][] = $key;
						}

					} elseif ( 3 == substr( $redirect_status, 0, 1 ) ) {

						// Not a 300-level status.
						$notices[] = array(
							'id'        => $key,
							'from_url'  => $redirects_to_check[ $key ]['from']['path'],
							'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
							'message'   => $redirect_status,
						);

						if ( 'draft' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['draft'][] = $key;
						}

					} elseif ( $redirects_to_check[ $key ]['to']['formatted'] === $resulting_url ) {

						// If the URL matches (verified).
						if ( $assoc_args['verbose'] ) {
							$notices[] = array(
								'id'        => $key,
								'from_url'  => $redirects_to_check[ $key ]['from']['path'],
								'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
								'message'   => 'Verified',
							);
						}
						if ( 'publish' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['publish'][] = $key;
						}

					} elseif ( $resulting_url === $from_url . '/' ) {
						$notices[] = array(
							'id'        => $key,
							'from_url'  => $redirects_to_check[ $key ]['from']['path'],
							'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
							'message'   => 'Did not redirect to new page (only to add trailing slash).',
						);

						if ( 'draft' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['draft'][] = $key;
						}
					} else {
						$notices[] = array(
							'id'        => $key,
							'from_url'  => $redirects_to_check[ $key ]['from']['path'],
							'to_url'    => $redirects_to_check[ $key ]['to']['raw'],
							'message'   => $redirect_status . ' Mismatch: redirected to ' . $resulting_url . ', expected ' . $redirects_to_check[ $key ]['from']['url'],
						);
						var_dump( $redirects_to_check[ $key ], $key );

						if ( 'draft' !== $redirects_to_check[ $key ]['poststatus'] ) {
							$update_redirect_status['draft'][] = $key;
						}
					}

					curl_multi_remove_handle( $mh, $ch[ $key ] );
				}
				curl_multi_close( $mh );
			}

			$paged++;
		} while ( count( $redirects ) );

		$progress->finish();

		// Update redirect status
		if ( count( $update_redirect_status ) > 0 ) {
			$current_row = 0;
			$progress2 = \WP_CLI\Utils\make_progress_bar( sprintf( 'Updating status of %d redirects.', (int) $total_redirects ) , (int) $total_redirects );

			foreach ( $update_redirect_status as $redirect_status => $redirects_to_update ) {
				foreach ( $redirects_to_update as $redirect_to_update ) {
					$current_row++;
					if ( 0 == $current_row % 100 ) {
						sleep ( 1 );
					}
					$result = $wpdb->update( $wpdb->posts, array( 'post_status' => $redirect_status ), array( 'ID' => $redirect_to_update ) );
					if ( false === $result ) {
						WP_CLI::warning( sprintf( 'Could not update redirect %s to %s.', $redirect_to_update, $redirect_status ) );
					}
					$progress2->tick();
				}
			}
			$progress2->finish();
		}

		exit;

		if ( count( $notices ) > 0 ) {
			WP_CLI\Utils\format_items( $format, $notices, array( 'id', 'from_url', 'to_url', 'message' ) );
		} else {
			echo WP_CLI::colorize( "%GAll of your redirects have been verified. Nice work!%n " );
		}
	}
}

WP_CLI::add_command( 'wpcom-legacy-redirector', 'WPCOM_Legacy_Redirector_CLI' );
