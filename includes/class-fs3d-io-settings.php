<?php
/**
 * Gestione delle impostazioni del plugin.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contenitore delle impostazioni, con sanitizzazione centralizzata.
 */
class FS3D_IO_Settings {

	/**
	 * Cache in memoria delle impostazioni gia' sanitizzate.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Valori di default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Conversione.
			'format'          => 'webp',   // webp | avif | both.
			'quality_webp'    => 80,
			'quality_avif'    => 55,
			'strip_metadata'  => 1,
			'engine'          => 'auto',   // auto | imagick | gd.
			'naming'          => 'suffix', // suffix (foto.jpg.webp) | replace (foto.webp).
			'skip_if_larger'  => 1,
			'min_saving'      => 3,        // percentuale minima di risparmio per tenere il file.

			// Automazione.
			'auto_optimize'   => 1,
			'process_sizes'   => 'all',    // original | all.

			// Batch / avanzate.
			'batch_size'      => 8,
			'exclusions'      => array(),
			'time_budget'     => 0,        // 0 = calcolato da max_execution_time.
		);
	}

	/**
	 * Crea le option di default se mancanti.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		$existing = get_option( FS3D_IO_OPT_SETTINGS, null );

		if ( ! is_array( $existing ) ) {
			add_option( FS3D_IO_OPT_SETTINGS, self::defaults(), '', false );
		}
	}

	/**
	 * Restituisce tutte le impostazioni sanitizzate.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored     = get_option( FS3D_IO_OPT_SETTINGS, array() );
			$stored     = is_array( $stored ) ? $stored : array();
			self::$cache = self::sanitize( array_merge( self::defaults(), $stored ) );
		}

		return self::$cache;
	}

	/**
	 * Restituisce una singola impostazione.
	 *
	 * @param string $key     Chiave.
	 * @param mixed  $default Valore di fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Salva le impostazioni (merge con quelle esistenti).
	 *
	 * @param array $input Valori grezzi.
	 * @return array Impostazioni salvate.
	 */
	public static function save( array $input ) {
		$merged = self::sanitize( array_merge( self::all(), $input ) );

		update_option( FS3D_IO_OPT_SETTINGS, $merged, false );
		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Svuota la cache interna (utile nei test e dopo un reset).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitizza un array completo di impostazioni.
	 *
	 * @param array $input Valori grezzi.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$out = self::defaults();

		// Formato di output: deve essere realmente supportato dal server.
		$format = isset( $input['format'] ) ? (string) $input['format'] : 'webp';
		if ( ! in_array( $format, array( 'webp', 'avif', 'both' ), true ) ) {
			$format = 'webp';
		}
		if ( in_array( $format, array( 'avif', 'both' ), true ) && ! FS3D_IO_Server::supports( 'avif' ) ) {
			$format = 'webp';
		}
		$out['format'] = $format;

		$out['quality_webp']   = self::clamp_int( isset( $input['quality_webp'] ) ? $input['quality_webp'] : 80, 40, 100, 80 );
		$out['quality_avif']   = self::clamp_int( isset( $input['quality_avif'] ) ? $input['quality_avif'] : 55, 30, 100, 55 );
		$out['strip_metadata'] = empty( $input['strip_metadata'] ) ? 0 : 1;
		$out['skip_if_larger'] = empty( $input['skip_if_larger'] ) ? 0 : 1;
		$out['auto_optimize']  = empty( $input['auto_optimize'] ) ? 0 : 1;
		$out['min_saving']     = self::clamp_int( isset( $input['min_saving'] ) ? $input['min_saving'] : 3, 0, 90, 3 );
		$out['batch_size']     = self::clamp_int( isset( $input['batch_size'] ) ? $input['batch_size'] : 8, 1, 50, 8 );
		$out['time_budget']    = self::clamp_int( isset( $input['time_budget'] ) ? $input['time_budget'] : 0, 0, 300, 0 );

		$engine = isset( $input['engine'] ) ? (string) $input['engine'] : 'auto';
		$out['engine'] = in_array( $engine, array( 'auto', 'imagick', 'gd' ), true ) ? $engine : 'auto';

		$naming = isset( $input['naming'] ) ? (string) $input['naming'] : 'suffix';
		$out['naming'] = in_array( $naming, array( 'suffix', 'replace' ), true ) ? $naming : 'suffix';

		$sizes = isset( $input['process_sizes'] ) ? (string) $input['process_sizes'] : 'all';
		$out['process_sizes'] = in_array( $sizes, array( 'original', 'all' ), true ) ? $sizes : 'all';

		$out['exclusions'] = self::sanitize_exclusions( isset( $input['exclusions'] ) ? $input['exclusions'] : array() );

		return $out;
	}

	/**
	 * Normalizza la lista di esclusioni: una riga per pattern, relativa alla cartella uploads.
	 *
	 * @param array|string $value Valore grezzo (textarea o array).
	 * @return array
	 */
	public static function sanitize_exclusions( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $line ) {
			$line = trim( (string) $line );
			$line = str_replace( '\\', '/', $line );
			$line = ltrim( $line, '/' );

			// Nessun path traversal nei pattern.
			if ( '' === $line || false !== strpos( $line, '..' ) ) {
				continue;
			}

			$out[] = $line;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Limita un intero in un intervallo.
	 *
	 * @param mixed $value   Valore.
	 * @param int   $min     Minimo.
	 * @param int   $max     Massimo.
	 * @param int   $default Fallback se non numerico.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $default ) {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}

		return (int) max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Formati di output effettivamente da generare, in ordine di priorita'.
	 *
	 * @return string[]
	 */
	public static function output_formats() {
		$format = self::get( 'format', 'webp' );

		if ( 'both' === $format ) {
			return array( 'avif', 'webp' );
		}

		return array( $format );
	}

	/**
	 * Qualita' configurata per un formato.
	 *
	 * @param string $format webp|avif.
	 * @return int
	 */
	public static function quality_for( $format ) {
		return 'avif' === $format ? (int) self::get( 'quality_avif', 55 ) : (int) self::get( 'quality_webp', 80 );
	}

	/**
	 * Budget di tempo per un singolo batch AJAX, in secondi.
	 *
	 * @return int
	 */
	public static function batch_time_budget() {
		$configured = (int) self::get( 'time_budget', 0 );

		if ( $configured > 0 ) {
			return $configured;
		}

		$max = FS3D_IO_Server::max_execution_time();

		// 0 = illimitato: restiamo comunque prudenti sotto il timeout del web server.
		if ( $max <= 0 ) {
			return 25;
		}

		return (int) max( 5, min( 45, floor( $max * 0.6 ) ) );
	}
}
