<?php
/**
 * Tab "Log": ultime operazioni registrate.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

$entries = FS3D_IO_Logger::get( 100 );
?>

<div class="fs3d-io-card">
	<h2><?php esc_html_e( 'Ultime operazioni', 'fs3d-image-optimizer' ); ?></h2>

	<p class="fs3d-io-actions">
		<button type="button" class="button" id="fs3d-io-clear-log"><?php esc_html_e( 'Svuota il log', 'fs3d-image-optimizer' ); ?></button>
	</p>

	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'Nessuna operazione registrata finora.', 'fs3d-image-optimizer' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fs3d-io-table fs3d-io-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Data', 'fs3d-image-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Esito', 'fs3d-image-optimizer' ); ?></th>
					<th><?php esc_html_e( 'File', 'fs3d-image-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Dettaglio', 'fs3d-image-optimizer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i:s', (int) $entry['time'] ) ); ?></td>
						<td><span class="fs3d-io-badge fs3d-io-badge--<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $entry['level'] ); ?></span></td>
						<td>
							<?php if ( ! empty( $entry['file'] ) ) : ?>
								<code><?php echo esc_html( $entry['file'] ); ?></code>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
