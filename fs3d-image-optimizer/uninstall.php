<?php
/**
 * Disinstallazione del plugin.
 *
 * Rimuove impostazioni, statistiche, log e metadati creati dal plugin, e toglie
 * il blocco di regole dal .htaccess. I file WebP/AVIF gia' generati NON vengono
 * eliminati: per farlo usa "Reset completo" dalle impostazioni prima di
 * disinstallare.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$fs3d_io_options = array(
	'fs3d_io_settings',
	'fs3d_io_stats',
	'fs3d_io_log',
	'fs3d_io_queue',
	'fs3d_io_queue_meta',
);

foreach ( $fs3d_io_options as $fs3d_io_option ) {
	delete_option( $fs3d_io_option );
}

delete_transient( 'fs3d_io_stats_cache' );

delete_post_meta_by_key( '_fs3d_io_data' );
delete_post_meta_by_key( '_fs3d_io_status' );

// Rimozione del blocco di regole dal .htaccess della cartella uploads.
$fs3d_io_uploads = wp_get_upload_dir();

if ( ! empty( $fs3d_io_uploads['basedir'] ) ) {
	$fs3d_io_htaccess = untrailingslashit( $fs3d_io_uploads['basedir'] ) . '/.htaccess';

	if ( file_exists( $fs3d_io_htaccess ) && is_writable( $fs3d_io_htaccess ) ) {
		$fs3d_io_content = file_get_contents( $fs3d_io_htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false !== $fs3d_io_content && false !== strpos( $fs3d_io_content, '# BEGIN FS3D Image Optimizer' ) ) {
			$fs3d_io_clean = preg_replace(
				'/\n*# BEGIN FS3D Image Optimizer.*?# END FS3D Image Optimizer\n*/s',
				"\n",
				$fs3d_io_content
			);

			$fs3d_io_clean = ltrim( (string) $fs3d_io_clean, "\n" );

			if ( '' === trim( $fs3d_io_clean ) ) {
				unlink( $fs3d_io_htaccess );
			} else {
				file_put_contents( $fs3d_io_htaccess, $fs3d_io_clean, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}
}
