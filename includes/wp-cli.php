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
	 * [--strict]
	 * : Enable verification for validated redirects to post ids.
	 *
	 * ## EXAMPLES
	 *
	 * wp wpcom-legacy-redirector verify-redirects
	 *
	 * @subcommand verify-redirects
	 * @synopsis [--status=<status>] [--format=<format>] [--verbose] [--strict]
	 */
	function verify_redirects( $args, $assoc_args ) {

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
		$count = 0;
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

			foreach ( $redirects as $redirect ) {
				$count++;
				$progress->tick();

				if ( 0 == $count % 100 ) {
					sleep( 1 );
				}

				$from_path = $redirect->post_title;
				$from_url = home_url( $from_path );

				if ( ! empty( $redirect->post_excerpt ) ) {

					$to_url['raw'] = $redirect->post_excerpt;
					$to_url['formatted'] = $to_url['raw'];

					if ( ! wp_validate_redirect( $to_url['formatted'], false ) ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $redirect->post_excerpt,
							'message'   => 'failed wp_validate_redirect()',
						);
						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					}

				} else {
					$to_url['raw'] = $redirect->post_parent;

					if ( ! $redirect->parent_id > 0 ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $to_url['raw'],
							'message'   => 'Attempting to redirect to a nonexistent post id.',
						);
						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					}

					if ( 'publish' !== $redirect->parent_status && 'attachment' !== $redirect->parent_post_type ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $to_url['raw'],
							'message'   => 'Attempting to redirect to an unpublished post.',
						);
						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					}

					if ( ! in_array( $redirect->parent_post_type, $post_types ) ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $to_url['raw'],
							'message'   => 'Attempting to redirect to a private post type: ' . $redirect->parent_post_type,
						);
						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					}

					// Don't verify urls to validated post ids unless the [--strict] flag is explicitly set.
					if ( ! $assoc_args['strict'] ) {
						if ( 'publish' !== $redirect->poststatus ) {
							$update_redirect_status['publish'][] = $redirect->ID;
						}
						continue;
					}

					// Reuse parent post object in case of multiple redirects to same post ID.
					if ( ! isset( $parent->ID ) || intval( $redirect->parent_id ) !== $parent->ID ) {
						$parent = get_post( $redirect->post_parent );
					}

					$to_url['formatted'] = get_permalink( $parent );

				} // End if().

				$from_url_head = wp_remote_head( $from_url );
				$resulting_url = wp_remote_retrieve_header( $from_url_head, 'location' );
				$redirect_status = wp_remote_retrieve_response_code( $from_url_head );
				$redirect_status_message = wp_remote_retrieve_response_message( $from_url_head );

				if ( 3 == substr( $redirect_status, 0, 1 ) ) {
					if ( $to_url['formatted'] === $resulting_url ) {
						if ( $assoc_args['verbose'] ) {
							$notices[] = array(
								'id'        => $redirect->ID,
								'from_url'  => $from_path,
								'to_url'    => $to_url['raw'],
								'message'   => 'Verified',
							);
						}
						if ( 'publish' !== $redirect->post_status ) {
							$update_redirect_status['publish'][] = $redirect->ID;
						}
						continue;

					} elseif ( $resulting_url === $from_url . '/' ) {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $to_url['raw'],
							'message'   => 'Did not redirect to new page (only to add trailing slash).',
						);

						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					} else {
						$notices[] = array(
							'id'        => $redirect->ID,
							'from_url'  => $from_path,
							'to_url'    => $to_url['raw'],
							'message'   => 'Mismatch: redirected to ' . $resulting_url,
						);

						if ( 'draft' !== $redirect->poststatus ) {
							$update_redirect_status['draft'][] = $redirect->ID;
						}
						continue;
					}
				} elseif ( 2 == substr( $redirect_status, 0, 1 ) ) {
					$notices[] = array(
						'id'        => $redirect->ID,
						'from_url'  => $from_path,
						'to_url'    => $to_url['raw'],
						'message'   => 'Did not redirect - returned ' . $redirect_status,
					);

					if ( 'draft' !== $redirect->poststatus ) {
						$update_redirect_status['draft'][] = $redirect->ID;
					}
					continue;
				} else {
					$notices[] = array(
						'id'        => $redirect->ID,
						'from_url'  => $from_path,
						'to_url'    => $to_url['raw'],
						'message'   => $redirect_status . ' ' . $redirect_status_message,
					);

					if ( 'draft' !== $redirect->poststatus ) {
						$update_redirect_status['draft'][] = $redirect->ID;
					}
					continue;
				} // End if().
			} // End foreach().

			$paged++;
		} while ( count( $redirects ) );

		// Update redirect status
		if ( count( $update_redirect_status ) > 0 ) {
			foreach ( $update_redirect_status as $status => $redirects_to_update ) {
				foreach ( $redirects_to_update as $redirect_to_update ) {
					$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $redirect_to_update ) );
				}
			}
		}

		$progress->finish();

		if ( count( $notices ) > 0 ) {
			WP_CLI\Utils\format_items( $format, $notices, array( 'id', 'from_url', 'to_url', 'message' ) );
		} else {
			echo WP_CLI::colorize( "%GAll of your redirects have been verified. Nice work!%n " );
		}
	}
}

WP_CLI::add_command( 'wpcom-legacy-redirector', 'WPCOM_Legacy_Redirector_CLI' );
