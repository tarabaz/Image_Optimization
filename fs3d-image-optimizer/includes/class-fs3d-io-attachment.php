<?php
/**
 * Ottimizzazione a livello di allegato: file principale + thumbnail generate da WordPress.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrazione della conversione per un allegato della libreria media.
 */
class FS3D_IO_Attachment {

	/**
	 * Versione dello schema salvato nella meta, per future migrazioni.
	 */
	const DATA_VERSION = 1;

	/**
	 * Ottimizza un allegato (file principale ed eventuali thumbnail).
	 *
	 * @param int   $attachment_id ID allegato.
	 * @param bool  $force         Riconverte anche se i file esistono gia'.
	 * @param array $metadata      Metadata gia' disponibili (evita una query in fase di upload).
	 * @return array Riepilogo dell'operazione.
	 */
	public static function optimize( $attachment_id, $force = false, $metadata = null ) {
		$attachment_id = (int) $attachment_id;

		$summary = array(
			'attachment_id' => $attachment_id,
			'status'        => 'failed',
			'converted'     => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'src_bytes'     => 0,
			'dst_bytes'     => 0,
			'saved'         => 0,
			'files'         => array(),
			'message'       => '',
		);

		$main_file = get_attached_file( $attachment_id );

		if ( ! $main_file || ! file_exists( $main_file ) ) {
			$summary['message'] = __( 'File dell\'allegato non trovato.', 'fs3d-image-optimizer' );
			$summary['failed']  = 1;

			update_post_meta( $attachment_id, FS3D_IO_META_STATUS, 'failed' );
			FS3D_IO_Logger::add( 'error', $summary['message'], array( 'attachment_id' => $attachment_id ) );

			return $summary;
		}

		if ( ! FS3D_IO_Converter::is_supported_source( $main_file ) ) {
			$summary['status']  = 'skipped';
			$summary['skipped'] = 1;
			$summary['message'] = __( 'Tipo di file non gestito (solo JPG e PNG).', 'fs3d-image-optimizer' );

			update_post_meta( $attachment_id, FS3D_IO_META_STATUS, 'skipped' );

			return $summary;
		}

		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		$targets = self::collect_targets( $main_file, $metadata );
		$formats = FS3D_IO_Settings::output_formats();
		$files   = array();

		foreach ( $targets as $size_key => $path ) {
			foreach ( $formats as $format ) {
				$result = FS3D_IO_Converter::convert( $path, $format, $force );

				$entry = array(
					'size'      => $size_key,
					'format'    => $format,
					'status'    => $result['status'],
					'reason'    => $result['reason'],
					'src'       => FS3D_IO_Converter::relative_path( $result['source'] ),
					'dst'       => '' !== $result['dest'] ? FS3D_IO_Converter::relative_path( $result['dest'] ) : '',
					'src_bytes' => (int) $result['src_bytes'],
					'dst_bytes' => (int) $result['dst_bytes'],
				);

				if ( 'converted' === $result['status'] ) {
					$summary['converted']++;
					$summary['src_bytes'] += (int) $result['src_bytes'];
					$summary['dst_bytes'] += (int) $result['dst_bytes'];
					$files[]               = $entry;
				} elseif ( 'skipped' === $result['status'] ) {
					$summary['skipped']++;

					// Un file gia' presente continua a contare come ottimizzato.
					if ( 'already_exists' === $result['reason'] ) {
						$summary['src_bytes'] += (int) $result['src_bytes'];
						$summary['dst_bytes'] += (int) $result['dst_bytes'];
						$files[]               = $entry;
					}
				} else {
					$summary['failed']++;

					FS3D_IO_Logger::add(
						'error',
						sprintf(
							/* translators: 1: nome file, 2: messaggio di errore. */
							__( '%1$s: %2$s', 'fs3d-image-optimizer' ),
							basename( $path ),
							$result['message']
						),
						array(
							'attachment_id' => $attachment_id,
							'file'          => basename( $path ),
						)
					);
				}
			}
		}

		$summary['files'] = $files;
		$summary['saved'] = (int) max( 0, $summary['src_bytes'] - $summary['dst_bytes'] );

		if ( ! empty( $files ) ) {
			$summary['status'] = $summary['failed'] > 0 ? 'partial' : 'optimized';
		} elseif ( $summary['failed'] > 0 ) {
			$summary['status'] = 'failed';
		} else {
			$summary['status'] = 'skipped';
		}

		$data = array(
			'version'   => self::DATA_VERSION,
			'time'      => time(),
			'formats'   => $formats,
			'naming'    => FS3D_IO_Settings::get( 'naming', 'suffix' ),
			'files'     => $files,
			'src_bytes' => $summary['src_bytes'],
			'dst_bytes' => $summary['dst_bytes'],
			'saved'     => $summary['saved'],
		);

		if ( empty( $files ) ) {
			delete_post_meta( $attachment_id, FS3D_IO_META_DATA );
		} else {
			update_post_meta( $attachment_id, FS3D_IO_META_DATA, $data );
		}

		update_post_meta( $attachment_id, FS3D_IO_META_STATUS, $summary['status'] );

		if ( $summary['converted'] > 0 ) {
			FS3D_IO_Logger::add(
				'success',
				sprintf(
					/* translators: 1: nome file, 2: numero di file generati, 3: spazio risparmiato. */
					__( '%1$s: %2$d file generati, %3$s risparmiati.', 'fs3d-image-optimizer' ),
					basename( $main_file ),
					$summary['converted'],
					size_format( $summary['saved'] )
				),
				array(
					'attachment_id' => $attachment_id,
					'file'          => basename( $main_file ),
					'saved'         => $summary['saved'],
				)
			);
		}

		return $summary;
	}

	/**
	 * Elenca i file da convertire per un allegato.
	 *
	 * Nota: con la modalita' "big image" di WordPress get_attached_file() restituisce
	 * gia' il file ridimensionato (-scaled), che e' quello realmente servito nelle pagine.
	 * L'originale full-size non viene linkato e quindi non viene convertito.
	 *
	 * @param string $main_file Percorso assoluto del file principale.
	 * @param array  $metadata  Metadata dell'allegato.
	 * @return array size_key => percorso assoluto.
	 */
	public static function collect_targets( $main_file, $metadata ) {
		$targets = array( 'full' => wp_normalize_path( $main_file ) );

		if ( 'all' !== FS3D_IO_Settings::get( 'process_sizes', 'all' ) ) {
			return $targets;
		}

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $targets;
		}

		$dir = trailingslashit( dirname( wp_normalize_path( $main_file ) ) );

		foreach ( $metadata['sizes'] as $size_key => $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}

			// I metadata contengono solo il nome file, mai un percorso: normalizziamo comunque.
			$file = basename( (string) $size['file'] );
			$path = $dir . $file;

			if ( ! isset( $targets[ $size_key ] ) && file_exists( $path ) ) {
				$targets[ (string) $size_key ] = $path;
			}
		}

		return $targets;
	}

	/**
	 * Hook di automazione sui nuovi upload.
	 *
	 * @param array  $metadata      Metadata generati.
	 * @param int    $attachment_id ID allegato.
	 * @param string $context       Contesto ('create' o 'update').
	 * @return array Metadata invariati.
	 */
	public static function on_generate_metadata( $metadata, $attachment_id, $context = 'create' ) {
		if ( ! FS3D_IO_Settings::get( 'auto_optimize', 1 ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! FS3D_IO_Converter::is_supported_source( $file ) ) {
			return $metadata;
		}

		self::optimize( $attachment_id, false, $metadata );
		FS3D_IO_Stats::invalidate();
		FS3D_IO_Logger::flush();

		return $metadata;
	}

	/**
	 * Rimuove i file generati quando l'allegato viene eliminato, per non lasciare orfani.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return void
	 */
	public static function on_delete_attachment( $attachment_id ) {
		self::delete_generated_files( $attachment_id );
		FS3D_IO_Stats::invalidate();
	}

	/**
	 * Elimina i file WebP/AVIF generati per un allegato.
	 *
	 * Elimina solo file con estensione .webp/.avif dentro la cartella uploads:
	 * gli originali non vengono mai toccati.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return int Numero di file eliminati.
	 */
	public static function delete_generated_files( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$basedir       = FS3D_IO_Converter::uploads_basedir();
		$deleted       = 0;
		$candidates    = array();

		$data = get_post_meta( $attachment_id, FS3D_IO_META_DATA, true );

		if ( is_array( $data ) && ! empty( $data['files'] ) ) {
			foreach ( $data['files'] as $entry ) {
				if ( ! empty( $entry['dst'] ) ) {
					$candidates[] = $basedir . '/' . ltrim( (string) $entry['dst'], '/' );
				}
			}
		}

		// Fallback: ricalcoliamo i percorsi attesi, utile se la meta e' incompleta
		// o se le impostazioni di naming sono cambiate nel frattempo.
		$main_file = get_attached_file( $attachment_id );

		if ( $main_file ) {
			$targets = self::collect_targets( $main_file, wp_get_attachment_metadata( $attachment_id ) );

			foreach ( $targets as $path ) {
				$dir       = dirname( $path );
				$name      = pathinfo( $path, PATHINFO_FILENAME );
				$ambiguous = FS3D_IO_Converter::has_name_collision( $path );

				foreach ( array( 'webp', 'avif' ) as $format ) {
					$candidates[] = $path . '.' . $format;

					// Con naming "replace" cancelliamo solo se non ci sono ambiguita' di nome.
					if ( ! $ambiguous ) {
						$candidates[] = $dir . '/' . $name . '.' . $format;
					}
				}
			}
		}

		foreach ( array_unique( $candidates ) as $file ) {
			if ( self::delete_generated_file( $file ) ) {
				$deleted++;
			}
		}

		delete_post_meta( $attachment_id, FS3D_IO_META_DATA );
		delete_post_meta( $attachment_id, FS3D_IO_META_STATUS );

		return $deleted;
	}

	/**
	 * Elimina un singolo file generato, con tutti i controlli di sicurezza.
	 *
	 * @param string $file Percorso assoluto.
	 * @return bool
	 */
	public static function delete_generated_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'webp', 'avif' ), true ) ) {
			return false;
		}

		if ( ! FS3D_IO_Converter::is_inside_uploads( $file ) ) {
			return false;
		}

		if ( ! file_exists( $file ) || is_dir( $file ) ) {
			return false;
		}

		return (bool) @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Stato di ottimizzazione leggibile di un allegato.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return string optimized|partial|failed|skipped|none.
	 */
	public static function get_status( $attachment_id ) {
		$status = get_post_meta( (int) $attachment_id, FS3D_IO_META_STATUS, true );

		return $status ? (string) $status : 'none';
	}
}
