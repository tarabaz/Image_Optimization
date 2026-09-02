<?php
/**
 * Test isolati delle funzioni piu' rischiose: conversione file e scrittura .htaccess.
 *
 * Girano in una sandbox temporanea, senza WordPress e senza toccare il sito.
 * Uso:  php tests/smoke-test.php
 *
 * @package FS3D_Image_Optimizer
 */

// phpcs:disable

// Sicurezza: da qui in poi si esegue solo da riga di comando. Il file finisce dentro
// wp-content/plugins/, quindi deve essere inerte se qualcuno lo richiama via browser
// o se viene incluso per errore dentro WordPress.
if ( 'cli' !== PHP_SAPI || defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require __DIR__ . '/wp-stubs.php';

$root = __DIR__ . '/..';

require $root . '/includes/class-fs3d-io-settings.php';
require $root . '/includes/class-fs3d-io-server.php';
require $root . '/includes/class-fs3d-io-logger.php';
require $root . '/includes/class-fs3d-io-converter.php';
require $root . '/includes/class-fs3d-io-htaccess.php';

$passed = 0;
$failed = 0;

/**
 * Asserzione minimale.
 *
 * @param string $label     Descrizione del controllo.
 * @param bool   $condition Esito.
 * @param string $detail    Dettaglio da mostrare in caso di fallimento.
 */
function check( $label, $condition, $detail = '' ) {
	global $passed, $failed;

	if ( $condition ) {
		$passed++;
		echo "  \033[32mOK\033[0m   {$label}\n";

		return;
	}

	$failed++;
	echo "  \033[31mKO\033[0m   {$label}";
	echo '' !== $detail ? "  -> {$detail}\n" : "\n";
}

/**
 * Crea un JPEG di prova con un gradiente (ben comprimibile in WebP).
 *
 * @param string $path Destinazione.
 * @param int    $w    Larghezza.
 * @param int    $h    Altezza.
 */
function make_jpeg( $path, $w = 400, $h = 300 ) {
	$im = imagecreatetruecolor( $w, $h );

	for ( $x = 0; $x < $w; $x++ ) {
		for ( $y = 0; $y < $h; $y++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, (int) ( 255 * $x / $w ), (int) ( 255 * $y / $h ), 120 ) );
		}
	}

	imagejpeg( $im, $path, 95 );
	imagedestroy( $im );
}

/**
 * Crea un PNG minuscolo: in WebP pesera' quasi sicuramente di piu'.
 *
 * @param string $path Destinazione.
 */
function make_tiny_png( $path ) {
	$im = imagecreatetruecolor( 4, 4 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
	imagepng( $im, $path, 9 );
	imagedestroy( $im );
}

/**
 * Crea un JPEG di rumore: si comprime male, quindi il risparmio in WebP resta
 * lontano dalle soglie estreme. Serve a testare lo scarto per risparmio insufficiente.
 *
 * @param string $path Destinazione.
 */
function make_noise_jpeg( $path ) {
	$w  = 300;
	$h  = 200;
	$im = imagecreatetruecolor( $w, $h );

	mt_srand( 42 );

	for ( $x = 0; $x < $w; $x++ ) {
		for ( $y = 0; $y < $h; $y++ ) {
			imagesetpixel( $im, $x, $y, imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
		}
	}

	imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
}

// Sandbox temporanea.
$sandbox = sys_get_temp_dir() . '/fs3d-io-test-' . getmypid();
$uploads = $sandbox . '/uploads';

mkdir( $uploads . '/2026/09', 0777, true );
fs3d_test_set_uploads( $uploads );

echo "\nSandbox: {$sandbox}\n";

// ---------------------------------------------------------------------------
echo "\n[1] Percorsi di destinazione e collisioni\n";

FS3D_IO_Settings::save( array( 'naming' => 'suffix' ) );

$source = $uploads . '/2026/09/foto.jpg';
make_jpeg( $source );

check(
	'naming=suffix produce foto.jpg.webp',
	FS3D_IO_Converter::destination_path( $source, 'webp' ) === $uploads . '/2026/09/foto.jpg.webp',
	FS3D_IO_Converter::destination_path( $source, 'webp' )
);

FS3D_IO_Settings::save( array( 'naming' => 'replace' ) );

check(
	'naming=replace produce foto.webp',
	FS3D_IO_Converter::destination_path( $source, 'webp' ) === $uploads . '/2026/09/foto.webp',
	FS3D_IO_Converter::destination_path( $source, 'webp' )
);

check( 'nessuna collisione con un solo file foto.*', ! FS3D_IO_Converter::has_name_collision( $source ) );

$twin = $uploads . '/2026/09/foto.png';
make_tiny_png( $twin );

check( 'collisione rilevata quando esistono foto.jpg e foto.png', FS3D_IO_Converter::has_name_collision( $source ) );

$collision = FS3D_IO_Converter::convert( $source, 'webp' );
check(
	'in caso di collisione la conversione viene saltata',
	'skipped' === $collision['status'] && 'name_collision' === $collision['reason'],
	$collision['status'] . '/' . $collision['reason']
);

unlink( $twin );
FS3D_IO_Settings::save( array( 'naming' => 'suffix' ) );

// ---------------------------------------------------------------------------
echo "\n[2] La conversione non tocca mai l'originale\n";

$before_hash = md5_file( $source );
$before_size = filesize( $source );
$before_mtime = filemtime( $source );

$result = FS3D_IO_Converter::convert( $source, 'webp' );

check( 'conversione riuscita', 'converted' === $result['status'], $result['status'] . ' - ' . $result['message'] );
check( 'originale ancora presente', file_exists( $source ) );
check( 'originale identico byte per byte', md5_file( $source ) === $before_hash );
check( 'dimensione originale invariata', filesize( $source ) === $before_size );
check( 'data di modifica originale invariata', filemtime( $source ) === $before_mtime );
check( 'file webp creato accanto', file_exists( $source . '.webp' ) );
check( 'destinazione diversa dal sorgente', $result['dest'] !== $result['source'] );
check(
	'il file generato e\' un vero WebP',
	'image/webp' === ( function_exists( 'mime_content_type' ) ? mime_content_type( $source . '.webp' ) : 'image/webp' ),
	function_exists( 'mime_content_type' ) ? mime_content_type( $source . '.webp' ) : ''
);
check( 'il webp pesa meno dell\'originale', filesize( $source . '.webp' ) < $before_size,
	filesize( $source . '.webp' ) . ' vs ' . $before_size );
check( 'nessun file temporaneo lasciato in giro', ! file_exists( $source . '.webp.fs3dtmp' ) );

// ---------------------------------------------------------------------------
echo "\n[3] Idempotenza e riconversione forzata\n";

$again = FS3D_IO_Converter::convert( $source, 'webp' );
check(
	'seconda conversione saltata (file gia\' presente)',
	'skipped' === $again['status'] && 'already_exists' === $again['reason'],
	$again['status'] . '/' . $again['reason']
);

$forced = FS3D_IO_Converter::convert( $source, 'webp', true );
check( 'con force la conversione viene rifatta', 'converted' === $forced['status'], $forced['status'] );

// ---------------------------------------------------------------------------
echo "\n[4] Conversioni inutili e file fuori perimetro\n";

// Il rumore si comprime poco: con la soglia al massimo il risparmio resta insufficiente.
$noisy = $uploads . '/2026/09/rumore.jpg';
make_noise_jpeg( $noisy );

FS3D_IO_Settings::save( array( 'skip_if_larger' => 1, 'min_saving' => 3 ) );

$noisy_ok = FS3D_IO_Converter::convert( $noisy, 'webp' );
check( 'con soglia normale il file rumoroso viene convertito', 'converted' === $noisy_ok['status'], $noisy_ok['status'] );
check( 'webp del file rumoroso presente', file_exists( $noisy . '.webp' ) );

FS3D_IO_Settings::save( array( 'min_saving' => 90 ) );

$noisy_hash = md5_file( $noisy );
$no_gain    = FS3D_IO_Converter::convert( $noisy, 'webp', true );

check(
	'risparmio sotto la soglia: conversione scartata',
	'skipped' === $no_gain['status'] && 'no_gain' === $no_gain['reason'],
	$no_gain['status'] . '/' . $no_gain['reason']
);
check( 'nessun file temporaneo lasciato dopo lo scarto', ! file_exists( $noisy . '.webp.fs3dtmp' ) );
check(
	'il webp non conveniente viene rimosso, cosi\' il server torna a servire l\'originale',
	! file_exists( $noisy . '.webp' )
);
check( 'originale intatto anche dopo lo scarto', md5_file( $noisy ) === $noisy_hash );

FS3D_IO_Settings::save( array( 'min_saving' => 3 ) );

$outside = $sandbox . '/fuori.jpg';
make_jpeg( $outside, 60, 60 );

$rejected = FS3D_IO_Converter::convert( $outside, 'webp' );
check(
	'file fuori dalla cartella uploads rifiutato',
	'failed' === $rejected['status'] && 'outside_uploads' === $rejected['reason'],
	$rejected['status'] . '/' . $rejected['reason']
);

// ---------------------------------------------------------------------------
echo "\n[5] Esclusioni\n";

FS3D_IO_Settings::save( array( 'exclusions' => "2026/*\n*-logo.png" ) );

check( 'pattern di cartella esclude il file', FS3D_IO_Converter::is_excluded( $source ) );

FS3D_IO_Settings::save( array( 'exclusions' => "*-logo.png" ) );

$logo = $uploads . '/2026/09/francystore-logo.png';
make_tiny_png( $logo );

check( 'pattern su nome file esclude il logo', FS3D_IO_Converter::is_excluded( $logo ) );
check( 'file non corrispondente non escluso', ! FS3D_IO_Converter::is_excluded( $source ) );

$excluded = FS3D_IO_Converter::convert( $logo, 'webp' );
check(
	'il file escluso non viene convertito',
	'skipped' === $excluded['status'] && 'excluded' === $excluded['reason'],
	$excluded['status'] . '/' . $excluded['reason']
);

FS3D_IO_Settings::save( array( 'exclusions' => '' ) );

// ---------------------------------------------------------------------------
echo "\n[6] Scrittura .htaccess\n";

$htaccess = $uploads . '/.htaccess';
$foreign  = "# Regole preesistenti da non perdere\n<IfModule mod_expires.c>\n\tExpiresActive On\n</IfModule>\n";

file_put_contents( $htaccess, $foreign );

$add = FS3D_IO_Htaccess::add_rules();

check( 'attivazione regole riuscita', ! empty( $add['success'] ), $add['message'] );
check( 'regole presenti nel file', FS3D_IO_Htaccess::has_rules() );
check( 'regole allineate alle impostazioni', FS3D_IO_Htaccess::rules_are_current() );

$content = file_get_contents( $htaccess );

check( 'contenuto preesistente preservato', false !== strpos( $content, 'ExpiresActive On' ) );
check( 'blocco scritto in cima al file', 0 === strpos( $content, FS3D_IO_Htaccess::MARKER_START ) );
check( 'regola webp presente', false !== strpos( $content, 'RewriteCond %{HTTP_ACCEPT} image/webp' ) );
check( 'header Vary: Accept presente', false !== strpos( $content, 'Header append Vary Accept' ) );
check(
	'Vary applicato anche ai file gia\' riscritti (webp/avif)',
	false !== strpos( $content, 'FilesMatch "\\.(jpe?g|png|webp|avif)$"' )
);
check( 'backup creato', ! empty( $add['backup'] ), 'nessun backup' );
check( 'cartella backup protetta', file_exists( FS3D_IO_Htaccess::backup_dir() . '/.htaccess' ) );

$backups = FS3D_IO_Htaccess::list_backups();
check( 'backup elencato correttamente', 1 === count( $backups ), count( $backups ) . ' backup' );
check(
	'il backup contiene il file originale',
	file_get_contents( $backups[0]['path'] ) === $foreign
);

// Doppia attivazione: il blocco non deve duplicarsi.
FS3D_IO_Htaccess::add_rules();
$content = file_get_contents( $htaccess );

check(
	'riscrittura non duplica il blocco',
	1 === substr_count( $content, FS3D_IO_Htaccess::MARKER_START ),
	substr_count( $content, FS3D_IO_Htaccess::MARKER_START ) . ' blocchi'
);

// Cambio schema di denominazione: le regole devono cambiare forma.
FS3D_IO_Settings::save( array( 'naming' => 'replace' ) );
FS3D_IO_Htaccess::add_rules();
$content = file_get_contents( $htaccess );

check( 'naming=replace genera la regola con %1', false !== strpos( $content, 'RewriteCond %1.webp -f' ) );
check( 'naming=replace non usa la forma con doppia estensione', false === strpos( $content, '$1.$2.webp' ) );

FS3D_IO_Settings::save( array( 'naming' => 'suffix' ) );
FS3D_IO_Htaccess::add_rules();
$content = file_get_contents( $htaccess );

check( 'naming=suffix genera la regola con $1.$2', false !== strpos( $content, '$1.$2.webp' ) );

// ---------------------------------------------------------------------------
echo "\n[7] Rimozione delle regole\n";

$remove = FS3D_IO_Htaccess::remove_rules();

check( 'rimozione riuscita', ! empty( $remove['success'] ), $remove['message'] );
check( 'nessuna regola residua', ! FS3D_IO_Htaccess::has_rules() );
check(
	'file riportato esattamente al contenuto originale',
	file_get_contents( $htaccess ) === $foreign,
	var_export( file_get_contents( $htaccess ), true )
);

// File .htaccess creato da zero e poi rimosso: deve sparire, non restare vuoto.
unlink( $htaccess );
FS3D_IO_Htaccess::add_rules();
check( 'regole scritte anche senza .htaccess preesistente', FS3D_IO_Htaccess::has_rules() );

FS3D_IO_Htaccess::remove_rules();
check( 'file rimosso se conteneva solo le nostre regole', ! file_exists( $htaccess ) );

// ---------------------------------------------------------------------------
echo "\n[8] AVIF (se supportato dal server)\n";

if ( FS3D_IO_Server::supports( 'avif' ) ) {
	FS3D_IO_Settings::save( array( 'format' => 'avif', 'quality_avif' => 55 ) );

	$avif = FS3D_IO_Converter::convert( $source, 'avif' );

	check( 'conversione AVIF riuscita', 'converted' === $avif['status'], $avif['status'] . ' - ' . $avif['message'] );
	check( 'file .avif creato accanto all\'originale', file_exists( $source . '.avif' ) );
	check( 'originale ancora intatto dopo AVIF', md5_file( $source ) === $before_hash );
} else {
	echo "  --   AVIF non supportato da questo PHP: test saltato\n";
}

// ---------------------------------------------------------------------------
// Pulizia.
$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $sandbox, RecursiveDirectoryIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::CHILD_FIRST
);

foreach ( $it as $item ) {
	$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
}

rmdir( $sandbox );

echo "\n----------------------------------------\n";
echo "Test superati: {$passed} | falliti: {$failed}\n\n";

exit( $failed > 0 ? 1 : 0 );
