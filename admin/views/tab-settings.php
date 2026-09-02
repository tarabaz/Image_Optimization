<?php
/**
 * Tab "Impostazioni".
 *
 * @package FS3D_Image_Optimizer
 *
 * @var array $settings Impostazioni correnti.
 * @var array $server   Report del server.
 */

defined( 'ABSPATH' ) || exit;

$avif_ok = FS3D_IO_Server::supports( 'avif' );
$webp_ok = FS3D_IO_Server::supports( 'webp' );
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fs3d-io-form">
	<input type="hidden" name="action" value="fs3d_io_save_settings">
	<?php wp_nonce_field( 'fs3d_io_save_settings' ); ?>

	<div class="fs3d-io-card">
		<h2><?php esc_html_e( 'Conversione', 'fs3d-image-optimizer' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Formato di output', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="format" value="webp" <?php checked( $settings['format'], 'webp' ); ?> <?php disabled( ! $webp_ok ); ?>>
							<?php esc_html_e( 'WebP (consigliato)', 'fs3d-image-optimizer' ); ?>
						</label><br>
						<label>
							<input type="radio" name="format" value="avif" <?php checked( $settings['format'], 'avif' ); ?> <?php disabled( ! $avif_ok ); ?>>
							<?php esc_html_e( 'Solo AVIF', 'fs3d-image-optimizer' ); ?>
							<?php if ( ! $avif_ok ) : ?>
								<em><?php esc_html_e( '(non supportato da questo server)', 'fs3d-image-optimizer' ); ?></em>
							<?php endif; ?>
						</label><br>
						<label>
							<input type="radio" name="format" value="both" <?php checked( $settings['format'], 'both' ); ?> <?php disabled( ! $avif_ok ); ?>>
							<?php esc_html_e( 'AVIF + WebP (doppio file, massima compatibilita\')', 'fs3d-image-optimizer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Con AVIF + WebP vengono generati due file per ogni immagine: il server sceglie il migliore supportato dal browser. Occupa piu\' spazio su disco e raddoppia i tempi di conversione.', 'fs3d-image-optimizer' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="fs3d-quality-webp"><?php esc_html_e( 'Qualita\' WebP', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<input type="range" id="fs3d-quality-webp" name="quality_webp" min="40" max="100" step="1"
						value="<?php echo esc_attr( $settings['quality_webp'] ); ?>"
						class="fs3d-io-range" data-output="fs3d-quality-webp-out">
					<output id="fs3d-quality-webp-out"><?php echo esc_html( $settings['quality_webp'] ); ?></output>
					<p class="description">
						<?php esc_html_e( 'Da 70 a 85 e\' il compromesso migliore per foto di prodotto: sotto 70 iniziano a vedersi artefatti sui contorni e sulle sfumature.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>

			<tr class="fs3d-io-avif-row">
				<th scope="row"><label for="fs3d-quality-avif"><?php esc_html_e( 'Qualita\' AVIF', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<input type="range" id="fs3d-quality-avif" name="quality_avif" min="30" max="100" step="1"
						value="<?php echo esc_attr( $settings['quality_avif'] ); ?>"
						class="fs3d-io-range" data-output="fs3d-quality-avif-out">
					<output id="fs3d-quality-avif-out"><?php echo esc_html( $settings['quality_avif'] ); ?></output>
					<p class="description">
						<?php esc_html_e( 'AVIF rende bene anche a valori piu\' bassi: 50-60 equivale grossomodo a WebP 80. La conversione pero\' e\' molto piu\' lenta.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Comportamento sui file', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<p class="fs3d-io-locked">
						<span class="dashicons dashicons-lock"></span>
						<?php esc_html_e( 'Il file convertito viene sempre creato accanto all\'originale. L\'originale non viene mai sovrascritto ne\' rinominato, e nessun URL viene modificato nel database.', 'fs3d-image-optimizer' ); ?>
					</p>

					<fieldset class="fs3d-io-subfield">
						<legend><?php esc_html_e( 'Nome del file generato', 'fs3d-image-optimizer' ); ?></legend>
						<label>
							<input type="radio" name="naming" value="suffix" <?php checked( $settings['naming'], 'suffix' ); ?>>
							<code>foto.jpg.webp</code> &mdash; <?php esc_html_e( 'consigliato: nessun rischio di collisione', 'fs3d-image-optimizer' ); ?>
						</label><br>
						<label>
							<input type="radio" name="naming" value="replace" <?php checked( $settings['naming'], 'replace' ); ?>>
							<code>foto.webp</code> &mdash; <?php esc_html_e( 'stesso nome, estensione diversa', 'fs3d-image-optimizer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Con "foto.webp" due immagini diverse chiamate foto.jpg e foto.png nella stessa cartella punterebbero allo stesso file convertito: in quel caso il plugin salta la conversione per non servire l\'immagine sbagliata. Se cambi questa impostazione a regole attive, il blocco .htaccess viene riscritto automaticamente.', 'fs3d-image-optimizer' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Metadata EXIF', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="strip_metadata" value="1" <?php checked( $settings['strip_metadata'], 1 ); ?>>
						<?php esc_html_e( 'Rimuovi i metadata EXIF dai file convertiti', 'fs3d-image-optimizer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Fa risparmiare qualche KB per immagine. Il profilo colore ICC viene comunque mantenuto, cosi\' i colori delle foto non cambiano. Gli originali conservano tutti i loro metadata.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Conversioni inutili', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="skip_if_larger" value="1" <?php checked( $settings['skip_if_larger'], 1 ); ?>>
						<?php esc_html_e( 'Scarta il file convertito se non fa risparmiare abbastanza', 'fs3d-image-optimizer' ); ?>
					</label>
					<p>
						<label>
							<?php esc_html_e( 'Risparmio minimo richiesto', 'fs3d-image-optimizer' ); ?>
							<input type="number" name="min_saving" min="0" max="90" step="1" class="small-text"
								value="<?php echo esc_attr( $settings['min_saving'] ); ?>">%
						</label>
					</p>
					<p class="description">
						<?php esc_html_e( 'Capita spesso con PNG piccoli o gia\' compressi: senza questo controllo si finirebbe per servire un file piu\' pesante dell\'originale.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="fs3d-engine"><?php esc_html_e( 'Motore di conversione', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<select name="engine" id="fs3d-engine">
						<option value="auto" <?php selected( $settings['engine'], 'auto' ); ?>><?php esc_html_e( 'Automatico (Imagick se disponibile, altrimenti GD)', 'fs3d-image-optimizer' ); ?></option>
						<option value="imagick" <?php selected( $settings['engine'], 'imagick' ); ?> <?php disabled( ! $server['imagick']['available'] ); ?>>Imagick</option>
						<option value="gd" <?php selected( $settings['engine'], 'gd' ); ?> <?php disabled( ! $server['gd']['available'] ); ?>>GD</option>
					</select>
					<p class="description"><?php esc_html_e( 'Imagick gestisce meglio profili colore e trasparenze; GD consuma piu\' memoria perche\' decomprime tutta l\'immagine.', 'fs3d-image-optimizer' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<div class="fs3d-io-card">
		<h2><?php esc_html_e( 'Automazione sui nuovi upload', 'fs3d-image-optimizer' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Ottimizzazione automatica', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<label class="fs3d-io-toggle">
						<input type="checkbox" name="auto_optimize" value="1" <?php checked( $settings['auto_optimize'], 1 ); ?>>
						<?php esc_html_e( 'Converti automaticamente ogni nuova immagine caricata', 'fs3d-image-optimizer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'La conversione avviene subito dopo che WordPress ha generato le thumbnail. Su Aruba il caricamento di un\'immagine molto grande puo\' quindi richiedere qualche secondo in piu\'.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Dimensioni da convertire', 'fs3d-image-optimizer' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="process_sizes" value="all" <?php checked( $settings['process_sizes'], 'all' ); ?>>
							<?php esc_html_e( 'File principale + tutte le thumbnail generate da WordPress', 'fs3d-image-optimizer' ); ?>
						</label><br>
						<label>
							<input type="radio" name="process_sizes" value="original" <?php checked( $settings['process_sizes'], 'original' ); ?>>
							<?php esc_html_e( 'Solo il file principale', 'fs3d-image-optimizer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Avada e Fusion Builder usano quasi sempre le thumbnail nelle gallerie e negli slider: convertire tutte le dimensioni e\' quello che fa davvero la differenza sul peso delle pagine.', 'fs3d-image-optimizer' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>
		</table>
	</div>

	<div class="fs3d-io-card">
		<h2><?php esc_html_e( 'Avanzate', 'fs3d-image-optimizer' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="fs3d-batch-size"><?php esc_html_e( 'Immagini per batch', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<input type="number" id="fs3d-batch-size" name="batch_size" min="1" max="50" step="1" class="small-text"
						value="<?php echo esc_attr( $settings['batch_size'] ); ?>">
					<p class="description">
						<?php
						printf(
							/* translators: %d: valore consigliato. */
							esc_html__( 'Quante immagini elaborare per ogni richiesta AJAX. Con i limiti di questo server il valore consigliato e\' %d.', 'fs3d-image-optimizer' ),
							(int) $server['suggested_batch']
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="fs3d-time-budget"><?php esc_html_e( 'Tempo massimo per batch', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<input type="number" id="fs3d-time-budget" name="time_budget" min="0" max="300" step="1" class="small-text"
						value="<?php echo esc_attr( $settings['time_budget'] ); ?>">
					<?php esc_html_e( 'secondi (0 = calcolato automaticamente)', 'fs3d-image-optimizer' ); ?>
					<p class="description">
						<?php
						printf(
							/* translators: 1: max_execution_time, 2: budget calcolato. */
							esc_html__( 'Il batch si ferma prima di questo limite e riprende dalla richiesta successiva, cosi\' non si va mai in timeout. max_execution_time del server: %1$ds, budget attuale: %2$ds.', 'fs3d-image-optimizer' ),
							(int) $server['max_execution_time'],
							(int) FS3D_IO_Settings::batch_time_budget()
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="fs3d-exclusions"><?php esc_html_e( 'Esclusioni', 'fs3d-image-optimizer' ); ?></label></th>
				<td>
					<textarea id="fs3d-exclusions" name="exclusions" rows="6" class="large-text code"
						placeholder="2019/*&#10;*-logo.png&#10;fiere/stand/*"><?php echo esc_textarea( implode( "\n", (array) $settings['exclusions'] ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Un pattern per riga, relativo alla cartella uploads. Si possono usare * e ?. Esempi: "2019/*" esclude tutto l\'anno 2019, "*-logo.png" esclude tutti i loghi.', 'fs3d-image-optimizer' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<?php submit_button( __( 'Salva impostazioni', 'fs3d-image-optimizer' ) ); ?>
</form>

<div class="fs3d-io-card fs3d-io-card--danger">
	<h2><?php esc_html_e( 'Reset completo', 'fs3d-image-optimizer' ); ?></h2>

	<p>
		<?php esc_html_e( 'Riporta il sito allo stato iniziale: elimina tutti i file WebP/AVIF generati dal plugin, rimuove le regole dal .htaccess (facendone prima un backup) e azzera i dati di ottimizzazione.', 'fs3d-image-optimizer' ); ?>
	</p>
	<p>
		<strong><?php esc_html_e( 'Cosa NON viene toccato:', 'fs3d-image-optimizer' ); ?></strong>
		<?php esc_html_e( 'le immagini originali, i link nei post, le opzioni di Avada e le eventuali immagini WebP che hai caricato tu a mano nella libreria.', 'fs3d-image-optimizer' ); ?>
	</p>

	<?php
	$progress_id = 'fs3d-io-progress-reset';
	require FS3D_IO_PATH . 'admin/views/partial-progress.php';
	?>

	<p>
		<button type="button" class="button button-link-delete" id="fs3d-io-reset"
			data-target="#fs3d-io-progress-reset">
			<?php esc_html_e( 'Esegui reset completo', 'fs3d-image-optimizer' ); ?>
		</button>
	</p>
</div>
