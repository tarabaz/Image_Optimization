<?php
/**
 * Endpoint AJAX: batch di conversione, .htaccess, verifica, reset.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestione delle richieste AJAX dell'area di amministrazione.
 */
class FS3D_IO_Ajax {

	/**
	 * Azione usata per il nonce.
	 */
	const NONCE_ACTION = 'fs3d_io_ajax';

	/**
	 * Registra gli handler.
	 *
	 * @return void
	 */
	public static function init() {
		$actions = array(
			'start_batch'   => 'start_batch',
			'process_batch' => 'process_batch',
			'cancel_batch'  => 'cancel_batch',
			'batch_status'  => 'batch_status',
			'htaccess'      => 'htaccess',
			'verify'        => 'verify',
			'refresh_stats' => 'refresh_stats',
			'clear_log'     => 'clear_log',
		);

		foreach ( $actions as $suffix => $method ) {
			add_action( 'wp_ajax_fs3d_io_' . $suffix, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Controlli comuni a ogni endpoint.
	 *
	 * @return void
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 'fs3d-image-optimizer' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Prepara la coda di lavorazione.
	 *
	 * @return void
	 */
	public static function start_batch() {
		self::guard();

		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'optimize';
		$mode  = in_array( $mode, array( 'optimize', 'reset' ), true ) ? $mode : 'optimize';
		$force = ! empty( $_POST['force'] );

		if ( 'reset' === $mode ) {
			$ids = self::collect_reset_ids();
		} elseif ( ! empty( $_POST['ids'] ) ) {
			$raw = wp_unslash( $_POST['ids'] );
			$raw = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
		} else {
			$filters = isset( $_POST['filters'] ) ? (array) wp_unslash( $_POST['filters'] ) : array();
			$ids     = FS3D_IO_Library::collect_ids( FS3D_IO_Library::sanitize_filters( $filters ) );
		}

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessuna immagine corrisponde alla selezione.', 'fs3d-image-optimizer' ) ) );
		}

		update_option( FS3D_IO_OPT_QUEUE, $ids, false );
		update_option(
			FS3D_IO_OPT_QUEUE_META,
			array(
				'mode'      => $mode,
				'force'     => $force ? 1 : 0,
				'offset'    => 0,
				'total'     => count( $ids ),
				'converted' => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'deleted'   => 0,
				'saved'     => 0,
				'started'   => time(),
			),
			false
		);

		wp_send_json_success(
			array(
				'total'      => count( $ids ),
				'batch_size' => (int) FS3D_IO_Settings::get( 'batch_size', 8 ),
				'mode'       => $mode,
			)
		);
	}

	/**
	 * Elabora un batch della coda.
	 *
	 * @return void
	 */
	public static function process_batch() {
		self::guard();

		$queue = get_option( FS3D_IO_OPT_QUEUE, array() );
		$meta  = get_option( FS3D_IO_OPT_QUEUE_META, array() );

		if ( ! is_array( $queue ) || empty( $queue ) || ! is_array( $meta ) ) {
			wp_send_json_error( array( 'message' => __( 'Nessuna operazione in corso.', 'fs3d-image-optimizer' ) ) );
		}

		$offset     = isset( $meta['offset'] ) ? (int) $meta['offset'] : 0;
		$total      = isset( $meta['total'] ) ? (int) $meta['total'] : count( $queue );
		$mode       = isset( $meta['mode'] ) ? (string) $meta['mode'] : 'optimize';
		$force      = ! empty( $meta['force'] );
		$batch_size = (int) FS3D_IO_Settings::get( 'batch_size', 8 );
		$budget     = FS3D_IO_Settings::batch_time_budget();
		$started    = microtime( true );

		$items     = array();
		$processed = 0;

		while ( $processed < $batch_size && $offset < $total ) {
			$id = isset( $queue[ $offset ] ) ? (int) $queue[ $offset ] : 0;
			$offset++;
			$processed++;

			if ( $id <= 0 ) {
				continue;
			}

			if ( 'reset' === $mode ) {
				$deleted          = FS3D_IO_Attachment::delete_generated_files( $id );
				$meta['deleted']  = ( isset( $meta['deleted'] ) ? (int) $meta['deleted'] : 0 ) + $deleted;
				$items[]          = array(
					'id'      => $id,
					'name'    => get_the_title( $id ),
					'status'  => 'deleted',
					'message' => sprintf(
						/* translators: %d: numero di file eliminati. */
						_n( '%d file eliminato.', '%d file eliminati.', $deleted, 'fs3d-image-optimizer' ),
						$deleted
					),
				);
			} else {
				$summary = FS3D_IO_Attachment::optimize( $id, $force );

				$meta['converted'] = ( isset( $meta['converted'] ) ? (int) $meta['converted'] : 0 ) + (int) $summary['converted'];
				$meta['skipped']   = ( isset( $meta['skipped'] ) ? (int) $meta['skipped'] : 0 ) + ( 'skipped' === $summary['status'] ? 1 : 0 );
				$meta['failed']    = ( isset( $meta['failed'] ) ? (int) $meta['failed'] : 0 ) + ( 'failed' === $summary['status'] ? 1 : 0 );
				$meta['saved']     = ( isset( $meta['saved'] ) ? (int) $meta['saved'] : 0 ) + (int) $summary['saved'];

				$items[] = array(
					'id'      => $id,
					'name'    => $summary['files'] ? basename( (string) $summary['files'][0]['src'] ) : get_the_title( $id ),
					'status'  => $summary['status'],
					'saved'   => size_format( $summary['saved'] ),
					'message' => self::summary_message( $summary ),
				);
			}

			// Ci fermiamo prima del timeout PHP: il batch successivo riprende da qui.
			if ( ( microtime( true ) - $started ) > $budget ) {
				break;
			}
		}

		$meta['offset'] = $offset;
		update_option( FS3D_IO_OPT_QUEUE_META, $meta, false );

		FS3D_IO_Logger::flush();
		FS3D_IO_Stats::invalidate();

		$done = $offset >= $total;

		if ( $done ) {
			delete_option( FS3D_IO_OPT_QUEUE );

			if ( 'reset' === $mode ) {
				self::finish_reset();
			}
		}

		wp_send_json_success(
			array(
				'done'      => $done,
				'offset'    => $offset,
				'total'     => $total,
				'percent'   => $total > 0 ? (int) round( ( $offset / $total ) * 100 ) : 100,
				'items'     => $items,
				'converted' => isset( $meta['converted'] ) ? (int) $meta['converted'] : 0,
				'skipped'   => isset( $meta['skipped'] ) ? (int) $meta['skipped'] : 0,
				'failed'    => isset( $meta['failed'] ) ? (int) $meta['failed'] : 0,
				'deleted'   => isset( $meta['deleted'] ) ? (int) $meta['deleted'] : 0,
				'saved'     => size_format( isset( $meta['saved'] ) ? (int) $meta['saved'] : 0 ),
				'elapsed'   => round( microtime( true ) - $started, 2 ),
			)
		);
	}

	/**
	 * Interrompe l'operazione in corso.
	 *
	 * @return void
	 */
	public static function cancel_batch() {
		self::guard();

		delete_option( FS3D_IO_OPT_QUEUE );
		delete_option( FS3D_IO_OPT_QUEUE_META );

		FS3D_IO_Logger::add( 'info', __( 'Operazione interrotta manualmente.', 'fs3d-image-optimizer' ) );
		FS3D_IO_Logger::flush();

		wp_send_json_success( array( 'message' => __( 'Operazione interrotta.', 'fs3d-image-optimizer' ) ) );
	}

	/**
	 * Stato di una eventuale coda rimasta in sospeso.
	 *
	 * @return void
	 */
	public static function batch_status() {
		self::guard();

		$queue = get_option( FS3D_IO_OPT_QUEUE, array() );
		$meta  = get_option( FS3D_IO_OPT_QUEUE_META, array() );

		if ( ! is_array( $queue ) || empty( $queue ) || ! is_array( $meta ) ) {
			wp_send_json_success( array( 'pending' => false ) );
		}

		wp_send_json_success(
			array(
				'pending' => true,
				'mode'    => isset( $meta['mode'] ) ? $meta['mode'] : 'optimize',
				'offset'  => isset( $meta['offset'] ) ? (int) $meta['offset'] : 0,
				'total'   => isset( $meta['total'] ) ? (int) $meta['total'] : count( $queue ),
			)
		);
	}

	/**
	 * Attiva, disattiva o ripristina le regole .htaccess.
	 *
	 * @return void
	 */
	public static function htaccess() {
		self::guard();

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';

		switch ( $operation ) {
			case 'activate':
				$result = FS3D_IO_Htaccess::add_rules();
				break;

			case 'deactivate':
				$result = FS3D_IO_Htaccess::remove_rules();
				break;

			case 'restore':
				$backup = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : '';
				$result = FS3D_IO_Htaccess::restore_backup( $backup );
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'Operazione non valida.', 'fs3d-image-optimizer' ) ) );
		}

		$payload = array(
			'message' => $result['message'],
			'backup'  => $result['backup'],
			'active'  => FS3D_IO_Htaccess::has_rules(),
			'current' => FS3D_IO_Htaccess::rules_are_current(),
		);

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( $payload );
		}

		wp_send_json_success( $payload );
	}

	/**
	 * Esegue la verifica della content negotiation.
	 *
	 * @return void
	 */
	public static function verify() {
		self::guard();

		$result = FS3D_IO_Verifier::run();

		FS3D_IO_Logger::add(
			$result['success'] ? 'success' : 'warning',
			sprintf( /* translators: %s: esito della verifica. */ __( 'Verifica regole: %s', 'fs3d-image-optimizer' ), $result['message'] )
		);
		FS3D_IO_Logger::flush();

		wp_send_json_success( $result );
	}

	/**
	 * Ricalcola le statistiche.
	 *
	 * @return void
	 */
	public static function refresh_stats() {
		self::guard();

		wp_send_json_success( FS3D_IO_Stats::get( true ) );
	}

	/**
	 * Svuota il log.
	 *
	 * @return void
	 */
	public static function clear_log() {
		self::guard();

		FS3D_IO_Logger::clear();

		wp_send_json_success( array( 'message' => __( 'Log svuotato.', 'fs3d-image-optimizer' ) ) );
	}

	/**
	 * Elenca gli allegati che hanno file generati da rimuovere durante il reset.
	 *
	 * Vengono considerati solo gli allegati tracciati dal plugin: eventuali WebP
	 * caricati a mano nella libreria non vengono mai toccati.
	 *
	 * @return int[]
	 */
	private static function collect_reset_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
				FS3D_IO_META_DATA,
				FS3D_IO_META_STATUS
			)
		);

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Operazioni finali del reset: regole .htaccess, file di test, statistiche.
	 *
	 * @return void
	 */
	private static function finish_reset() {
		FS3D_IO_Htaccess::remove_rules();
		FS3D_IO_Verifier::cleanup_test_files();
		FS3D_IO_Stats::invalidate();

		FS3D_IO_Logger::add( 'info', __( 'Reset completato: file generati eliminati e regole .htaccess rimosse.', 'fs3d-image-optimizer' ) );
		FS3D_IO_Logger::flush();
	}

	/**
	 * Messaggio sintetico per una riga di riepilogo.
	 *
	 * @param array $summary Riepilogo di FS3D_IO_Attachment::optimize().
	 * @return string
	 */
	private static function summary_message( $summary ) {
		if ( 'optimized' === $summary['status'] || 'partial' === $summary['status'] ) {
			return sprintf(
				/* translators: 1: file generati, 2: spazio risparmiato. */
				__( '%1$d file, %2$s risparmiati', 'fs3d-image-optimizer' ),
				count( $summary['files'] ),
				size_format( $summary['saved'] )
			);
		}

		if ( '' !== $summary['message'] ) {
			return $summary['message'];
		}

		return 'skipped' === $summary['status']
			? __( 'Nessuna conversione necessaria', 'fs3d-image-optimizer' )
			: __( 'Conversione non riuscita', 'fs3d-image-optimizer' );
	}
}
