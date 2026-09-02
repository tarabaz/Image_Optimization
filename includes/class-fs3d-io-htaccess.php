<?php
/**
 * Gestione delle regole .htaccess per la content negotiation.
 *
 * Le regole vengono scritte nel .htaccess della cartella uploads, non nella root:
 * il raggio d'azione resta limitato ai file media e il .htaccess di WordPress
 * non viene mai toccato.
 *
 * @package FS3D_Image_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scrittore delle regole di rewrite, con backup obbligatorio prima di ogni modifica.
 */
class FS3D_IO_Htaccess {

	/**
	 * Marcatore di apertura del blocco.
	 */
	const MARKER_START = '# BEGIN FS3D Image Optimizer';

	/**
	 * Marcatore di chiusura del blocco.
	 */
	const MARKER_END = '# END FS3D Image Optimizer';

	/**
	 * Numero di backup conservati.
	 */
	const MAX_BACKUPS = 10;

	/**
	 * Percorso del .htaccess gestito.
	 *
	 * @return string
	 */
	public static function file_path() {
		return FS3D_IO_Converter::uploads_basedir() . '/.htaccess';
	}

	/**
	 * Cartella dei backup (protetta da accesso web).
	 *
	 * @return string
	 */
	public static function backup_dir() {
		return FS3D_IO_Converter::uploads_basedir() . '/fs3d-io-backups';
	}

	/**
	 * Il file .htaccess e' scrivibile (o creabile)?
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$file = self::file_path();

		if ( file_exists( $file ) ) {
			return wp_is_writable( $file );
		}

		return wp_is_writable( dirname( $file ) );
	}

	/**
	 * Le regole del plugin sono presenti nel file?
	 *
	 * @return bool
	 */
	public static function has_rules() {
		$content = self::read();

		return ( '' !== $content && false !== strpos( $content, self::MARKER_START ) );
	}

	/**
	 * Il blocco presente corrisponde alle impostazioni attuali?
	 *
	 * @return bool
	 */
	public static function rules_are_current() {
		if ( ! self::has_rules() ) {
			return false;
		}

		return self::normalize( self::extract_block( self::read() ) ) === self::normalize( self::build_rules() );
	}

	/**
	 * Normalizza il testo per il confronto (fine riga e spazi finali).
	 *
	 * @param string $text Testo.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );

		return trim( preg_replace( '/[ \t]+$/m', '', $text ) );
	}

	/**
	 * Legge il file .htaccess.
	 *
	 * @return string Stringa vuota se assente o illeggibile.
	 */
	public static function read() {
		$file = self::file_path();

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return '';
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return false === $content ? '' : $content;
	}

	/**
	 * Estrae il blocco delimitato dai marcatori.
	 *
	 * @param string $content Contenuto del file.
	 * @return string
	 */
	public static function extract_block( $content ) {
		$pattern = '/' . preg_quote( self::MARKER_START, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . '/s';

		if ( preg_match( $pattern, (string) $content, $matches ) ) {
			return $matches[0];
		}

		return '';
	}

	/**
	 * Rimuove il blocco del plugin dal contenuto, lasciando intatto il resto.
	 *
	 * @param string $content Contenuto del file.
	 * @return string
	 */
	public static function strip_block( $content ) {
		$pattern = '/\n*' . preg_quote( self::MARKER_START, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . '\n*/s';
		$clean   = preg_replace( $pattern, "\n", (string) $content );

		return ltrim( (string) $clean, "\n" );
	}

	/**
	 * Costruisce il blocco di regole in base alle impostazioni attuali.
	 *
	 * @return string
	 */
	public static function build_rules() {
		$naming  = FS3D_IO_Settings::get( 'naming', 'suffix' );
		$formats = FS3D_IO_Settings::output_formats();

		$lines   = array();
		$lines[] = self::MARKER_START;
		$lines[] = '# Generato da FS3D Image Optimizer ' . FS3D_IO_VERSION . '. Non modificare a mano:';
		$lines[] = '# il blocco viene riscritto dal plugin. Gli URL delle immagini restano invariati,';
		$lines[] = '# qui si sceglie solo QUALE file servire in base all\'header Accept del browser.';
		$lines[] = '';
		$lines[] = '<IfModule mod_mime.c>';
		$lines[] = '	AddType image/webp .webp';
		$lines[] = '	AddType image/avif .avif';
		$lines[] = '</IfModule>';
		$lines[] = '';
		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = '	RewriteEngine On';

		// AVIF prima di WebP: se il browser supporta entrambi vince il formato piu' compresso.
		if ( in_array( 'avif', $formats, true ) ) {
			$lines = array_merge( $lines, self::format_rules( 'avif', $naming ) );
		}

		if ( in_array( 'webp', $formats, true ) ) {
			$lines = array_merge( $lines, self::format_rules( 'webp', $naming ) );
		}

		$lines[] = '</IfModule>';
		$lines[] = '';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '	# Fondamentale con proxy e CDN: la risposta cambia in base ad Accept.';
		$lines[] = '	# FilesMatch valuta il file effettivamente servito, quindi dopo il rewrite';
		$lines[] = '	# vede .webp/.avif: vanno elencate tutte le estensioni coinvolte.';
		$lines[] = '	<FilesMatch "\.(jpe?g|png|webp|avif)$">';
		$lines[] = '		Header append Vary Accept';
		$lines[] = '	</FilesMatch>';
		$lines[] = '	# Header diagnostico usato dal pulsante "Verifica" del plugin.';
		$lines[] = '	# Dopo un rewrite interno Apache espone le variabili anche con prefisso REDIRECT_.';
		$lines[] = '	Header set X-FS3D-IO "webp" env=FS3DIO_WEBP';
		$lines[] = '	Header set X-FS3D-IO "webp" env=REDIRECT_FS3DIO_WEBP';
		$lines[] = '	Header set X-FS3D-IO "avif" env=FS3DIO_AVIF';
		$lines[] = '	Header set X-FS3D-IO "avif" env=REDIRECT_FS3DIO_AVIF';
		$lines[] = '</IfModule>';
		$lines[] = self::MARKER_END;

		return implode( "\n", $lines );
	}

	/**
	 * Regole di rewrite per un singolo formato.
	 *
	 * @param string $format webp|avif.
	 * @param string $naming suffix|replace.
	 * @return string[]
	 */
	private static function format_rules( $format, $naming ) {
		$env   = 'FS3DIO_' . strtoupper( $format );
		$mime  = 'image/' . $format;
		$lines = array();

		$lines[] = '';
		$lines[] = '	# ' . strtoupper( $format );
		$lines[] = '	RewriteCond %{HTTP_ACCEPT} ' . $mime;

		if ( 'replace' === $naming ) {
			// foto.jpg -> foto.webp: ricaviamo il percorso base dal filename richiesto.
			$lines[] = '	RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png)$ [NC]';
			$lines[] = '	RewriteCond %1.' . $format . ' -f';
			$lines[] = '	RewriteRule ^(.+)\.(jpe?g|png)$ $1.' . $format . ' [NC,T=' . $mime . ',E=' . $env . ':1,L]';
		} else {
			// foto.jpg -> foto.jpg.webp.
			$lines[] = '	RewriteCond %{REQUEST_FILENAME} -f';
			$lines[] = '	RewriteCond %{REQUEST_FILENAME}.' . $format . ' -f';
			$lines[] = '	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.' . $format . ' [NC,T=' . $mime . ',E=' . $env . ':1,L]';
		}

		return $lines;
	}

	/**
	 * Attiva (scrive o aggiorna) le regole.
	 *
	 * @return array {
	 *     @type bool   $success Esito.
	 *     @type string $message Messaggio leggibile.
	 *     @type string $backup  Percorso del backup creato.
	 * }
	 */
	public static function add_rules() {
		$file = self::file_path();

		if ( ! is_dir( dirname( $file ) ) ) {
			return self::result( false, __( 'Cartella uploads non trovata.', 'fs3d-image-optimizer' ) );
		}

		if ( ! self::is_writable() ) {
			return self::result( false, __( 'Il file .htaccess della cartella uploads non e\' scrivibile. Controlla i permessi (di solito 644).', 'fs3d-image-optimizer' ) );
		}

		$current = self::read();
		$backup  = self::backup( $current );
		$clean   = self::strip_block( $current );
		$block   = self::build_rules();

		// Il blocco va in testa: deve valutare la richiesta prima di eventuali altre regole.
		$new = $block . "\n\n" . ltrim( $clean, "\n" );
		$new = rtrim( $new, "\n" ) . "\n";

		if ( ! self::write( $new ) ) {
			return self::result( false, __( 'Scrittura del .htaccess fallita.', 'fs3d-image-optimizer' ), $backup );
		}

		FS3D_IO_Logger::add( 'success', __( 'Regole .htaccess attivate.', 'fs3d-image-optimizer' ), array( 'file' => '.htaccess' ) );
		FS3D_IO_Logger::flush();

		return self::result( true, __( 'Regole attivate correttamente.', 'fs3d-image-optimizer' ), $backup );
	}

	/**
	 * Rimuove le regole del plugin.
	 *
	 * @return array
	 */
	public static function remove_rules() {
		$file = self::file_path();

		if ( ! file_exists( $file ) ) {
			return self::result( true, __( 'Nessuna regola da rimuovere.', 'fs3d-image-optimizer' ) );
		}

		$current = self::read();

		if ( false === strpos( $current, self::MARKER_START ) ) {
			return self::result( true, __( 'Nessuna regola del plugin presente nel file.', 'fs3d-image-optimizer' ) );
		}

		if ( ! wp_is_writable( $file ) ) {
			return self::result( false, __( 'Il file .htaccess non e\' scrivibile: impossibile rimuovere le regole.', 'fs3d-image-optimizer' ) );
		}

		$backup = self::backup( $current );
		$clean  = self::strip_block( $current );

		// Se restava solo il nostro blocco, eliminiamo il file invece di lasciarne uno vuoto.
		if ( '' === trim( $clean ) ) {
			if ( ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return self::result( false, __( 'Impossibile eliminare il file .htaccess.', 'fs3d-image-optimizer' ), $backup );
			}
		} elseif ( ! self::write( rtrim( $clean, "\n" ) . "\n" ) ) {
			return self::result( false, __( 'Scrittura del .htaccess fallita.', 'fs3d-image-optimizer' ), $backup );
		}

		FS3D_IO_Logger::add( 'info', __( 'Regole .htaccess rimosse.', 'fs3d-image-optimizer' ), array( 'file' => '.htaccess' ) );
		FS3D_IO_Logger::flush();

		return self::result( true, __( 'Regole rimosse correttamente.', 'fs3d-image-optimizer' ), $backup );
	}

	/**
	 * Scrittura atomica del file.
	 *
	 * @param string $content Contenuto completo.
	 * @return bool
	 */
	private static function write( $content ) {
		$file = self::file_path();
		$tmp  = $file . '.fs3dtmp';

		$bytes = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $bytes ) {
			return false;
		}

		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return false;
		}

		@chmod( $file, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return true;
	}

	/**
	 * Crea un backup del contenuto attuale prima di ogni modifica.
	 *
	 * @param string $content Contenuto da salvare.
	 * @return string Percorso del backup, stringa vuota se non creato.
	 */
	public static function backup( $content ) {
		if ( '' === (string) $content ) {
			return '';
		}

		$dir = self::backup_dir();

		if ( ! self::prepare_backup_dir( $dir ) ) {
			return '';
		}

		$name = 'htaccess-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.bak';
		$path = $dir . '/' . $name;

		$written = file_put_contents( $path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $written ) {
			return '';
		}

		self::prune_backups( $dir );

		return $path;
	}

	/**
	 * Crea la cartella dei backup e la protegge dall'accesso web.
	 *
	 * @param string $dir Percorso.
	 * @return bool
	 */
	private static function prepare_backup_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$guard = $dir . '/.htaccess';

		if ( ! file_exists( $guard ) ) {
			$rules = "# Cartella privata: nessun accesso dal web.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

			file_put_contents( $guard, $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $dir . '/index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return true;
	}

	/**
	 * Mantiene solo gli ultimi backup.
	 *
	 * @param string $dir Cartella.
	 * @return void
	 */
	private static function prune_backups( $dir ) {
		$files = glob( $dir . '/htaccess-*.bak' );

		if ( ! is_array( $files ) || count( $files ) <= self::MAX_BACKUPS ) {
			return;
		}

		sort( $files );

		$excess = array_slice( $files, 0, count( $files ) - self::MAX_BACKUPS );

		foreach ( $excess as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Elenco dei backup disponibili, dal piu' recente.
	 *
	 * @return array[] Ogni voce: path, name, time, size.
	 */
	public static function list_backups() {
		$files = glob( self::backup_dir() . '/htaccess-*.bak' );

		if ( ! is_array( $files ) ) {
			return array();
		}

		rsort( $files );

		$out = array();

		foreach ( $files as $file ) {
			$out[] = array(
				'path' => $file,
				'name' => basename( $file ),
				'time' => filemtime( $file ),
				'size' => filesize( $file ),
			);
		}

		return $out;
	}

	/**
	 * Ripristina un backup (creando prima un backup dello stato attuale).
	 *
	 * @param string $name Nome del file di backup.
	 * @return array
	 */
	public static function restore_backup( $name ) {
		$name = basename( (string) $name );
		$path = self::backup_dir() . '/' . $name;

		if ( ! preg_match( '/^htaccess-[0-9]{8}-[0-9]{6}-[A-Za-z0-9]+\.bak$/', $name ) || ! file_exists( $path ) ) {
			return self::result( false, __( 'Backup non trovato.', 'fs3d-image-optimizer' ) );
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return self::result( false, __( 'Backup illeggibile.', 'fs3d-image-optimizer' ) );
		}

		self::backup( self::read() );

		if ( ! self::write( $content ) ) {
			return self::result( false, __( 'Ripristino fallito: file non scrivibile.', 'fs3d-image-optimizer' ) );
		}

		FS3D_IO_Logger::add( 'info', sprintf( /* translators: %s: nome del backup. */ __( 'Ripristinato il backup %s.', 'fs3d-image-optimizer' ), $name ) );
		FS3D_IO_Logger::flush();

		return self::result( true, __( 'Backup ripristinato.', 'fs3d-image-optimizer' ) );
	}

	/**
	 * Costruisce un risultato standard.
	 *
	 * @param bool   $success Esito.
	 * @param string $message Messaggio.
	 * @param string $backup  Percorso backup.
	 * @return array
	 */
	private static function result( $success, $message, $backup = '' ) {
		return array(
			'success' => (bool) $success,
			'message' => (string) $message,
			'backup'  => $backup ? basename( $backup ) : '',
		);
	}
}
