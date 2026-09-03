<?php
/**
 * Plugin Name:       Francy Image Optimizer
 * Plugin URI:        https://francystore3d.it/
 * Description:       Converte le immagini della libreria in WebP/AVIF affiancando i file agli originali (mai sovrascritti) e li serve via content negotiation con .htaccess. Nessun URL viene modificato nel database.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            FrancyStore3D
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fs3d-image-optimizer
 * Domain Path:       /languages
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'FS3D_IO_VERSION', '1.0.0' );
define( 'FS3D_IO_FILE', __FILE__ );
define( 'FS3D_IO_PATH', plugin_dir_path( __FILE__ ) );
define( 'FS3D_IO_URL', plugin_dir_url( __FILE__ ) );
define( 'FS3D_IO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Nomi delle option e delle meta usate dal plugin.
 */
define( 'FS3D_IO_OPT_SETTINGS', 'fs3d_io_settings' );
define( 'FS3D_IO_OPT_STATS', 'fs3d_io_stats' );
define( 'FS3D_IO_OPT_LOG', 'fs3d_io_log' );
define( 'FS3D_IO_OPT_QUEUE', 'fs3d_io_queue' );
define( 'FS3D_IO_OPT_QUEUE_META', 'fs3d_io_queue_meta' );
define( 'FS3D_IO_META_DATA', '_fs3d_io_data' );
define( 'FS3D_IO_META_STATUS', '_fs3d_io_status' );

require_once FS3D_IO_PATH . 'includes/class-fs3d-io-settings.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-server.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-logger.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-converter.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-attachment.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-library.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-htaccess.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-verifier.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-stats.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-ajax.php';
require_once FS3D_IO_PATH . 'includes/class-fs3d-io-admin.php';

/**
 * Bootstrap del plugin.
 */
final class FS3D_IO_Plugin {

	/**
	 * Istanza singleton.
	 *
	 * @var FS3D_IO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Restituisce l'istanza singleton.
	 *
	 * @return FS3D_IO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Aggancia gli hook.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Automazione sui nuovi upload.
		add_filter( 'wp_generate_attachment_metadata', array( 'FS3D_IO_Attachment', 'on_generate_metadata' ), 20, 3 );

		// Pulizia dei file generati quando l'allegato viene eliminato.
		add_action( 'delete_attachment', array( 'FS3D_IO_Attachment', 'on_delete_attachment' ), 10, 1 );

		if ( is_admin() ) {
			FS3D_IO_Admin::init();
			FS3D_IO_Ajax::init();
		}
	}

	/**
	 * Carica le traduzioni.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'fs3d-image-optimizer', false, dirname( FS3D_IO_BASENAME ) . '/languages' );
	}

	/**
	 * Attivazione: crea le option di default. Non scrive mai .htaccess in automatico.
	 *
	 * @return void
	 */
	public static function activate() {
		FS3D_IO_Settings::install_defaults();
		FS3D_IO_Stats::install_defaults();
	}

	/**
	 * Disattivazione: rimuove le regole .htaccess per non lasciare rewrite orfane,
	 * ma non tocca nessun file immagine generato.
	 *
	 * @return void
	 */
	public static function deactivate() {
		FS3D_IO_Htaccess::remove_rules();
		delete_option( FS3D_IO_OPT_QUEUE );
		delete_option( FS3D_IO_OPT_QUEUE_META );
	}
}

register_activation_hook( __FILE__, array( 'FS3D_IO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FS3D_IO_Plugin', 'deactivate' ) );

FS3D_IO_Plugin::instance();
