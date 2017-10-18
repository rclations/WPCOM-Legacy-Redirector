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
	 * ## EXAMPLES
	 *
	 * wp wpcom-legacy-redirector verify-redirects
	 *
	 * @subcommand verify-redirects
	 * @synopsis [--status=<status>] [--format=<format>] [--verbose]
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

		$query_count = 0;
		$posts_per_page = 100;
		$paged = 0;
		$offset = 0;
		$notices = array();
		$update_redirect_status[] = array();

		if ( 'all' === $status ) {
			$total_redirects_where = "post_type = '" . WPCOM_Legacy_Redirector::POST_TYPE . "'";
		} else {
			$total_redirects_where = "post_type = '" . WPCOM_Legacy_Redirector::POST_TYPE . "' AND post_status = '" . $status . "'";
		}
		$total_redirects = $wpdb->get_var( "SELECT COUNT( ID ) FROM $wpdb->posts WHERE " . $total_redirects_where );
		$query_count = WPCOM_Legacy_Redirector::update_query_count( $query_count );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Verifying ' . number_format( $total_redirects ) . ' redirects', (int) $total_redirects );
		$progress->tick();

		do {

			$validated_redirects = array();

			$redirects_query = array(
				'where' => "a.post_type = '" . WPCOM_Legacy_Redirector::POST_TYPE . "'",
				'order' => "a.post_parent ASC",
				'limit' => ( $paged * $posts_per_page ) - $offset . ', ' . $posts_per_page,
			);

			if ( 'all' !== $status ) {
				$redirects_query['where'] .= " AND a.post_status = '" . $status . "'";
			}

			$redirects_to_verify = $wpdb->get_results(
				"SELECT a.ID, a.post_title, a.post_excerpt, a.post_parent, a.post_status,
						b.ID AS 'parent_id', b.post_status as 'parent_status', b.post_type as 'parent_post_type'
					FROM $wpdb->posts a
					LEFT JOIN $wpdb->posts b
						ON a.post_parent = b.ID
					WHERE " . $redirects_query['where'] . "
					ORDER BY " . $redirects_query['order'] . "
					LIMIT " . $redirects_query['limit']
			);
			$query_count = WPCOM_Legacy_Redirector::update_query_count( $query_count );

			// Validate redirects
			foreach ( $redirects_to_verify as $redirect_to_validate ) {

				$redirect = array(
					'id' => $redirect_to_validate->ID,
					'from' => array(
						'raw' => $redirect_to_validate->post_title,
						'formatted' => $redirect_to_validate->post_title,
					),
					'to' => array(
						'raw' => '',
						'formatted' => '',
					),
					'post_status' => $redirect_to_validate->post_status,
					'parent' => array(
						'id' => $redirect_to_validate->parent_id,
						'status' => $redirect_to_validate->parent_status,
						'post_type' => $redirect_to_validate->parent_post_type,
					),
				);

				// Format relative from urls
				if ( '/' == substr( $redirect['from']['raw'], 0, 1 ) ) {
					$redirect['from']['formatted'] = home_url( $redirect['from']['raw'] );
				}

				// Format and validate, based on redirect destination type.
				if ( ! empty( $redirect_to_validate->post_excerpt ) ) {
					$redirect['destination_type'] = 'url';
					$redirect['to']['raw'] = $redirect_to_validate->post_excerpt;

					// Format relative URLs
					if ( '/' == substr( $redirect['to']['raw'], 0, 1 ) ) {
						$redirect['to']['formatted'] = home_url( $redirect['to']['raw'] );
					}

					$validation = WPCOM_Legacy_Redirector::validate_url_redirect( $redirect, $post_types );

				} else {
					$redirect['destination_type'] = 'post';
					$redirect['to']['raw'] = $redirect_to_validate->post_parent;

					// Set here for error handling. Update value post-validation, since it requires an expensive get_permalink() call.
					$redirect['to']['formatted'] = $redirect['to']['raw'];

					$validation = WPCOM_Legacy_Redirector::validate_post_redirect( $redirect, $post_types );
				}

				if ( is_wp_error( $validation ) ) {
					$notices[] = array(
						'id'        => $redirect['id'],
						'from_url'  => $redirect['from']['raw'],
						'to_url'    => $redirect['to']['formatted'],
						'message'   => $validation->get_error_message(),
					);
					if ( 'draft' !== $redirect['post_status'] ) {
						$update_redirect_status['draft'][] = $redirect['id'];
					}
					continue;
				}

				// Update $redirect['to']['formatted'] for redirects to post ids.
				if ( 'post' === $redirect['destination_type'] ) {

					// Reuse parent post object in case of multiple redirects to same post ID.
					if ( ! isset( $parent->ID ) || intval( $redirect['parent']['id'] ) !== $parent->ID ) {
						$parent = get_post( $redirect['parent']['id'] );
					}

					$redirect['to']['formatted'] = get_permalink( $parent );
					$query_count = WPCOM_Legacy_Redirector::update_query_count( $query_count );
				}

				$redirects[ $redirect['id'] ] = array(
					'id' => $redirect['id'],
					'from' => $redirect['from'],
					'to' => $redirect['to'],
					'poststatus' => $redirect['post_status']
				);
			}

			// Clear unneeded variable.
			$redirects_to_verify = '';

			$redirects = WPCOM_Legacy_Redirector::try_redirects( $redirects, $progress );

			foreach( $redirects as $key => $redirect ) {

				$verify = WPCOM_Legacy_Redirector::verify_redirect( $redirect );

				if ( is_wp_error( $verify ) ) {
					$notices[] = array(
						'id'        => $redirect['id'],
						'from_url'  => $redirect['from']['raw'],
						'to_url'    => $redirect['to']['formatted'],
						'message'   => $verify->get_error_message(),
					);
					if ( 'draft' !== $redirect['post_status'] ) {
						$update_redirect_status['draft'][] = $redirect['id'];
					}
					continue;
				} else {
					if ( $assoc_args['verbose'] ) {
						$notices[] = array(
							'id'        => $redirect['id'],
							'from_url'  => $redirect['from']['path'],
							'to_url'    => $redirect['to']['raw'],
							'message'   => 'Verified',
						);
					}

					if ( 'publish' !== $redirect['post_status'] ) {
						$update_redirect_status['publish'][] = $redirect['id'];
					}
				}
			}

			// Update redirect status
			if ( count( $update_redirect_status ) > 0 ) {
				foreach ( $update_redirect_status as $redirect_status => $redirects_to_update ) {
					foreach ( $redirects_to_update as $redirect_to_update ) {
						$updated_rows = $wpdb->update( $wpdb->posts, array( 'post_status' => $redirect_status ), array( 'ID' => $redirect_to_update ) );
						$query_count = WPCOM_Legacy_Redirector::update_query_count( $query_count );

						if ( $updated_rows ) {
							clean_post_cache( $updated_rows );

							if ( $redirect_status !== $status ) {
								$offset = $offset + $updated_rows;
							}
						}
					}
				}
				$update_redirect_status = array();
			}

			$paged++;
		} while ( count( $redirects_to_verify ) );

		$progress->finish();

		if ( count( $notices ) > 0 ) {
			WP_CLI\Utils\format_items( $format, $notices, array( 'id', 'from_url', 'to_url', 'message' ) );
		} else {
			echo WP_CLI::colorize( "%GAll of your redirects have been verified. Nice work!%n " );
		}
	}
}

WP_CLI::add_command( 'wpcom-legacy-redirector', 'WPCOM_Legacy_Redirector_CLI' );
