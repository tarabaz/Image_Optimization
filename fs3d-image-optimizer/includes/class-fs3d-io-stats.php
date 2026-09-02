<?php
/**
 * Statistiche aggregate sull'ottimizzazione.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calcolo e cache delle statistiche.
 */
class FS3D_IO_Stats {

	/**
	 * Chiave del transient di cache.
	 */
	const CACHE_KEY = 'fs3d_io_stats_cache';

	/**
	 * Durata della cache in secondi.
	 */
	const CACHE_TTL = 900;

	/**
	 * Crea le option di default.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		if ( false === get_option( FS3D_IO_OPT_STATS, false ) ) {
			add_option( FS3D_IO_OPT_STATS, array(), '', false );
		}
	}

	/**
	 * Invalida la cache.
	 *
	 * @return void
	 */
	public static function invalidate() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Restituisce le statistiche, usando la cache quando possibile.
	 *
	 * @param bool $fresh Forza il ricalcolo.
	 * @return array
	 */
	public static function get( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$stats = self::calculate();

		set_transient( self::CACHE_KEY, $stats, self::CACHE_TTL );

		return $stats;
	}

	/**
	 * Calcola le statistiche leggendo il database.
	 *
	 * @return array
	 */
	public static function calculate() {
		global $wpdb;

		$mimes        = FS3D_IO_Converter::SOURCE_MIMES;
		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_images = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ( {$placeholders} )",
				$mimes
			)
		);

		$status_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
				FS3D_IO_META_STATUS
			),
			ARRAY_A
		);

		$data_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				FS3D_IO_META_DATA
			)
		);
		// phpcs:enable

		$by_status = array(
			'optimized' => 0,
			'partial'   => 0,
			'failed'    => 0,
			'skipped'   => 0,
		);

		foreach ( (array) $status_rows as $row ) {
			$key = isset( $row['status'] ) ? (string) $row['status'] : '';

			if ( isset( $by_status[ $key ] ) ) {
				$by_status[ $key ] = (int) $row['total'];
			}
		}

		$src_bytes = 0;
		$dst_bytes = 0;
		$files     = 0;

		foreach ( (array) $data_rows as $raw ) {
			$data = maybe_unserialize( $raw );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$src_bytes += isset( $data['src_bytes'] ) ? (int) $data['src_bytes'] : 0;
			$dst_bytes += isset( $data['dst_bytes'] ) ? (int) $data['dst_bytes'] : 0;
			$files     += ! empty( $data['files'] ) ? count( $data['files'] ) : 0;
		}

		$optimized = $by_status['optimized'] + $by_status['partial'];
		$saved     = (int) max( 0, $src_bytes - $dst_bytes );

		return array(
			'total_images'    => $total_images,
			'optimized'       => $optimized,
			'pending'         => (int) max( 0, $total_images - $optimized - $by_status['skipped'] - $by_status['failed'] ),
			'failed'          => $by_status['failed'],
			'skipped'         => $by_status['skipped'],
			'generated_files' => $files,
			'src_bytes'       => $src_bytes,
			'dst_bytes'       => $dst_bytes,
			'saved_bytes'     => $saved,
			'saved_percent'   => $src_bytes > 0 ? round( ( $saved / $src_bytes ) * 100, 1 ) : 0,
			'coverage'        => $total_images > 0 ? round( ( $optimized / $total_images ) * 100, 1 ) : 0,
			'updated'         => time(),
		);
	}
}
