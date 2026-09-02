<?php
/**
 * Tab "Stato": capacita' del server e statistiche.
 *
 * @package FS3D_Image_Optimizer
 *
 * @var array $settings Impostazioni correnti.
 * @var array $server   Report del server.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fs3d_io_flag' ) ) {
	/**
	 * Rende un valore booleano come pallino colorato.
	 *
	 * @param bool|null $value Valore (null = sconosciuto).
	 * @param string    $yes   Etichetta per true.
	 * @param string    $no    Etichetta per false.
	 * @return string HTML gia' sicuro.
	 */
	function fs3d_io_flag( $value, $yes = '', $no = '' ) {
		if ( null === $value ) {
			return '<span class="fs3d-io-flag fs3d-io-flag--unknown">' . esc_html__( 'non rilevabile', 'fs3d-image-optimizer' ) . '</span>';
		}

		$yes = '' !== $yes ? $yes : __( 'si', 'fs3d-image-optimizer' );
		$no  = '' !== $no ? $no : __( 'no', 'fs3d-image-optimizer' );

		return $value
			? '<span class="fs3d-io-flag fs3d-io-flag--ok">' . esc_html( $yes ) . '</span>'
			: '<span class="fs3d-io-flag fs3d-io-flag--ko">' . esc_html( $no ) . '</span>';
	}
}

$stats     = FS3D_IO_Stats::get();
$rules_on  = FS3D_IO_Htaccess::has_rules();
$engine    = FS3D_IO_Server::engine_for( 'webp' );
$mem_bytes = (int) $server['memory_limit_bytes'];
?>

<div class="fs3d-io-grid fs3d-io-grid--stats">
	<div class="fs3d-io-card fs3d-io-card--metric">
		<span class="fs3d-io-metric__value"><?php echo esc_html( number_format_i18n( $stats['total_images'] ) ); ?></span>
		<span class="fs3d-io-metric__label"><?php esc_html_e( 'Immagini JPG/PNG in libreria', 'fs3d-image-optimizer' ); ?></span>
	</div>
	<div class="fs3d-io-card fs3d-io-card--metric">
		<span class="fs3d-io-metric__value"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></span>
		<span class="fs3d-io-metric__label">
			<?php
			printf(
				/* translators: %s: percentuale di copertura. */
				esc_html__( 'Gia\' ottimizzate (%s%% della libreria)', 'fs3d-image-optimizer' ),
				esc_html( number_format_i18n( $stats['coverage'], 1 ) )
			);
			?>
		</span>
	</div>
	<div class="fs3d-io-card fs3d-io-card--metric fs3d-io-card--highlight">
		<span class="fs3d-io-metric__value"><?php echo esc_html( size_format( $stats['saved_bytes'], 1 ) ); ?></span>
		<span class="fs3d-io-metric__label">
			<?php
			printf(
				/* translators: %s: percentuale risparmiata. */
				esc_html__( 'Spazio risparmiato (-%s%% sui file convertiti)', 'fs3d-image-optimizer' ),
				esc_html( number_format_i18n( $stats['saved_percent'], 1 ) )
			);
			?>
		</span>
	</div>
	<div class="fs3d-io-card fs3d-io-card--metric">
		<span class="fs3d-io-metric__value"><?php echo esc_html( number_format_i18n( $stats['generated_files'] ) ); ?></span>
		<span class="fs3d-io-metric__label"><?php esc_html_e( 'File affiancati generati', 'fs3d-image-optimizer' ); ?></span>
	</div>
</div>

<p class="fs3d-io-actions">
	<button type="button" class="button" id="fs3d-io-refresh-stats"><?php esc_html_e( 'Ricalcola statistiche', 'fs3d-image-optimizer' ); ?></button>
	<a class="button button-primary" href="<?php echo esc_url( FS3D_IO_Admin::tab_url( 'library' ) ); ?>"><?php esc_html_e( 'Vai alla libreria', 'fs3d-image-optimizer' ); ?></a>
</p>

<div class="fs3d-io-grid fs3d-io-grid--two">
	<div class="fs3d-io-card">
		<h2><?php esc_html_e( 'Stato del server', 'fs3d-image-optimizer' ); ?></h2>

		<table class="widefat striped fs3d-io-table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Versione PHP', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php echo esc_html( $server['php_version'] ); ?>
						<?php echo wp_kses_post( fs3d_io_flag( $server['php_ok'], __( 'ok', 'fs3d-image-optimizer' ), __( 'troppo vecchia', 'fs3d-image-optimizer' ) ) ); ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'GD', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php if ( $server['gd']['available'] ) : ?>
							<?php echo esc_html( $server['gd']['version'] ); ?> &mdash;
							WebP <?php echo wp_kses_post( fs3d_io_flag( $server['gd']['webp'] ) ); ?>
							AVIF <?php echo wp_kses_post( fs3d_io_flag( $server['gd']['avif'] ) ); ?>
						<?php else : ?>
							<?php echo wp_kses_post( fs3d_io_flag( false, '', __( 'non disponibile', 'fs3d-image-optimizer' ) ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Imagick', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php if ( $server['imagick']['available'] ) : ?>
							<?php echo esc_html( $server['imagick']['version'] ); ?><br>
							WebP <?php echo wp_kses_post( fs3d_io_flag( $server['imagick']['webp'] ) ); ?>
							AVIF <?php echo wp_kses_post( fs3d_io_flag( $server['imagick']['avif'] ) ); ?>
						<?php else : ?>
							<?php echo wp_kses_post( fs3d_io_flag( false, '', __( 'non disponibile', 'fs3d-image-optimizer' ) ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Motore in uso', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php if ( '' !== $engine ) : ?>
							<strong><?php echo esc_html( 'imagick' === $engine ? 'Imagick' : 'GD' ); ?></strong>
						<?php else : ?>
							<span class="fs3d-io-flag fs3d-io-flag--ko"><?php esc_html_e( 'nessun motore compatibile', 'fs3d-image-optimizer' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'memory_limit', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php echo esc_html( $server['memory_limit'] ); ?>
						<?php if ( $mem_bytes > 0 && $mem_bytes < 128 * 1024 * 1024 ) : ?>
							<span class="fs3d-io-flag fs3d-io-flag--warn"><?php esc_html_e( 'basso: usa batch piccoli', 'fs3d-image-optimizer' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'max_execution_time', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php
						echo esc_html(
							$server['max_execution_time'] > 0
								? $server['max_execution_time'] . ' s'
								: __( 'illimitato', 'fs3d-image-optimizer' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Batch consigliato', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: batch consigliato, 2: batch impostato. */
							esc_html__( '%1$d immagini per richiesta (attuale: %2$d)', 'fs3d-image-optimizer' ),
							(int) $server['suggested_batch'],
							(int) $settings['batch_size']
						);
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Web server', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php echo esc_html( $server['server_software'] ? $server['server_software'] : __( 'sconosciuto', 'fs3d-image-optimizer' ) ); ?>
						<?php if ( ! $server['is_apache'] ) : ?>
							<span class="fs3d-io-flag fs3d-io-flag--warn"><?php esc_html_e( 'non sembra Apache: le regole .htaccess potrebbero non essere lette', 'fs3d-image-optimizer' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'mod_rewrite / mod_headers', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php echo wp_kses_post( fs3d_io_flag( $server['mod_rewrite'], 'mod_rewrite ok', 'mod_rewrite assente' ) ); ?>
						<?php echo wp_kses_post( fs3d_io_flag( $server['mod_headers'], 'mod_headers ok', 'mod_headers assente' ) ); ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="fs3d-io-card">
		<h2><?php esc_html_e( 'Configurazione attuale', 'fs3d-image-optimizer' ); ?></h2>

		<table class="widefat striped fs3d-io-table">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Formato di output', 'fs3d-image-optimizer' ); ?></th>
					<td><strong><?php echo esc_html( strtoupper( str_replace( 'both', 'AVIF + WebP', $settings['format'] ) ) ); ?></strong></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Qualita\'', 'fs3d-image-optimizer' ); ?></th>
					<td>
						WebP <?php echo esc_html( $settings['quality_webp'] ); ?>
						<?php if ( 'webp' !== $settings['format'] ) : ?>
							&middot; AVIF <?php echo esc_html( $settings['quality_avif'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Nome dei file generati', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<code>
							<?php echo esc_html( 'suffix' === $settings['naming'] ? 'foto.jpg.webp' : 'foto.webp' ); ?>
						</code>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Nuovi upload', 'fs3d-image-optimizer' ); ?></th>
					<td><?php echo wp_kses_post( fs3d_io_flag( (bool) $settings['auto_optimize'], __( 'ottimizzazione automatica attiva', 'fs3d-image-optimizer' ), __( 'disattivata', 'fs3d-image-optimizer' ) ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Dimensioni trattate', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php
						echo esc_html(
							'all' === $settings['process_sizes']
								? __( 'originale + tutte le thumbnail', 'fs3d-image-optimizer' )
								: __( 'solo il file principale', 'fs3d-image-optimizer' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Regole .htaccess', 'fs3d-image-optimizer' ); ?></th>
					<td>
						<?php echo wp_kses_post( fs3d_io_flag( $rules_on, __( 'attive', 'fs3d-image-optimizer' ), __( 'non attive', 'fs3d-image-optimizer' ) ) ); ?>
						<?php if ( $rules_on && ! FS3D_IO_Htaccess::rules_are_current() ) : ?>
							<span class="fs3d-io-flag fs3d-io-flag--warn"><?php esc_html_e( 'da aggiornare', 'fs3d-image-optimizer' ); ?></span>
						<?php endif; ?>
						<a href="<?php echo esc_url( FS3D_IO_Admin::tab_url( 'serving' ) ); ?>"><?php esc_html_e( 'gestisci', 'fs3d-image-optimizer' ); ?></a>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Originali', 'fs3d-image-optimizer' ); ?></th>
					<td><span class="fs3d-io-flag fs3d-io-flag--ok"><?php esc_html_e( 'mai sovrascritti o rinominati', 'fs3d-image-optimizer' ); ?></span></td>
				</tr>
			</tbody>
		</table>

		<?php if ( '' === $engine ) : ?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Nessuna libreria di questo server sa generare WebP. Contatta l\'assistenza dell\'hosting e chiedi di abilitare il supporto WebP in GD o Imagick.', 'fs3d-image-optimizer' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>
