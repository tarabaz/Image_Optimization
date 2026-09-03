<?php
/**
 * Interfaccia di amministrazione.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu, pagine e salvataggio delle impostazioni.
 */
class FS3D_IO_Admin {

	/**
	 * Slug della pagina.
	 */
	const PAGE_SLUG = 'fs3d-image-optimizer';

	/**
	 * Hook suffix della pagina, per limitare l'enqueue degli asset.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Registra gli hook di amministrazione.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_fs3d_io_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_filter( 'plugin_action_links_' . FS3D_IO_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'media_column_content' ), 10, 2 );
	}

	/**
	 * Aggiunge la voce di menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		self::$hook = add_menu_page(
			__( 'Francy Image Optimizer', 'fs3d-image-optimizer' ),
			__( 'Francy Img webp', 'fs3d-image-optimizer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-format-image',
			81
		);
	}

	/**
	 * Link rapido nella lista plugin.
	 *
	 * @param array $links Link esistenti.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Impostazioni', 'fs3d-image-optimizer' ) . '</a>' );

		return $links;
	}

	/**
	 * Tab disponibili.
	 *
	 * @return array
	 */
	public static function tabs() {
		return array(
			'dashboard' => __( 'Stato', 'fs3d-image-optimizer' ),
			'settings'  => __( 'Impostazioni', 'fs3d-image-optimizer' ),
			'library'   => __( 'Libreria', 'fs3d-image-optimizer' ),
			'serving'   => __( 'Regole .htaccess', 'fs3d-image-optimizer' ),
			'log'       => __( 'Log', 'fs3d-image-optimizer' ),
		);
	}

	/**
	 * Tab attiva.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs = self::tabs();

		return isset( $tabs[ $tab ] ) ? $tab : 'dashboard';
	}

	/**
	 * URL di una tab.
	 *
	 * @param string $tab Slug tab.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Carica CSS e JS solo sulla pagina del plugin.
	 *
	 * @param string $hook Hook suffix corrente.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style(
			'fs3d-io-admin',
			FS3D_IO_URL . 'admin/css/admin.css',
			array(),
			FS3D_IO_VERSION
		);

		wp_enqueue_script(
			'fs3d-io-admin',
			FS3D_IO_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			FS3D_IO_VERSION,
			true
		);

		wp_localize_script(
			'fs3d-io-admin',
			'FS3DIO',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( FS3D_IO_Ajax::NONCE_ACTION ),
				'batchSize' => (int) FS3D_IO_Settings::get( 'batch_size', 8 ),
				'i18n'      => array(
					'confirmReset'   => __( 'Confermi il reset completo? Verranno eliminati tutti i file WebP/AVIF generati dal plugin e disattivate le regole .htaccess. Gli originali e gli URL non vengono toccati.', 'fs3d-image-optimizer' ),
					'confirmCancel'  => __( 'Vuoi interrompere l\'operazione in corso?', 'fs3d-image-optimizer' ),
					'confirmDeact'   => __( 'Disattivo le regole .htaccess? Il sito tornera\' a servire i file originali.', 'fs3d-image-optimizer' ),
					'noSelection'    => __( 'Seleziona almeno un\'immagine.', 'fs3d-image-optimizer' ),
					'working'        => __( 'Elaborazione in corso...', 'fs3d-image-optimizer' ),
					'done'           => __( 'Operazione completata.', 'fs3d-image-optimizer' ),
					'stopped'        => __( 'Operazione interrotta.', 'fs3d-image-optimizer' ),
					'genericError'   => __( 'Errore di comunicazione con il server. Riprova.', 'fs3d-image-optimizer' ),
					'resumePending'  => __( 'C\'e\' un\'operazione rimasta in sospeso. Vuoi riprenderla?', 'fs3d-image-optimizer' ),
					'verifying'      => __( 'Verifica in corso...', 'fs3d-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Salvataggio delle impostazioni.
	 *
	 * @return void
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'fs3d-image-optimizer' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'fs3d_io_save_settings' );

		$before = FS3D_IO_Settings::all();

		$input = array(
			'format'         => isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : 'webp',
			'quality_webp'   => isset( $_POST['quality_webp'] ) ? (int) $_POST['quality_webp'] : 80,
			'quality_avif'   => isset( $_POST['quality_avif'] ) ? (int) $_POST['quality_avif'] : 55,
			'strip_metadata' => isset( $_POST['strip_metadata'] ) ? 1 : 0,
			'skip_if_larger' => isset( $_POST['skip_if_larger'] ) ? 1 : 0,
			'min_saving'     => isset( $_POST['min_saving'] ) ? (int) $_POST['min_saving'] : 3,
			'engine'         => isset( $_POST['engine'] ) ? sanitize_key( wp_unslash( $_POST['engine'] ) ) : 'auto',
			'naming'         => isset( $_POST['naming'] ) ? sanitize_key( wp_unslash( $_POST['naming'] ) ) : 'suffix',
			'auto_optimize'  => isset( $_POST['auto_optimize'] ) ? 1 : 0,
			'process_sizes'  => isset( $_POST['process_sizes'] ) ? sanitize_key( wp_unslash( $_POST['process_sizes'] ) ) : 'all',
			'batch_size'     => isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 8,
			'time_budget'    => isset( $_POST['time_budget'] ) ? (int) $_POST['time_budget'] : 0,
			'exclusions'     => isset( $_POST['exclusions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclusions'] ) ) : '',
		);

		$after = FS3D_IO_Settings::save( $input );

		$notice = 'saved';

		// Naming e formati determinano le regole di rewrite: se sono cambiati e le
		// regole sono attive, le riscriviamo subito per non servire file inesistenti.
		$rules_affected = ( $before['naming'] !== $after['naming'] || $before['format'] !== $after['format'] );

		if ( $rules_affected && FS3D_IO_Htaccess::has_rules() ) {
			$result = FS3D_IO_Htaccess::add_rules();
			$notice = ! empty( $result['success'] ) ? 'saved_rules' : 'saved_rules_failed';
		}

		if ( $before['naming'] !== $after['naming'] ) {
			FS3D_IO_Logger::add(
				'warning',
				__( 'Schema di denominazione cambiato: i file generati con lo schema precedente restano su disco ma non vengono piu\' serviti. Esegui un reset o una nuova ottimizzazione completa.', 'fs3d-image-optimizer' )
			);
			FS3D_IO_Logger::flush();
		}

		FS3D_IO_Stats::invalidate();

		wp_safe_redirect( add_query_arg( 'fs3d_notice', $notice, self::tab_url( 'settings' ) ) );
		exit;
	}

	/**
	 * Colonna di stato nella libreria media (vista elenco).
	 *
	 * @param array $columns Colonne esistenti.
	 * @return array
	 */
	public static function media_column( $columns ) {
		$columns['fs3d_io'] = __( 'WebP/AVIF', 'fs3d-image-optimizer' );

		return $columns;
	}

	/**
	 * Contenuto della colonna di stato.
	 *
	 * @param string $column        Nome colonna.
	 * @param int    $attachment_id ID allegato.
	 * @return void
	 */
	public static function media_column_content( $column, $attachment_id ) {
		if ( 'fs3d_io' !== $column ) {
			return;
		}

		$status = FS3D_IO_Attachment::get_status( $attachment_id );
		$data   = get_post_meta( $attachment_id, FS3D_IO_META_DATA, true );

		echo '<span class="fs3d-io-badge fs3d-io-badge--' . esc_attr( $status ) . '">'
			. esc_html( FS3D_IO_Library::status_label( $status ) ) . '</span>';

		if ( is_array( $data ) && ! empty( $data['saved'] ) ) {
			echo '<br><small>-' . esc_html( size_format( (int) $data['saved'] ) ) . '</small>';
		}
	}

	/**
	 * Mostra un avviso in base al parametro in query string.
	 *
	 * @return void
	 */
	public static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['fs3d_notice'] ) ? sanitize_key( wp_unslash( $_GET['fs3d_notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'              => array( 'success', __( 'Impostazioni salvate.', 'fs3d-image-optimizer' ) ),
			'saved_rules'        => array( 'success', __( 'Impostazioni salvate e regole .htaccess aggiornate.', 'fs3d-image-optimizer' ) ),
			'saved_rules_failed' => array( 'error', __( 'Impostazioni salvate, ma l\'aggiornamento delle regole .htaccess non e\' riuscito. Controlla i permessi del file.', 'fs3d-image-optimizer' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * Render della pagina.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 'fs3d-image-optimizer' ) );
		}

		$tab      = self::current_tab();
		$tabs     = self::tabs();
		$settings = FS3D_IO_Settings::all();
		$server   = FS3D_IO_Server::report();

		include FS3D_IO_PATH . 'admin/views/page.php';
	}
}
