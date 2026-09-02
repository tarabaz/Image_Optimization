<?php
/**
 * Query e filtri sulla libreria media.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Interrogazione degli allegati immagine gestibili dal plugin.
 */
class FS3D_IO_Library {

	/**
	 * Filtri di default.
	 *
	 * @return array
	 */
	public static function default_filters() {
		return array(
			'mime'     => 'all',   // all | image/jpeg | image/png.
			'status'   => 'all',   // all | optimized | pending | failed.
			'size'     => 'all',   // all | big | medium | small.
			'search'   => '',
			'paged'    => 1,
			'per_page' => 40,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);
	}

	/**
	 * Normalizza un set di filtri arbitrario.
	 *
	 * @param array $input Filtri grezzi.
	 * @return array
	 */
	public static function sanitize_filters( $input ) {
		$filters = self::default_filters();
		$input   = is_array( $input ) ? $input : array();

		if ( isset( $input['mime'] ) && in_array( $input['mime'], array( 'all', 'image/jpeg', 'image/png' ), true ) ) {
			$filters['mime'] = $input['mime'];
		}

		if ( isset( $input['status'] ) && in_array( $input['status'], array( 'all', 'optimized', 'pending', 'failed' ), true ) ) {
			$filters['status'] = $input['status'];
		}

		if ( isset( $input['size'] ) && in_array( $input['size'], array( 'all', 'big', 'medium', 'small' ), true ) ) {
			$filters['size'] = $input['size'];
		}

		if ( isset( $input['search'] ) ) {
			$filters['search'] = sanitize_text_field( (string) $input['search'] );
		}

		if ( isset( $input['paged'] ) ) {
			$filters['paged'] = max( 1, (int) $input['paged'] );
		}

		if ( isset( $input['per_page'] ) ) {
			$filters['per_page'] = max( 10, min( 100, (int) $input['per_page'] ) );
		}

		return $filters;
	}

	/**
	 * Soglie in byte usate dal filtro dimensione.
	 *
	 * @return array
	 */
	public static function size_thresholds() {
		return array(
			'big'    => 1024 * 1024,       // oltre 1 MB.
			'medium' => 300 * 1024,        // da 300 KB a 1 MB.
			'small'  => 0,                 // sotto 300 KB.
		);
	}

	/**
	 * Costruisce gli argomenti di WP_Query a partire dai filtri.
	 *
	 * @param array $filters Filtri sanitizzati.
	 * @param bool  $ids_only Restituisce solo gli ID senza paginazione.
	 * @return array
	 */
	private static function build_query_args( $filters, $ids_only = false ) {
		$mimes = 'all' === $filters['mime'] ? FS3D_IO_Converter::SOURCE_MIMES : array( $filters['mime'] );

		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => $mimes,
			'fields'                 => 'ids',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
		);

		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}

		$meta_query = self::status_meta_query( $filters['status'] );

		if ( ! empty( $meta_query ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'] = $meta_query;
		}

		if ( $ids_only ) {
			$args['posts_per_page'] = -1;
			$args['no_found_rows']  = true;
		} else {
			$args['posts_per_page'] = $filters['per_page'];
			$args['paged']          = $filters['paged'];
		}

		return $args;
	}

	/**
	 * Meta query corrispondente al filtro di stato.
	 *
	 * @param string $status Filtro.
	 * @return array
	 */
	private static function status_meta_query( $status ) {
		switch ( $status ) {
			case 'optimized':
				return array(
					array(
						'key'     => FS3D_IO_META_STATUS,
						'value'   => array( 'optimized', 'partial' ),
						'compare' => 'IN',
					),
				);

			case 'failed':
				return array(
					array(
						'key'     => FS3D_IO_META_STATUS,
						'value'   => array( 'failed', 'partial' ),
						'compare' => 'IN',
					),
				);

			case 'pending':
				return array(
					'relation' => 'OR',
					array(
						'key'     => FS3D_IO_META_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => FS3D_IO_META_STATUS,
						'value'   => array( 'optimized', 'partial' ),
						'compare' => 'NOT IN',
					),
				);
		}

		return array();
	}

	/**
	 * Esegue la query paginata.
	 *
	 * @param array $filters Filtri sanitizzati.
	 * @return array {
	 *     @type int[] $ids       ID della pagina corrente.
	 *     @type int   $total     Totale risultati.
	 *     @type int   $pages     Numero di pagine.
	 *     @type bool  $filtered  True se e' stato applicato il filtro dimensione in PHP.
	 * }
	 */
	public static function query( $filters ) {
		$filters = self::sanitize_filters( $filters );

		// Il filtro dimensione non e' esprimibile in SQL: lavoriamo sull'elenco completo
		// degli ID e impaginiamo in PHP.
		if ( 'all' !== $filters['size'] ) {
			$ids   = self::filter_ids_by_size( self::collect_ids( $filters ), $filters['size'] );
			$total = count( $ids );
			$offset = ( $filters['paged'] - 1 ) * $filters['per_page'];

			return array(
				'ids'      => array_slice( $ids, $offset, $filters['per_page'] ),
				'total'    => $total,
				'pages'    => (int) ceil( $total / $filters['per_page'] ),
				'filtered' => true,
			);
		}

		$query = new WP_Query( self::build_query_args( $filters ) );

		return array(
			'ids'      => array_map( 'intval', $query->posts ),
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'filtered' => false,
		);
	}

	/**
	 * Restituisce tutti gli ID che rispettano i filtri (usato per costruire la coda).
	 *
	 * @param array $filters Filtri sanitizzati.
	 * @return int[]
	 */
	public static function collect_ids( $filters ) {
		$filters = self::sanitize_filters( $filters );
		$query   = new WP_Query( self::build_query_args( $filters, true ) );
		$ids     = array_map( 'intval', $query->posts );

		if ( 'all' !== $filters['size'] ) {
			$ids = self::filter_ids_by_size( $ids, $filters['size'] );
		}

		return $ids;
	}

	/**
	 * Filtra gli ID in base alla dimensione del file principale.
	 *
	 * @param int[]  $ids    ID allegati.
	 * @param string $bucket big|medium|small.
	 * @return int[]
	 */
	public static function filter_ids_by_size( $ids, $bucket ) {
		$thresholds = self::size_thresholds();
		$out        = array();

		foreach ( $ids as $id ) {
			$bytes = self::file_size( $id );

			if ( $bytes <= 0 ) {
				continue;
			}

			if ( 'big' === $bucket && $bytes >= $thresholds['big'] ) {
				$out[] = $id;
			} elseif ( 'medium' === $bucket && $bytes >= $thresholds['medium'] && $bytes < $thresholds['big'] ) {
				$out[] = $id;
			} elseif ( 'small' === $bucket && $bytes < $thresholds['medium'] ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * Dimensione del file principale di un allegato.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return int Byte (0 se non determinabile).
	 */
	public static function file_size( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		// WordPress 6.0+ salva gia' la dimensione: evitiamo una stat() inutile.
		if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
			return (int) $meta['filesize'];
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return 0;
		}

		return (int) filesize( $file );
	}

	/**
	 * Dati di una riga per la tabella di amministrazione.
	 *
	 * @param int $attachment_id ID allegato.
	 * @return array
	 */
	public static function row_data( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file          = get_attached_file( $attachment_id );
		$data          = get_post_meta( $attachment_id, FS3D_IO_META_DATA, true );
		$status        = FS3D_IO_Attachment::get_status( $attachment_id );
		$src_bytes     = self::file_size( $attachment_id );

		$saved   = ( is_array( $data ) && isset( $data['saved'] ) ) ? (int) $data['saved'] : 0;
		$files   = ( is_array( $data ) && ! empty( $data['files'] ) ) ? count( $data['files'] ) : 0;
		$percent = 0;

		if ( is_array( $data ) && ! empty( $data['src_bytes'] ) ) {
			$percent = (int) round( ( $saved / $data['src_bytes'] ) * 100 );
		}

		return array(
			'id'         => $attachment_id,
			'title'      => get_the_title( $attachment_id ),
			'name'       => $file ? basename( $file ) : '',
			'thumb'      => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'edit_link'  => get_edit_post_link( $attachment_id, 'raw' ),
			'mime'       => get_post_mime_type( $attachment_id ),
			'date'       => get_the_date( 'd/m/Y', $attachment_id ),
			'size'       => $src_bytes,
			'size_human' => $src_bytes ? size_format( $src_bytes ) : '-',
			'status'     => $status,
			'saved'      => $saved,
			'percent'    => $percent,
			'generated'  => $files,
		);
	}

	/**
	 * Etichetta leggibile di uno stato.
	 *
	 * @param string $status Stato.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'optimized' => __( 'Ottimizzata', 'fs3d-image-optimizer' ),
			'partial'   => __( 'Parziale', 'fs3d-image-optimizer' ),
			'failed'    => __( 'Errore', 'fs3d-image-optimizer' ),
			'skipped'   => __( 'Saltata', 'fs3d-image-optimizer' ),
			'none'      => __( 'Da fare', 'fs3d-image-optimizer' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['none'];
	}
}
