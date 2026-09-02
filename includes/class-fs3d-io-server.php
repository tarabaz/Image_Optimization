<?php
/**
 * Rilevamento delle capacita' del server (PHP, GD, Imagick, limiti).
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Introspezione dell'ambiente di esecuzione.
 */
class FS3D_IO_Server {

	/**
	 * Cache del report.
	 *
	 * @var array|null
	 */
	private static $report = null;

	/**
	 * Report completo delle capacita' del server.
	 *
	 * @return array
	 */
	public static function report() {
		if ( null !== self::$report ) {
			return self::$report;
		}

		$gd      = self::gd_info();
		$imagick = self::imagick_info();

		self::$report = array(
			'php_version'        => PHP_VERSION,
			'php_ok'             => version_compare( PHP_VERSION, '7.4', '>=' ),
			'gd'                 => $gd,
			'imagick'            => $imagick,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'memory_limit_bytes' => self::memory_limit_bytes(),
			'max_execution_time' => self::max_execution_time(),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'wp_memory_limit'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
			'mod_rewrite'        => self::has_apache_module( 'mod_rewrite' ),
			'mod_headers'        => self::has_apache_module( 'mod_headers' ),
			'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'is_apache'          => self::is_apache(),
			'suggested_batch'    => self::suggested_batch_size(),
		);

		return self::$report;
	}

	/**
	 * Informazioni su GD.
	 *
	 * @return array
	 */
	public static function gd_info() {
		$out = array(
			'available' => false,
			'version'   => '',
			'webp'      => false,
			'avif'      => false,
			'jpeg'      => false,
			'png'       => false,
		);

		if ( ! function_exists( 'gd_info' ) ) {
			return $out;
		}

		$info = gd_info();

		$out['available'] = true;
		$out['version']   = isset( $info['GD Version'] ) ? $info['GD Version'] : '';
		$out['jpeg']      = ! empty( $info['JPEG Support'] ) && function_exists( 'imagecreatefromjpeg' );
		$out['png']       = ! empty( $info['PNG Support'] ) && function_exists( 'imagecreatefrompng' );
		$out['webp']      = ! empty( $info['WebP Support'] ) && function_exists( 'imagewebp' );
		$out['avif']      = ! empty( $info['AVIF Support'] ) && function_exists( 'imageavif' );

		return $out;
	}

	/**
	 * Informazioni su Imagick.
	 *
	 * @return array
	 */
	public static function imagick_info() {
		$out = array(
			'available' => false,
			'version'   => '',
			'webp'      => false,
			'avif'      => false,
			'jpeg'      => false,
			'png'       => false,
		);

		if ( ! class_exists( 'Imagick' ) ) {
			return $out;
		}

		$out['available'] = true;

		try {
			$version = Imagick::getVersion();
			$out['version'] = isset( $version['versionString'] ) ? $version['versionString'] : '';

			$formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );

			$out['webp'] = in_array( 'WEBP', $formats, true );
			$out['avif'] = in_array( 'AVIF', $formats, true );
			$out['jpeg'] = in_array( 'JPEG', $formats, true );
			$out['png']  = in_array( 'PNG', $formats, true );
		} catch ( Exception $e ) {
			$out['version'] = 'errore: ' . $e->getMessage();
		}

		return $out;
	}

	/**
	 * Verifica se un formato di output e' supportato da almeno un motore utilizzabile.
	 *
	 * @param string $format webp|avif.
	 * @return bool
	 */
	public static function supports( $format ) {
		$format = strtolower( $format );

		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return false;
		}

		$gd      = self::gd_info();
		$imagick = self::imagick_info();

		return ( ! empty( $gd['available'] ) && ! empty( $gd[ $format ] ) )
			|| ( ! empty( $imagick['available'] ) && ! empty( $imagick[ $format ] ) );
	}

	/**
	 * Motore da usare per un dato formato, rispettando la preferenza salvata.
	 *
	 * @param string $format webp|avif.
	 * @return string imagick|gd|'' se nessuno disponibile.
	 */
	public static function engine_for( $format ) {
		$preferred = FS3D_IO_Settings::get( 'engine', 'auto' );
		$gd        = self::gd_info();
		$imagick   = self::imagick_info();

		$imagick_ok = ! empty( $imagick['available'] ) && ! empty( $imagick[ $format ] );
		$gd_ok      = ! empty( $gd['available'] ) && ! empty( $gd[ $format ] );

		if ( 'imagick' === $preferred ) {
			return $imagick_ok ? 'imagick' : '';
		}

		if ( 'gd' === $preferred ) {
			return $gd_ok ? 'gd' : '';
		}

		// Auto: Imagick per primo, gestisce meglio profili colore e metadata.
		if ( $imagick_ok ) {
			return 'imagick';
		}

		return $gd_ok ? 'gd' : '';
	}

	/**
	 * max_execution_time come intero (0 = illimitato).
	 *
	 * @return int
	 */
	public static function max_execution_time() {
		$value = ini_get( 'max_execution_time' );

		return ( false === $value || '' === $value ) ? 30 : (int) $value;
	}

	/**
	 * memory_limit in byte (-1 = illimitato).
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );

		if ( false === $raw || '' === $raw ) {
			return -1;
		}

		return self::parse_size( $raw );
	}

	/**
	 * Converte una dimensione stile ini (128M) in byte.
	 *
	 * @param string $size Valore ini.
	 * @return int
	 */
	public static function parse_size( $size ) {
		$size = trim( (string) $size );

		if ( '' === $size ) {
			return 0;
		}

		if ( '-1' === $size ) {
			return -1;
		}

		$unit  = strtolower( substr( $size, -1 ) );
		$value = (float) $size;

		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}

		return (int) $value;
	}

	/**
	 * Memoria ancora disponibile in byte (PHP_INT_MAX se illimitata).
	 *
	 * @return int
	 */
	public static function memory_available() {
		$limit = self::memory_limit_bytes();

		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}

		$used = memory_get_usage( true );

		return (int) max( 0, $limit - $used );
	}

	/**
	 * Dimensione di batch consigliata in base a memoria e tempo disponibili.
	 *
	 * @return int
	 */
	public static function suggested_batch_size() {
		$limit = self::memory_limit_bytes();
		$time  = self::max_execution_time();

		$by_memory = 10;

		if ( $limit > 0 ) {
			if ( $limit < 96 * 1024 * 1024 ) {
				$by_memory = 3;
			} elseif ( $limit < 192 * 1024 * 1024 ) {
				$by_memory = 5;
			} elseif ( $limit < 320 * 1024 * 1024 ) {
				$by_memory = 8;
			}
		}

		$by_time = 10;

		if ( $time > 0 ) {
			if ( $time < 30 ) {
				$by_time = 3;
			} elseif ( $time < 60 ) {
				$by_time = 6;
			}
		}

		return (int) max( 1, min( $by_memory, $by_time ) );
	}

	/**
	 * Il server gira su Apache?
	 *
	 * @return bool
	 */
	public static function is_apache() {
		if ( ! empty( $GLOBALS['is_apache'] ) ) {
			return true;
		}

		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';

		return ( false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' ) );
	}

	/**
	 * Verifica la presenza di un modulo Apache.
	 *
	 * Su hosting condiviso apache_get_modules() e' spesso disabilitato: in quel caso
	 * restituiamo null (sconosciuto) invece di un falso negativo.
	 *
	 * @param string $module Nome del modulo.
	 * @return bool|null
	 */
	public static function has_apache_module( $module ) {
		if ( ! function_exists( 'apache_get_modules' ) ) {
			return null;
		}

		$modules = apache_get_modules();

		if ( ! is_array( $modules ) || empty( $modules ) ) {
			return null;
		}

		return in_array( $module, $modules, true );
	}

	/**
	 * Stima la memoria necessaria per aprire un'immagine con GD.
	 *
	 * @param string $file Percorso assoluto.
	 * @return int Byte stimati (0 se non determinabile).
	 */
	public static function estimate_memory_for( $file ) {
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return 0;
		}

		$bits     = isset( $info['bits'] ) ? (int) $info['bits'] : 8;
		$channels = isset( $info['channels'] ) ? (int) $info['channels'] : 4;
		$channels = $channels > 0 ? $channels : 4;

		// Formula classica: pixel * bit/8 * canali, con margine di sicurezza.
		$bytes = $info[0] * $info[1] * ( $bits / 8 ) * $channels * 1.8;

		return (int) ( $bytes + 2 * 1024 * 1024 );
	}
}
