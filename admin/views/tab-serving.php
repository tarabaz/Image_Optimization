<?php
/**
 * Tab "Regole .htaccess": attivazione, verifica reale, backup.
 *
 * @package FS3D_Image_Optimizer
 *
 * @var array $settings Impostazioni correnti.
 * @var array $server   Report del server.
 */

defined( 'ABSPATH' ) || exit;

$file      = FS3D_IO_Htaccess::file_path();
$active    = FS3D_IO_Htaccess::has_rules();
$current   = $active && FS3D_IO_Htaccess::rules_are_current();
$writable  = FS3D_IO_Htaccess::is_writable();
$backups   = FS3D_IO_Htaccess::list_backups();
$uploads   = FS3D_IO_Converter::uploads_basedir();
?>

<div class="fs3d-io-card">
	<h2><?php esc_html_e( 'Come funziona', 'fs3d-image-optimizer' ); ?></h2>
	<p>
		<?php esc_html_e( 'Il browser dichiara in ogni richiesta quali formati sa leggere, tramite l\'header Accept. Le regole scritte qui dicono ad Apache: se il browser accetta WebP e accanto al file richiesto esiste la versione WebP, servi quella. L\'URL richiesto resta quello originale, quindi non serve modificare nemmeno un link nel database.', 'fs3d-image-optimizer' ); ?>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: %s: percorso del file .htaccess. */
			esc_html__( 'Le regole vengono scritte solo in %s: il .htaccess principale di WordPress non viene mai toccato.', 'fs3d-image-optimizer' ),
			'<code>' . esc_html( $file ) . '</code>'
		);
		?>
	</p>
</div>

<div class="fs3d-io-card">
	<h2><?php esc_html_e( 'Stato delle regole', 'fs3d-image-optimizer' ); ?></h2>

	<table class="widefat striped fs3d-io-table">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Regole attive', 'fs3d-image-optimizer' ); ?></th>
				<td id="fs3d-io-rules-state">
					<?php if ( $active && $current ) : ?>
						<span class="fs3d-io-flag fs3d-io-flag--ok"><?php esc_html_e( 'attive e aggiornate', 'fs3d-image-optimizer' ); ?></span>
					<?php elseif ( $active ) : ?>
						<span class="fs3d-io-flag fs3d-io-flag--warn"><?php esc_html_e( 'attive ma non allineate alle impostazioni attuali', 'fs3d-image-optimizer' ); ?></span>
					<?php else : ?>
						<span class="fs3d-io-flag fs3d-io-flag--ko"><?php esc_html_e( 'non attive', 'fs3d-image-optimizer' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'File .htaccess', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<code><?php echo esc_html( $file ); ?></code><br>
					<?php if ( $writable ) : ?>
						<span class="fs3d-io-flag fs3d-io-flag--ok"><?php esc_html_e( 'scrivibile', 'fs3d-image-optimizer' ); ?></span>
					<?php else : ?>
						<span class="fs3d-io-flag fs3d-io-flag--ko"><?php esc_html_e( 'non scrivibile', 'fs3d-image-optimizer' ); ?></span>
						<p class="description"><?php esc_html_e( 'Dal pannello di Aruba (o via FTP) imposta i permessi della cartella uploads a 755 e del file .htaccess a 644.', 'fs3d-image-optimizer' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Backup', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %s: percorso cartella backup. */
						esc_html__( 'Ogni modifica crea prima una copia del file in %s (cartella protetta dall\'accesso web).', 'fs3d-image-optimizer' ),
						'<code>' . esc_html( str_replace( $uploads, '.../uploads', FS3D_IO_Htaccess::backup_dir() ) ) . '</code>'
					);
					?>
				</td>
			</tr>
		</tbody>
	</table>

	<p class="fs3d-io-actions">
		<button type="button" class="button button-primary" id="fs3d-io-rules-activate" <?php disabled( ! $writable ); ?>>
			<?php echo $active ? esc_html__( 'Riscrivi le regole', 'fs3d-image-optimizer' ) : esc_html__( 'Attiva le regole', 'fs3d-image-optimizer' ); ?>
		</button>
		<button type="button" class="button" id="fs3d-io-rules-deactivate" <?php disabled( ! $active ); ?>>
			<?php esc_html_e( 'Disattiva le regole', 'fs3d-image-optimizer' ); ?>
		</button>
		<button type="button" class="button" id="fs3d-io-verify">
			<?php esc_html_e( 'Verifica che funzionino davvero', 'fs3d-image-optimizer' ); ?>
		</button>
	</p>

	<div id="fs3d-io-rules-message" class="fs3d-io-inline-notice" hidden></div>
	<div id="fs3d-io-verify-result" class="fs3d-io-verify" hidden></div>
</div>

<div class="fs3d-io-card">
	<h2><?php esc_html_e( 'Anteprima del blocco', 'fs3d-image-optimizer' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Questo e\' esattamente cio\' che verra\' scritto nel file, in cima al contenuto esistente. Il resto del .htaccess non viene modificato.', 'fs3d-image-optimizer' ); ?>
	</p>
	<pre class="fs3d-io-code"><?php echo esc_html( FS3D_IO_Htaccess::build_rules() ); ?></pre>
</div>

<div class="fs3d-io-card">
	<h2><?php esc_html_e( 'Backup disponibili', 'fs3d-image-optimizer' ); ?></h2>

	<?php if ( empty( $backups ) ) : ?>
		<p><?php esc_html_e( 'Nessun backup presente: verra\' creato automaticamente alla prima modifica del file.', 'fs3d-image-optimizer' ); ?></p>
	<?php else : ?>
		<table class="widefat striped fs3d-io-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'File', 'fs3d-image-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Data', 'fs3d-image-optimizer' ); ?></th>
					<th><?php esc_html_e( 'Dimensione', 'fs3d-image-optimizer' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<tr>
						<td><code><?php echo esc_html( $backup['name'] ); ?></code></td>
						<td><?php echo esc_html( wp_date( 'd/m/Y H:i:s', $backup['time'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
						<td>
							<button type="button" class="button button-small fs3d-io-restore"
								data-backup="<?php echo esc_attr( $backup['name'] ); ?>">
								<?php esc_html_e( 'Ripristina', 'fs3d-image-optimizer' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
