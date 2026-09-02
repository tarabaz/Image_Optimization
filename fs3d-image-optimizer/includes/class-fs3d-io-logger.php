<?php
/**
 * Log delle operazioni (ring buffer su option, nessuna tabella custom).
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registro delle ultime operazioni eseguite.
 */
class FS3D_IO_Logger {

	/**
	 * Numero massimo di voci conservate.
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Buffer delle voci non ancora scritte su DB.
	 *
	 * @var array
	 */
	private static $buffer = array();

	/**
	 * Aggiunge una voce al buffer.
	 *
	 * @param string $level   info|success|warning|error.
	 * @param string $message Messaggio leggibile.
	 * @param array  $context Dati extra (file, attachment_id, bytes...).
	 * @return void
	 */
	public static function add( $level, $message, $context = array() ) {
		self::$buffer[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => (string) $message,
			'file'    => isset( $context['file'] ) ? (string) $context['file'] : '',
			'id'      => isset( $context['attachment_id'] ) ? (int) $context['attachment_id'] : 0,
			'saved'   => isset( $context['saved'] ) ? (int) $context['saved'] : 0,
		);
	}

	/**
	 * Scrive il buffer su DB. Da chiamare una volta a fine batch/operazione.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$buffer ) ) {
			return;
		}

		$entries = get_option( FS3D_IO_OPT_LOG, array() );
		$entries = is_array( $entries ) ? $entries : array();

		// Le voci piu' recenti stanno in testa.
		$entries = array_merge( array_reverse( self::$buffer ), $entries );

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		update_option( FS3D_IO_OPT_LOG, $entries, false );

		self::$buffer = array();
	}

	/**
	 * Restituisce le ultime voci registrate.
	 *
	 * @param int $limit Numero massimo di voci.
	 * @return array
	 */
	public static function get( $limit = 50 ) {
		$entries = get_option( FS3D_IO_OPT_LOG, array() );
		$entries = is_array( $entries ) ? $entries : array();

		return array_slice( $entries, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Svuota il log.
	 *
	 * @return void
	 */
	public static function clear() {
		self::$buffer = array();
		delete_option( FS3D_IO_OPT_LOG );
	}
}
