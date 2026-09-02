<?php
/**
 * Verifica reale della content negotiation: richiesta HTTP con header Accept
 * e controllo del Content-Type restituito da Apache.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Test end-to-end delle regole .htaccess.
 */
class FS3D_IO_Verifier {

	/**
	 * Nome del file di test generato nella root di uploads.
	 */
	const TEST_BASENAME = 'fs3d-io-selftest.png';

	/**
	 * Esegue la verifica completa.
	 *
	 * @return array {
	 *     @type bool   $success Il webp viene realmente servito.
	 *     @type string $message Riepilogo leggibile.
	 *     @type array  $steps   Dettaglio dei singoli controlli.
	 * }
	 */
	public static function run() {
		$steps = array();

		if ( ! FS3D_IO_Htaccess::has_rules() ) {
			return array(
				'success' => false,
				'message' => __( 'Le regole .htaccess non sono attive: attivale prima di verificare.', 'fs3d-image-optimizer' ),
				'steps'   => $steps,
			);
		}

		$target = self::resolve_test_target();

		if ( is_wp_error( $target ) ) {
			return array(
				'success' => false,
				'message' => $target->get_error_message(),
				'steps'   => $steps,
			);
		}

		$steps[] = array(
			'label'  => __( 'File di test', 'fs3d-image-optimizer' ),
			'ok'     => true,
			'detail' => $target['label'],
		);

		$format = $target['format'];

		// 1. Browser che dichiara di supportare il formato moderno.
		$modern = self::request( $target['url'], 'image/' . $format . ',image/*,*/*;q=0.8' );

		if ( is_wp_error( $modern ) ) {
			$steps[] = array(
				'label'  => __( 'Richiesta con Accept moderno', 'fs3d-image-optimizer' ),
				'ok'     => false,
				'detail' => $modern->get_error_message(),
			);

			return array(
				'success' => false,
				'message' => __( 'Il sito non riesce a chiamare se stesso (loopback bloccato dall\'hosting). Verifica aprendo l\'immagine dal browser e controllando il Content-Type nella scheda Rete.', 'fs3d-image-optimizer' ),
				'steps'   => $steps,
			);
		}

		$modern_ok = ( 'image/' . $format === $modern['type'] );

		$steps[] = array(
			'label'  => sprintf( /* translators: %s: formato. */ __( 'Accept: image/%s', 'fs3d-image-optimizer' ), $format ),
			'ok'     => $modern_ok,
			'detail' => sprintf(
				/* translators: 1: content-type ricevuto, 2: codice HTTP, 3: header diagnostico. */
				__( 'Content-Type: %1$s | HTTP %2$d | X-FS3D-IO: %3$s', 'fs3d-image-optimizer' ),
				$modern['type'] ? $modern['type'] : '-',
				$modern['code'],
				$modern['marker'] ? $modern['marker'] : '-'
			),
		);

		// 2. Browser che NON supporta il formato moderno: deve ricevere l'originale.
		$legacy    = self::request( $target['url'], 'image/png,image/jpeg,*/*;q=0.5' );
		$legacy_ok = false;

		if ( is_wp_error( $legacy ) ) {
			$steps[] = array(
				'label'  => __( 'Richiesta con Accept legacy', 'fs3d-image-optimizer' ),
				'ok'     => false,
				'detail' => $legacy->get_error_message(),
			);
		} else {
			$legacy_ok = in_array( $legacy['type'], array( 'image/png', 'image/jpeg' ), true );

			$steps[] = array(
				'label'  => __( 'Accept legacy (browser senza WebP)', 'fs3d-image-optimizer' ),
				'ok'     => $legacy_ok,
				'detail' => sprintf(
					/* translators: 1: content-type ricevuto, 2: codice HTTP. */
					__( 'Content-Type: %1$s | HTTP %2$d', 'fs3d-image-optimizer' ),
					$legacy['type'] ? $legacy['type'] : '-',
					$legacy['code']
				),
			);
		}

		// 3. Header Vary: necessario per non far cachare la risposta sbagliata a proxy e CDN.
		$vary_ok = ! is_wp_error( $modern ) && false !== stripos( $modern['vary'], 'accept' );

		$steps[] = array(
			'label'  => __( 'Header Vary: Accept', 'fs3d-image-optimizer' ),
			'ok'     => $vary_ok,
			'detail' => $vary_ok
				? __( 'Presente.', 'fs3d-image-optimizer' )
				: __( 'Assente: senza mod_headers un proxy potrebbe servire il file sbagliato.', 'fs3d-image-optimizer' ),
		);

		$success = $modern_ok && $legacy_ok;

		if ( $success ) {
			$message = sprintf(
				/* translators: %s: formato servito. */
				__( 'Tutto a posto: il server serve %s ai browser compatibili e l\'originale agli altri, senza modificare gli URL.', 'fs3d-image-optimizer' ),
				strtoupper( $format )
			);
		} elseif ( ! $modern_ok ) {
			$message = __( 'Le regole non stanno funzionando: il server restituisce ancora il file originale anche dichiarando il supporto WebP. Su Aruba controlla che mod_rewrite sia attivo e che AllowOverride consenta le regole nella cartella uploads.', 'fs3d-image-optimizer' );
		} else {
			$message = __( 'Attenzione: il formato moderno viene servito, ma i browser senza supporto non ricevono l\'originale. Disattiva le regole e ricontrolla il .htaccess.', 'fs3d-image-optimizer' );
		}

		return array(
			'success' => $success,
			'message' => $message,
			'steps'   => $steps,
		);
	}

	/**
	 * Individua un'immagine su cui testare: prima una gia' ottimizzata, altrimenti
	 * ne genera una di servizio.
	 *
	 * @return array|WP_Error array con url, format, label.
	 */
	private static function resolve_test_target() {
		$formats = FS3D_IO_Settings::output_formats();
		$format  = in_array( 'webp', $formats, true ) ? 'webp' : reset( $formats );

		$existing = self::find_optimized_url( $format );

		if ( $existing ) {
			return array(
				'url'    => $existing['url'],
				'format' => $format,
				'label'  => $existing['name'],
			);
		}

		$generated = self::ensure_test_image( $format );

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		return $generated;
	}

	/**
	 * Cerca un allegato gia' ottimizzato di cui esista il file convertito.
	 *
	 * @param string $format webp|avif.
	 * @return array|false array con url e name.
	 */
	private static function find_optimized_url( $format ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => FS3D_IO_META_STATUS,
						'value'   => array( 'optimized', 'partial' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );

			if ( ! $file || ! file_exists( $file ) ) {
				continue;
			}

			$dest = FS3D_IO_Converter::destination_path( $file, $format );

			if ( file_exists( $dest ) ) {
				return array(
					'url'  => wp_get_attachment_url( $id ),
					'name' => basename( $file ),
				);
			}
		}

		return false;
	}

	/**
	 * Genera (se serve) una coppia PNG + formato moderno da usare come test.
	 *
	 * @param string $format webp|avif.
	 * @return array|WP_Error
	 */
	private static function ensure_test_image( $format ) {
		$basedir = FS3D_IO_Converter::uploads_basedir();
		$dirs    = wp_get_upload_dir();
		$source  = $basedir . '/' . self::TEST_BASENAME;
		$dest    = FS3D_IO_Converter::destination_path( $source, $format );

		if ( ! file_exists( $source ) && ! self::create_test_png( $source ) ) {
			return new WP_Error( 'fs3d_io_test_image', __( 'Impossibile creare l\'immagine di test nella cartella uploads.', 'fs3d-image-optimizer' ) );
		}

		if ( ! file_exists( $dest ) ) {
			// L'immagine di servizio viene convertita ignorando la soglia di risparmio:
			// serve solo a provare le regole, non deve rispettare i criteri di convenienza.
			$result = FS3D_IO_Converter::convert( $source, $format, true, true );

			if ( 'converted' !== $result['status'] ) {
				return new WP_Error( 'fs3d_io_test_convert', __( 'Impossibile generare la versione convertita dell\'immagine di test.', 'fs3d-image-optimizer' ) );
			}
		}

		return array(
			'url'    => trailingslashit( $dirs['baseurl'] ) . self::TEST_BASENAME,
			'format' => $format,
			'label'  => self::TEST_BASENAME . ' ' . __( '(immagine di servizio generata dal plugin)', 'fs3d-image-optimizer' ),
		);
	}

	/**
	 * Crea un PNG di test con un gradiente, comprimibile molto bene in WebP.
	 *
	 * @param string $path Percorso di destinazione.
	 * @return bool
	 */
	private static function create_test_png( $path ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return false;
		}

		$width  = 600;
		$height = 400;
		$image  = imagecreatetruecolor( $width, $height );

		if ( ! $image ) {
			return false;
		}

		for ( $x = 0; $x < $width; $x++ ) {
			for ( $y = 0; $y < $height; $y++ ) {
				$r = (int) ( 255 * ( $x / $width ) );
				$g = (int) ( 255 * ( $y / $height ) );
				$b = (int) ( 128 + 127 * sin( ( $x + $y ) / 40 ) );

				imagesetpixel( $image, $x, $y, imagecolorallocate( $image, $r, $g, max( 0, min( 255, $b ) ) ) );
			}
		}

		$ok = imagepng( $image, $path, 6 );
		imagedestroy( $image );

		return (bool) $ok;
	}

	/**
	 * Esegue una richiesta HTTP con un header Accept specifico.
	 *
	 * @param string $url    URL da richiedere.
	 * @param string $accept Valore dell'header Accept.
	 * @return array|WP_Error
	 */
	private static function request( $url, $accept ) {
		// Cache buster: evita risposte servite da cache intermedie durante il test.
		$url = add_query_arg( 'fs3dio', time() . wp_rand( 100, 999 ), $url );

		$args = array(
			'timeout'     => 15,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'        => $accept,
				'Cache-Control' => 'no-cache',
			),
		);

		$response = wp_remote_get( $url, $args );

		// Su hosting condiviso la verifica del certificato in loopback fallisce spesso:
		// riproviamo senza, e' una richiesta al nostro stesso dominio.
		if ( is_wp_error( $response ) ) {
			$args['sslverify'] = false;
			$response          = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$type = wp_remote_retrieve_header( $response, 'content-type' );
		$type = is_array( $type ) ? reset( $type ) : (string) $type;
		$type = trim( strtolower( strtok( $type, ';' ) ) );

		$vary = wp_remote_retrieve_header( $response, 'vary' );
		$vary = is_array( $vary ) ? implode( ',', $vary ) : (string) $vary;

		$marker = wp_remote_retrieve_header( $response, 'x-fs3d-io' );
		$marker = is_array( $marker ) ? reset( $marker ) : (string) $marker;

		return array(
			'code'   => (int) wp_remote_retrieve_response_code( $response ),
			'type'   => $type,
			'vary'   => $vary,
			'marker' => $marker,
		);
	}

	/**
	 * Elimina i file di test generati.
	 *
	 * @return void
	 */
	public static function cleanup_test_files() {
		$source = FS3D_IO_Converter::uploads_basedir() . '/' . self::TEST_BASENAME;

		foreach ( array( 'webp', 'avif' ) as $format ) {
			FS3D_IO_Attachment::delete_generated_file( FS3D_IO_Converter::destination_path( $source, $format ) );
			FS3D_IO_Attachment::delete_generated_file( $source . '.' . $format );
		}

		if ( file_exists( $source ) ) {
			@unlink( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
