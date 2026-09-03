<?php
/**
 * Wrapper della pagina di amministrazione.
 *
 * @package FS3D_Image_Optimizer
 *
 * @var string $tab      Tab attiva.
 * @var array  $tabs     Elenco delle tab.
 * @var array  $settings Impostazioni correnti.
 * @var array  $server   Report del server.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap fs3d-io">
	<h1 class="fs3d-io__title">
		<span class="dashicons dashicons-format-image"></span>
		<?php esc_html_e( 'Francy Image Optimizer', 'fs3d-image-optimizer' ); ?>
	</h1>

	<p class="fs3d-io__lead">
		<?php esc_html_e( 'Genera versioni WebP/AVIF accanto agli originali e le serve tramite .htaccess. Nessun URL viene modificato: i link salvati nei post e nelle opzioni di Avada restano esattamente com\'erano.', 'fs3d-image-optimizer' ); ?>
	</p>

	<?php FS3D_IO_Admin::render_notice(); ?>

	<nav class="nav-tab-wrapper fs3d-io__tabs">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a href="<?php echo esc_url( FS3D_IO_Admin::tab_url( $slug ) ); ?>"
				class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="fs3d-io__content">
		<?php
		$view = FS3D_IO_PATH . 'admin/views/tab-' . $tab . '.php';

		if ( file_exists( $view ) ) {
			include $view;
		}
		?>
	</div>
</div>
