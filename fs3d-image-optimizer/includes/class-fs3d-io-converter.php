<?php
/**
 * Conversione di un singolo file immagine in WebP/AVIF affiancato all'originale.
 *
 * Regola non negoziabile: il file sorgente non viene MAI modificato, rinominato o
 * sovrascritto. Il file convertito viene sempre scritto accanto, con un percorso
 * di destinazione diverso da quello di partenza.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Motore di conversione.
 */
class FS3D_IO_Converter {

	/**
	 * Estensioni sorgente gestite.
	 *
	 * @var string[]
	 */
	const SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png' );

	/**
	 * MIME sorgente gestiti.
	 *
	 * @var string[]
	 */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	/**
	 * Cache del percorso base uploads.
	 *
	 * @var string|null
	 */
	private static $uploads_basedir = null;

	/**
	 * Percorso assoluto normalizzato della cartella uploads.
	 *
	 * @return string
	 */
	public static function uploads_basedir() {
		if ( null === self::$uploads_basedir ) {
			$dirs = wp_get_upload_dir();
			$base = isset( $dirs['basedir'] ) ? $dirs['basedir'] : '';

			self::$uploads_basedir = untrailingslashit( wp_normalize_path( $base ) );
		}

		return self::$uploads_basedir;
	}

	/**
	 * Percorso relativo alla cartella uploads (o stringa vuota se esterno).
	 *
	 * @param string $file Percorso assoluto.
	 * @return string
	 */
	public static function relative_path( $file ) {
		$file = wp_normalize_path( $file );
		$base = self::uploads_basedir();

		if ( '' === $base || 0 !== strpos( $file, $base . '/' ) ) {
			return '';
		}

		return ltrim( substr( $file, strlen( $base ) ), '/' );
	}

	/**
	 * Il file si trova dentro la cartella uploads?
	 *
	 * @param string $file Percorso assoluto.
	 * @return bool
	 */
	public static function is_inside_uploads( $file ) {
		return '' !== self::relative_path( $file );
	}

	/**
	 * Il file e' escluso dalle regole configurate?
	 *
	 * @param string $file Percorso assoluto.
	 * @return bool
	 */
	public static function is_excluded( $file ) {
		$patterns = (array) FS3D_IO_Settings::get( 'exclusions', array() );

		if ( empty( $patterns ) ) {
			return false;
		}

		$relative = self::relative_path( $file );

		if ( '' === $relative ) {
			return true;
		}

		$basename = basename( $relative );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );

			if ( '' === $pattern ) {
				continue;
			}

			if ( self::matches_pattern( $pattern, $relative ) || self::matches_pattern( $pattern, $basename ) ) {
				return true;
			}

			// Un pattern senza wildcard che indica una cartella esclude tutto il suo contenuto.
			if ( false === strpos( $pattern, '*' ) && 0 === strpos( $relative, untrailingslashit( $pattern ) . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Confronto glob-like indipendente da fnmatch() (non sempre disponibile).
	 *
	 * @param string $pattern Pattern con * e ?.
	 * @param string $subject Stringa da confrontare.
	 * @return bool
	 */
	public static function matches_pattern( $pattern, $subject ) {
		if ( function_exists( 'fnmatch' ) ) {
			return fnmatch( $pattern, $subject );
		}

		$regex = '#^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $pattern, '#' ) ) . '$#i';

		return (bool) preg_match( $regex, $subject );
	}

	/**
	 * L'estensione del file e' gestibile?
	 *
	 * @param string $file Percorso o nome file.
	 * @return bool
	 */
	public static function is_supported_source( $file ) {
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		return in_array( $ext, self::SOURCE_EXTENSIONS, true );
	}

	/**
	 * Calcola il percorso di destinazione per un formato.
	 *
	 * @param string $source Percorso assoluto del file sorgente.
	 * @param string $format webp|avif.
	 * @return string Percorso assoluto (mai uguale al sorgente).
	 */
	public static function destination_path( $source, $format ) {
		$source = wp_normalize_path( $source );
		$format = strtolower( $format );

		if ( 'replace' === FS3D_IO_Settings::get( 'naming', 'suffix' ) ) {
			$dir  = dirname( $source );
			$name = pathinfo( $source, PATHINFO_FILENAME );

			return $dir . '/' . $name . '.' . $format;
		}

		return $source . '.' . $format;
	}

	/**
	 * Con la modalita' "replace" due sorgenti diversi (foto.jpg e foto.png) puntano
	 * allo stesso file di destinazione: in quel caso non convertiamo, per non servire
	 * l'immagine sbagliata.
	 *
	 * @param string $source Percorso assoluto del sorgente.
	 * @return bool
	 */
	public static function has_name_collision( $source ) {
		if ( 'replace' !== FS3D_IO_Settings::get( 'naming', 'suffix' ) ) {
			return false;
		}

		$source = wp_normalize_path( $source );
		$dir    = dirname( $source );
		$name   = pathinfo( $source, PATHINFO_FILENAME );

		foreach ( self::SOURCE_EXTENSIONS as $ext ) {
			foreach ( array( $ext, strtoupper( $ext ) ) as $variant ) {
				$candidate = $dir . '/' . $name . '.' . $variant;

				if ( $candidate !== $source && file_exists( $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Converte un file. Non tocca mai il sorgente.
	 *
	 * @param string $source      Percorso assoluto del file sorgente.
	 * @param string $format      webp|avif.
	 * @param bool   $force       Riconverte anche se la destinazione esiste gia'.
	 * @param bool   $ignore_gain Ignora la soglia di risparmio minimo (usato dall'autodiagnosi).
	 * @return array {
	 *     @type string $status    converted|skipped|failed.
	 *     @type string $reason    Codice motivo quando skipped/failed.
	 *     @type string $message   Messaggio leggibile.
	 *     @type string $source    Percorso sorgente.
	 *     @type string $dest      Percorso destinazione.
	 *     @type int    $src_bytes Dimensione sorgente.
	 *     @type int    $dst_bytes Dimensione destinazione.
	 * }
	 */
	public static function convert( $source, $format, $force = false, $ignore_gain = false ) {
		$source = wp_normalize_path( $source );
		$format = strtolower( $format );

		$result = array(
			'status'    => 'failed',
			'reason'    => '',
			'message'   => '',
			'source'    => $source,
			'dest'      => '',
			'src_bytes' => 0,
			'dst_bytes' => 0,
		);

		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			$result['reason']  = 'bad_format';
			$result['message'] = __( 'Formato di output non valido.', 'fs3d-image-optimizer' );

			return $result;
		}

		if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
			$result['reason']  = 'missing_source';
			$result['message'] = __( 'File sorgente mancante o non leggibile.', 'fs3d-image-optimizer' );

			return $result;
		}

		if ( ! self::is_inside_uploads( $source ) ) {
			$result['reason']  = 'outside_uploads';
			$result['message'] = __( 'Il file e\' fuori dalla cartella uploads.', 'fs3d-image-optimizer' );

			return $result;
		}

		if ( ! self::is_supported_source( $source ) ) {
			$result['reason']  = 'unsupported_type';
			$result['message'] = __( 'Tipo di file non gestito (solo JPG e PNG).', 'fs3d-image-optimizer' );

			return $result;
		}

		if ( self::is_excluded( $source ) ) {
			$result['status']  = 'skipped';
			$result['reason']  = 'excluded';
			$result['message'] = __( 'File escluso dalle regole configurate.', 'fs3d-image-optimizer' );

			return $result;
		}

		if ( self::has_name_collision( $source ) ) {
			$result['status']  = 'skipped';
			$result['reason']  = 'name_collision';
			$result['message'] = __( 'Esiste un altro file con lo stesso nome base: conversione saltata per non servire l\'immagine sbagliata.', 'fs3d-image-optimizer' );

			return $result;
		}

		$dest           = self::destination_path( $source, $format );
		$result['dest'] = $dest;

		// Guardia assoluta: la destinazione non puo' mai coincidere con il sorgente.
		if ( wp_normalize_path( $dest ) === $source ) {
			$result['reason']  = 'dest_equals_source';
			$result['message'] = __( 'Percorso di destinazione uguale all\'originale: conversione annullata.', 'fs3d-image-optimizer' );

			return $result;
		}

		$src_bytes           = (int) filesize( $source );
		$result['src_bytes'] = $src_bytes;

		if ( ! $force && file_exists( $dest ) && filemtime( $dest ) >= filemtime( $source ) ) {
			$result['status']    = 'skipped';
			$result['reason']    = 'already_exists';
			$result['dst_bytes'] = (int) filesize( $dest );
			$result['message']   = __( 'Versione ottimizzata gia\' presente e aggiornata.', 'fs3d-image-optimizer' );

			return $result;
		}

		$dir = dirname( $dest );

		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			$result['reason']  = 'dir_not_writable';
			$result['message'] = __( 'Cartella di destinazione non scrivibile.', 'fs3d-image-optimizer' );

			return $result;
		}

		$engine = FS3D_IO_Server::engine_for( $format );

		if ( '' === $engine ) {
			$result['reason']  = 'no_engine';
			/* translators: %s: formato di output. */
			$result['message'] = sprintf( __( 'Nessuna libreria disponibile per generare %s su questo server.', 'fs3d-image-optimizer' ), strtoupper( $format ) );

			return $result;
		}

		// Su GD l'immagine viene decompressa in memoria: verifichiamo di poterla reggere.
		if ( 'gd' === $engine ) {
			$needed    = FS3D_IO_Server::estimate_memory_for( $source );
			$available = FS3D_IO_Server::memory_available();

			if ( $needed > 0 && $needed > $available ) {
				$result['status']  = 'skipped';
				$result['reason']  = 'not_enough_memory';
				$result['message'] = sprintf(
					/* translators: 1: memoria necessaria, 2: memoria disponibile. */
					__( 'Immagine troppo grande per la memoria disponibile (servono ~%1$s, liberi ~%2$s).', 'fs3d-image-optimizer' ),
					size_format( $needed ),
					size_format( $available )
				);

				return $result;
			}
		}

		// Scrittura su file temporaneo: se qualcosa va storto non lasciamo file parziali.
		$tmp = $dest . '.fs3dtmp';

		if ( file_exists( $tmp ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$quality = FS3D_IO_Settings::quality_for( $format );
		$strip   = (bool) FS3D_IO_Settings::get( 'strip_metadata', 1 );

		try {
			if ( 'imagick' === $engine ) {
				$ok = self::convert_with_imagick( $source, $tmp, $format, $quality, $strip );
			} else {
				$ok = self::convert_with_gd( $source, $tmp, $format, $quality );
			}
		} catch ( Exception $e ) {
			$ok                = false;
			$result['message'] = $e->getMessage();
		} catch ( Error $e ) {
			$ok                = false;
			$result['message'] = $e->getMessage();
		}

		if ( ! $ok || ! file_exists( $tmp ) || filesize( $tmp ) < 1 ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$result['reason'] = 'conversion_failed';

			if ( '' === $result['message'] ) {
				$result['message'] = __( 'Conversione fallita.', 'fs3d-image-optimizer' );
			}

			return $result;
		}

		$dst_bytes = (int) filesize( $tmp );

		// Se il file convertito non conviene, lo buttiamo via: meglio nessun file che un file peggiore.
		if ( ! $ignore_gain && FS3D_IO_Settings::get( 'skip_if_larger', 1 ) ) {
			$min_saving = (int) FS3D_IO_Settings::get( 'min_saving', 3 );
			$threshold  = $src_bytes * ( 1 - ( $min_saving / 100 ) );

			if ( $dst_bytes >= $threshold ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				// Rimuoviamo una eventuale versione vecchia rimasta, cosi' Apache torna a servire l'originale.
				if ( file_exists( $dest ) ) {
					@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				$result['status']    = 'skipped';
				$result['reason']    = 'no_gain';
				$result['dst_bytes'] = $dst_bytes;
				$result['message']   = sprintf(
					/* translators: 1: dimensione originale, 2: dimensione convertita. */
					__( 'Nessun risparmio utile (%1$s originale, %2$s convertito): file scartato.', 'fs3d-image-optimizer' ),
					size_format( $src_bytes ),
					size_format( $dst_bytes )
				);

				return $result;
			}
		}

		// Sostituzione atomica della destinazione.
		if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			$result['reason']  = 'write_failed';
			$result['message'] = __( 'Impossibile scrivere il file convertito.', 'fs3d-image-optimizer' );

			return $result;
		}

		self::apply_file_permissions( $dest );

		$result['status']    = 'converted';
		$result['dst_bytes'] = (int) filesize( $dest );
		$result['message']   = sprintf(
			/* translators: 1: dimensione originale, 2: dimensione convertita, 3: percentuale risparmiata. */
			__( 'Convertito: %1$s -> %2$s (-%3$s%%).', 'fs3d-image-optimizer' ),
			size_format( $src_bytes ),
			size_format( $result['dst_bytes'] ),
			$src_bytes > 0 ? round( ( 1 - ( $result['dst_bytes'] / $src_bytes ) ) * 100 ) : 0
		);

		return $result;
	}

	/**
	 * Conversione con Imagick.
	 *
	 * @param string $source  Sorgente.
	 * @param string $dest    Destinazione temporanea.
	 * @param string $format  webp|avif.
	 * @param int    $quality Qualita'.
	 * @param bool   $strip   Rimuovere i metadata.
	 * @return bool
	 * @throws ImagickException Se Imagick fallisce.
	 */
	private static function convert_with_imagick( $source, $dest, $format, $quality, $strip ) {
		$image = new Imagick();

		try {
			$image->readImage( $source );

			// Le immagini possono avere piu' frame (PNG animate, JPEG con thumbnail incorporata).
			if ( method_exists( $image, 'getNumberImages' ) && $image->getNumberImages() > 1 ) {
				$image = $image->coalesceImages();
			}

			if ( $strip ) {
				// Conserviamo il profilo colore per non alterare la resa, poi lo riapplichiamo.
				$profiles = $image->getImageProfiles( 'icc', true );
				$image->stripImage();

				if ( ! empty( $profiles['icc'] ) ) {
					$image->profileImage( 'icc', $profiles['icc'] );
				}
			}

			$image->setImageFormat( $format );
			$image->setImageCompressionQuality( $quality );

			if ( 'webp' === $format ) {
				$image->setOption( 'webp:method', '4' );
				$image->setOption( 'webp:low-memory', 'true' );

				if ( $image->getImageAlphaChannel() ) {
					$image->setOption( 'webp:alpha-quality', '90' );
				}
			} else {
				$image->setOption( 'heic:speed', '6' );
			}

			$written = $image->writeImage( $dest );

			return (bool) $written;
		} finally {
			$image->clear();
			$image->destroy();
		}
	}

	/**
	 * Conversione con GD.
	 *
	 * GD non copia i metadata EXIF, quindi lo strip e' implicito.
	 *
	 * @param string $source  Sorgente.
	 * @param string $dest    Destinazione temporanea.
	 * @param string $format  webp|avif.
	 * @param int    $quality Qualita'.
	 * @return bool
	 */
	private static function convert_with_gd( $source, $dest, $format, $quality ) {
		$ext   = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$image = false;

		if ( 'png' === $ext ) {
			$image = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) {
			$image = @imagecreatefromjpeg( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! $image ) {
			return false;
		}

		try {
			if ( 'png' === $ext ) {
				imagepalettetotruecolor( $image );
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}

			if ( 'avif' === $format ) {
				if ( ! function_exists( 'imageavif' ) ) {
					return false;
				}

				return (bool) @imageavif( $image, $dest, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			return (bool) @imagewebp( $image, $dest, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		} finally {
			imagedestroy( $image );
		}
	}

	/**
	 * Allinea i permessi del file generato a quelli usati da WordPress.
	 *
	 * @param string $file Percorso.
	 * @return void
	 */
	private static function apply_file_permissions( $file ) {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			$perms = FS_CHMOD_FILE;
		} else {
			$reference = ABSPATH . 'index.php';
			$perms     = file_exists( $reference ) ? ( ( fileperms( $reference ) & 0777 ) | 0644 ) : 0644;
		}

		@chmod( $file, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
