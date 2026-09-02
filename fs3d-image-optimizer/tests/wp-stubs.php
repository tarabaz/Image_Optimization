<?php
/**
 * Stub minimi delle funzioni WordPress usate dalle classi testate.
 *
 * Servono a far girare i test in isolamento, senza caricare WordPress e senza
 * toccare il sito in produzione.
 *
 * @package FS3D_Image_Optimizer
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'FS3D_IO_VERSION', '1.0.0-test' );
define( 'FS3D_IO_OPT_SETTINGS', 'fs3d_io_settings' );
define( 'FS3D_IO_OPT_STATS', 'fs3d_io_stats' );
define( 'FS3D_IO_OPT_LOG', 'fs3d_io_log' );
define( 'FS3D_IO_OPT_QUEUE', 'fs3d_io_queue' );
define( 'FS3D_IO_OPT_QUEUE_META', 'fs3d_io_queue_meta' );
define( 'FS3D_IO_META_DATA', '_fs3d_io_data' );
define( 'FS3D_IO_META_STATUS', '_fs3d_io_status' );

$GLOBALS['fs3d_test_options'] = array();
$GLOBALS['fs3d_test_uploads'] = '';

function fs3d_test_set_uploads( $dir ) {
	$GLOBALS['fs3d_test_uploads'] = rtrim( $dir, '/' );
}

function wp_get_upload_dir() {
	return array(
		'basedir' => $GLOBALS['fs3d_test_uploads'],
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

function wp_normalize_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = preg_replace( '|(?<=.)/+|', '/', $path );

	if ( ':' === substr( $path, 1, 1 ) ) {
		$path = ucfirst( $path );
	}

	return $path;
}

function trailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' ) . '/';
}

function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}

function wp_is_writable( $path ) {
	return is_writable( $path );
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$out   = '';

	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}

	return $out;
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max > 0 ? $max : PHP_INT_MAX );
}

function size_format( $bytes, $decimals = 0 ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$bytes = (float) $bytes;
	$i     = 0;

	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}

	return round( $bytes, $decimals ) . ' ' . $units[ $i ];
}

function __( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['fs3d_test_options'] )
		? $GLOBALS['fs3d_test_options'][ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['fs3d_test_options'][ $name ] = $value;

	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['fs3d_test_options'] ) ) {
		return false;
	}

	return update_option( $name, $value );
}

function delete_option( $name ) {
	unset( $GLOBALS['fs3d_test_options'][ $name ] );

	return true;
}

function get_transient( $name ) {
	return get_option( '_transient_' . $name, false );
}

function set_transient( $name, $value, $ttl = 0 ) {
	return update_option( '_transient_' . $name, $value );
}

function delete_transient( $name ) {
	return delete_option( '_transient_' . $name );
}
