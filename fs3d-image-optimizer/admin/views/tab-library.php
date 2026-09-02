<?php
/**
 * Tab "Libreria": elenco filtrabile con selezione multipla e batch AJAX.
 *
 * @package FS3D_Image_Optimizer
 *
 * @var array $settings Impostazioni correnti.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$filters = FS3D_IO_Library::sanitize_filters(
	array(
		'mime'     => isset( $_GET['mime'] ) ? sanitize_text_field( wp_unslash( $_GET['mime'] ) ) : 'all',
		'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all',
		'size'     => isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : 'all',
		'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		'paged'    => isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1,
		'per_page' => isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : 40,
	)
);
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$results = FS3D_IO_Library::query( $filters );
$rows    = array_map( array( 'FS3D_IO_Library', 'row_data' ), $results['ids'] );

$base_url = FS3D_IO_Admin::tab_url( 'library' );
?>

<form method="get" class="fs3d-io-filters">
	<input type="hidden" name="page" value="<?php echo esc_attr( FS3D_IO_Admin::PAGE_SLUG ); ?>">
	<input type="hidden" name="tab" value="library">

	<label>
		<span class="screen-reader-text"><?php esc_html_e( 'Tipo di file', 'fs3d-image-optimizer' ); ?></span>
		<select name="mime">
			<option value="all" <?php selected( $filters['mime'], 'all' ); ?>><?php esc_html_e( 'Tutti i formati', 'fs3d-image-optimizer' ); ?></option>
			<option value="image/jpeg" <?php selected( $filters['mime'], 'image/jpeg' ); ?>>JPEG</option>
			<option value="image/png" <?php selected( $filters['mime'], 'image/png' ); ?>>PNG</option>
		</select>
	</label>

	<label>
		<span class="screen-reader-text"><?php esc_html_e( 'Stato', 'fs3d-image-optimizer' ); ?></span>
		<select name="status">
			<option value="all" <?php selected( $filters['status'], 'all' ); ?>><?php esc_html_e( 'Qualsiasi stato', 'fs3d-image-optimizer' ); ?></option>
			<option value="pending" <?php selected( $filters['status'], 'pending' ); ?>><?php esc_html_e( 'Da ottimizzare', 'fs3d-image-optimizer' ); ?></option>
			<option value="optimized" <?php selected( $filters['status'], 'optimized' ); ?>><?php esc_html_e( 'Gia\' ottimizzate', 'fs3d-image-optimizer' ); ?></option>
			<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Con errori', 'fs3d-image-optimizer' ); ?></option>
		</select>
	</label>

	<label>
		<span class="screen-reader-text"><?php esc_html_e( 'Dimensione', 'fs3d-image-optimizer' ); ?></span>
		<select name="size">
			<option value="all" <?php selected( $filters['size'], 'all' ); ?>><?php esc_html_e( 'Qualsiasi dimensione', 'fs3d-image-optimizer' ); ?></option>
			<option value="big" <?php selected( $filters['size'], 'big' ); ?>><?php esc_html_e( 'Oltre 1 MB', 'fs3d-image-optimizer' ); ?></option>
			<option value="medium" <?php selected( $filters['size'], 'medium' ); ?>><?php esc_html_e( 'Da 300 KB a 1 MB', 'fs3d-image-optimizer' ); ?></option>
			<option value="small" <?php selected( $filters['size'], 'small' ); ?>><?php esc_html_e( 'Sotto 300 KB', 'fs3d-image-optimizer' ); ?></option>
		</select>
	</label>

	<label>
		<span class="screen-reader-text"><?php esc_html_e( 'Cerca', 'fs3d-image-optimizer' ); ?></span>
		<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>"
			placeholder="<?php esc_attr_e( 'Cerca per nome...', 'fs3d-image-optimizer' ); ?>">
	</label>

	<button type="submit" class="button"><?php esc_html_e( 'Filtra', 'fs3d-image-optimizer' ); ?></button>
	<a class="button-link" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Azzera filtri', 'fs3d-image-optimizer' ); ?></a>
</form>

<?php if ( 'all' !== $filters['size'] ) : ?>
	<p class="description">
		<?php esc_html_e( 'Il filtro per dimensione legge i file su disco: su librerie molto grandi il caricamento della pagina puo\' richiedere qualche secondo in piu\'.', 'fs3d-image-optimizer' ); ?>
	</p>
<?php endif; ?>

<div class="fs3d-io-bulkbar">
	<div class="fs3d-io-bulkbar__actions">
		<button type="button" class="button button-primary" id="fs3d-io-optimize-selected">
			<?php esc_html_e( 'Ottimizza selezionate', 'fs3d-image-optimizer' ); ?>
		</button>
		<button type="button" class="button" id="fs3d-io-optimize-filtered">
			<?php
			printf(
				/* translators: %s: numero di immagini che corrispondono ai filtri. */
				esc_html__( 'Ottimizza tutte le %s immagini filtrate', 'fs3d-image-optimizer' ),
				esc_html( number_format_i18n( $results['total'] ) )
			);
			?>
		</button>
		<label class="fs3d-io-force">
			<input type="checkbox" id="fs3d-io-force">
			<?php esc_html_e( 'Riconverti anche quelle gia\' fatte', 'fs3d-image-optimizer' ); ?>
		</label>
	</div>

	<div class="fs3d-io-bulkbar__count">
		<?php
		printf(
			/* translators: 1: risultati in pagina, 2: totale. */
			esc_html__( '%1$s immagini in pagina su %2$s trovate', 'fs3d-image-optimizer' ),
			esc_html( number_format_i18n( count( $rows ) ) ),
			esc_html( number_format_i18n( $results['total'] ) )
		);
		?>
	</div>
</div>

<?php require FS3D_IO_PATH . 'admin/views/partial-progress.php'; ?>

<table class="widefat striped fs3d-io-media">
	<thead>
		<tr>
			<td class="check-column">
				<input type="checkbox" id="fs3d-io-check-all" title="<?php esc_attr_e( 'Seleziona tutte in pagina', 'fs3d-image-optimizer' ); ?>">
			</td>
			<th class="fs3d-io-media__thumb"><?php esc_html_e( 'Anteprima', 'fs3d-image-optimizer' ); ?></th>
			<th><?php esc_html_e( 'File', 'fs3d-image-optimizer' ); ?></th>
			<th><?php esc_html_e( 'Peso originale', 'fs3d-image-optimizer' ); ?></th>
			<th><?php esc_html_e( 'Stato', 'fs3d-image-optimizer' ); ?></th>
			<th><?php esc_html_e( 'Risparmio', 'fs3d-image-optimizer' ); ?></th>
			<th><?php esc_html_e( 'Data', 'fs3d-image-optimizer' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr>
				<td colspan="7"><?php esc_html_e( 'Nessuna immagine corrisponde ai filtri selezionati.', 'fs3d-image-optimizer' ); ?></td>
			</tr>
		<?php endif; ?>

		<?php foreach ( $rows as $row ) : ?>
			<tr id="fs3d-io-row-<?php echo esc_attr( $row['id'] ); ?>">
				<th scope="row" class="check-column">
					<input type="checkbox" class="fs3d-io-check" value="<?php echo esc_attr( $row['id'] ); ?>">
				</th>
				<td class="fs3d-io-media__thumb">
					<?php if ( $row['thumb'] ) : ?>
						<img src="<?php echo esc_url( $row['thumb'] ); ?>" alt="" loading="lazy" width="60" height="60">
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $row['edit_link'] ) : ?>
						<a href="<?php echo esc_url( $row['edit_link'] ); ?>"><strong><?php echo esc_html( $row['name'] ); ?></strong></a>
					<?php else : ?>
						<strong><?php echo esc_html( $row['name'] ); ?></strong>
					<?php endif; ?>
					<div class="fs3d-io-media__meta"><?php echo esc_html( $row['mime'] ); ?> &middot; ID <?php echo esc_html( $row['id'] ); ?></div>
				</td>
				<td><?php echo esc_html( $row['size_human'] ); ?></td>
				<td class="fs3d-io-status-cell">
					<span class="fs3d-io-badge fs3d-io-badge--<?php echo esc_attr( $row['status'] ); ?>">
						<?php echo esc_html( FS3D_IO_Library::status_label( $row['status'] ) ); ?>
					</span>
					<?php if ( $row['generated'] > 0 ) : ?>
						<div class="fs3d-io-media__meta">
							<?php
							printf(
								/* translators: %d: numero di file generati. */
								esc_html( _n( '%d file generato', '%d file generati', $row['generated'], 'fs3d-image-optimizer' ) ),
								(int) $row['generated']
							);
							?>
						</div>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $row['saved'] > 0 ) : ?>
						<strong>-<?php echo esc_html( size_format( $row['saved'] ) ); ?></strong>
						<div class="fs3d-io-media__meta">-<?php echo esc_html( $row['percent'] ); ?>%</div>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $row['date'] ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php
$pagination = paginate_links(
	array(
		'base'      => add_query_arg( 'paged', '%#%', add_query_arg( array_filter( array(
			'mime'   => 'all' !== $filters['mime'] ? $filters['mime'] : null,
			'status' => 'all' !== $filters['status'] ? $filters['status'] : null,
			'size'   => 'all' !== $filters['size'] ? $filters['size'] : null,
			's'      => '' !== $filters['search'] ? $filters['search'] : null,
		) ), $base_url ) ),
		'format'    => '',
		'current'   => $filters['paged'],
		'total'     => max( 1, $results['pages'] ),
		'prev_text' => __( '&laquo; Precedente', 'fs3d-image-optimizer' ),
		'next_text' => __( 'Successiva &raquo;', 'fs3d-image-optimizer' ),
	)
);

if ( $pagination ) {
	echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $pagination ) . '</div></div>';
}
?>
