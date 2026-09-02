<?php
/**
 * Pannello di avanzamento riutilizzabile per le operazioni a batch.
 *
 * @package FS3D_Image_Optimizer
 *
 * @var string $progress_id ID del pannello (opzionale).
 */

defined( 'ABSPATH' ) || exit;

$progress_id = isset( $progress_id ) && '' !== $progress_id ? $progress_id : 'fs3d-io-progress';
?>
<div class="fs3d-io-progress" id="<?php echo esc_attr( $progress_id ); ?>" hidden>
	<div class="fs3d-io-progress__head">
		<strong class="fs3d-io-progress__title"><?php esc_html_e( 'Elaborazione in corso...', 'fs3d-image-optimizer' ); ?></strong>
		<button type="button" class="button button-small fs3d-io-progress__cancel">
			<?php esc_html_e( 'Interrompi', 'fs3d-image-optimizer' ); ?>
		</button>
	</div>

	<div class="fs3d-io-progress__bar">
		<div class="fs3d-io-progress__fill" style="width:0%"></div>
	</div>

	<div class="fs3d-io-progress__stats">
		<span class="fs3d-io-progress__counter">0 / 0</span>
		<span class="fs3d-io-progress__saved"></span>
	</div>

	<ul class="fs3d-io-progress__log"></ul>

	<div class="fs3d-io-progress__summary" hidden></div>
</div>
