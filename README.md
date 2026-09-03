# FS3D Image Optimizer

Plugin WordPress per convertire le immagini della libreria in **WebP/AVIF** senza mai
toccare gli originali e senza modificare nemmeno un URL nel database.

Nasce per [francystore3d.it](https://francystore3d.it) — WordPress + Avada su hosting
condiviso Aruba — come alternativa gratuita e autogestita alla conversione automatica
dei servizi a pagamento.

## I tre principi su cui è costruito

1. **Il database non si tocca.** Avada salva gli URL delle immagini sia dentro
   `post_content` sia dentro opzioni PHP serializzate, dove ogni stringa porta scritta
   la propria lunghezza (`s:42:"..."`). Una sostituzione di testo "a mano" rompe i dati
   serializzati. Qui non si sostituisce niente: i link restano quelli di sempre.
2. **L'originale non si sovrascrive mai.** Il file convertito viene creato *accanto*
   all'originale. Non esiste alcuna opzione per sovrascrivere o rinominare: il codice
   rifiuta esplicitamente qualsiasi conversione in cui il percorso di destinazione
   coincida con quello del sorgente.
3. **Il file giusto lo sceglie il server.** Il browser dichiara in ogni richiesta quali
   formati supporta (header `Accept`). Un blocco di regole in `.htaccess` dice ad Apache:
   se il browser accetta WebP e accanto al file richiesto esiste la versione WebP, servi
   quella. L'URL richiesto resta `.jpg`, il byte servito è WebP.

## Cosa fa

* **Stato del server** — versione PHP, GD e Imagick con supporto WebP/AVIF, `memory_limit`,
  `max_execution_time`, presenza di `mod_rewrite`/`mod_headers` e dimensione di batch
  consigliata calcolata su quei limiti.
* **Conversione** — WebP (default), AVIF o entrambi; qualità regolabile per formato;
  rimozione dei metadata EXIF mantenendo il profilo colore ICC; scarto automatico delle
  conversioni che non fanno risparmiare abbastanza.
* **Automazione sui nuovi upload** — hook su `wp_generate_attachment_metadata`, con
  scelta tra solo il file principale o anche tutte le thumbnail generate da WordPress.
* **Libreria esistente** — vista filtrabile per formato, stato e peso, selezione multipla,
  conversione a batch via AJAX con barra di avanzamento e riepilogo finale.
* **Regole `.htaccess`** — attivazione/disattivazione con un clic, backup del file prima
  di ogni scrittura, anteprima del blocco e verifica reale con richiesta HTTP.
* **Statistiche e log** — copertura della libreria, spazio risparmiato, registro delle
  ultime 200 operazioni con gli errori.
* **Reset completo** — elimina i file generati, rimuove le regole e riporta tutto a zero.

## Installazione

I file del plugin stanno nella **root del repository**, non in una sottocartella: lo zip
scaricato da GitHub è quindi installabile direttamente, senza doverlo ricomporre a mano.

1. Su GitHub: **Code → Download ZIP**.
2. WordPress → Plugin → Aggiungi nuovo → **Carica plugin** → scegli lo zip → Installa → Attiva.
3. Menu **Ottimizza immagini** nella barra laterale.

WordPress chiamerà la cartella del plugin come lo zip, quindi `Image_Optimization-main`.
Funziona benissimo così — nessun percorso è scritto a mano nel codice — ma se preferisci
un nome pulito, via FTP rinomina `wp-content/plugins/Image_Optimization-main` in
`fs3d-image-optimizer` mentre il plugin è disattivato, poi riattivalo.

In alternativa, via FTP: copia il contenuto del repository dentro una cartella
`wp-content/plugins/fs3d-image-optimizer/`, in modo che `fs3d-image-optimizer.php` si trovi
al primo livello di quella cartella. È il file con l'header del plugin: WordPress lo cerca
lì e solo lì.

L'attivazione **non** scrive niente nel `.htaccess` e non converte niente: ogni azione
che tocca il sito parte solo da un clic esplicito.

## Rollout consigliato su un sito in produzione

Il sito è live, quindi conviene procedere per gradi e verificare a ogni passo.

1. **Backup.** Fai un backup completo (file + database) dal pannello Aruba prima di
   iniziare. Il plugin non modifica il database, ma un backup prima di un cambiamento
   strutturale è sempre la mossa giusta.
2. **Controlla lo stato del server.** Tab *Stato*: serve almeno GD o Imagick con supporto
   WebP. Se il motore risulta assente, chiedi all'assistenza Aruba di abilitarlo.
3. **Allinea il batch ai limiti del server.** Tab *Impostazioni* → Avanzate: usa il valore
   consigliato mostrato nella tab *Stato*. Con `memory_limit` basso, meglio 3-5 immagini
   per richiesta.
4. **Prova su poche immagini.** Tab *Libreria*: filtra per "Da ottimizzare", seleziona
   5-10 immagini e lancia la conversione. Controlla il riepilogo e il log.
5. **Attiva le regole e verificale.** Tab *Regole .htaccess* → *Attiva le regole*, poi
   *Verifica che funzionino davvero*: il plugin fa due richieste HTTP reali, una che
   dichiara il supporto WebP e una che non lo dichiara, e controlla che il server
   risponda con il file corretto in entrambi i casi.
6. **Controlla il sito a occhio.** Apri home, una scheda prodotto e una galleria Avada.
   Con gli strumenti sviluppatore, scheda Rete, il `Content-Type` delle immagini deve
   essere `image/webp` mentre l'URL resta `.jpg`.
7. **Solo a quel punto, converti tutto il resto** a batch dalla tab *Libreria*.

Se qualcosa non va, *Disattiva le regole*: il sito torna immediatamente a servire gli
originali, che non sono mai stati toccati.

## Nome dei file generati

| Modalità | Originale | File generato | Note |
|---|---|---|---|
| `foto.jpg.webp` (default) | `foto.jpg` | `foto.jpg.webp` | Nessun rischio di collisione |
| `foto.webp` | `foto.jpg` | `foto.webp` | Estensione sostituita |

La modalità con estensione sostituita è quella descritta nel brief originale, ma ha un
caso limite reale: se nella stessa cartella esistono `foto.jpg` e `foto.png` — cosa che
WordPress permette — entrambi punterebbero a `foto.webp` e il server finirebbe per
servire l'immagine sbagliata. Il plugin rileva la collisione e salta quei file, ma la
modalità con suffisso evita il problema alla radice ed è quella impostata di default.

Cambiando modalità a regole attive, il blocco `.htaccess` viene riscritto automaticamente
per restare coerente.

## Le regole scritte in `.htaccess`

Vengono scritte **solo** in `wp-content/uploads/.htaccess`, delimitate da marcatori. Il
`.htaccess` principale di WordPress non viene mai toccato, e il contenuto preesistente
del file in uploads viene preservato.

```apache
# BEGIN FS3D Image Optimizer
<IfModule mod_rewrite.c>
	RewriteEngine On

	# WEBP
	RewriteCond %{HTTP_ACCEPT} image/webp
	RewriteCond %{REQUEST_FILENAME} -f
	RewriteCond %{REQUEST_FILENAME}.webp -f
	RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [NC,T=image/webp,E=FS3DIO_WEBP:1,L]
</IfModule>
# END FS3D Image Optimizer
```

Prima di ogni scrittura viene creata una copia del file in
`wp-content/uploads/fs3d-io-backups/`, cartella protetta dall'accesso web, con gli ultimi
10 backup ripristinabili con un clic dalla tab *Regole .htaccess*.

`Vary: Accept` viene applicato anche alle estensioni `.webp`/`.avif`: dopo un rewrite
interno Apache valuta `FilesMatch` sul file effettivamente servito, quindi limitarlo a
`.jpg`/`.png` avrebbe lasciato senza `Vary` proprio le risposte convertite — quelle in cui
un proxy o una CDN rischierebbe di servire il file sbagliato al browser sbagliato.

## Test

I punti più delicati — conversione dei file e scrittura del `.htaccess` — hanno una suite
che gira in una sandbox temporanea, senza WordPress e senza toccare nessun sito:

```bash
php tests/smoke-test.php
```

52 controlli, tra cui: l'originale resta identico byte per byte dopo la conversione, la
destinazione non coincide mai con il sorgente, le collisioni di nome vengono rilevate, le
conversioni non convenienti vengono scartate e il file vecchio rimosso, i file fuori dalla
cartella uploads vengono rifiutati, il contenuto preesistente del `.htaccess` sopravvive a
scrittura e rimozione, e il blocco non si duplica se lo si riscrive.

## Struttura

```
.
├── fs3d-image-optimizer.php     Bootstrap e hook (file con l'header del plugin)
├── uninstall.php                Pulizia alla disinstallazione
├── includes/
│   ├── class-fs3d-io-settings.php     Impostazioni e sanitizzazione
│   ├── class-fs3d-io-server.php       Capacità e limiti del server
│   ├── class-fs3d-io-converter.php    Conversione di un singolo file
│   ├── class-fs3d-io-attachment.php   Allegato: originale + thumbnail
│   ├── class-fs3d-io-library.php      Query e filtri sulla libreria
│   ├── class-fs3d-io-htaccess.php     Regole, backup, ripristino
│   ├── class-fs3d-io-verifier.php     Verifica HTTP reale
│   ├── class-fs3d-io-stats.php        Statistiche aggregate
│   ├── class-fs3d-io-logger.php       Log delle operazioni
│   ├── class-fs3d-io-ajax.php         Endpoint AJAX e code a batch
│   └── class-fs3d-io-admin.php        Menu, tab, salvataggio
├── admin/
│   ├── views/                   Viste delle tab
│   ├── css/admin.css
│   └── js/admin.js
├── languages/                   File di traduzione (.mo/.po)
└── tests/                       Eseguibili solo da riga di comando
    ├── wp-stubs.php             Stub minimi delle funzioni WordPress
    └── smoke-test.php           Test isolati
```

## Se metti mano al codice

Due cose che sembrano innocue e non lo sono.

**I marcatori del blocco `.htaccess`**, in `includes/class-fs3d-io-htaccess.php`:

```php
const MARKER_START = '# BEGIN FS3D Image Optimizer';
const MARKER_END   = '# END FS3D Image Optimizer';
```

Portano ancora il nome vecchio ed è voluto. Quelle righe sono già scritte dentro
`wp-content/uploads/.htaccess` sui siti dove il plugin è attivo: cambiandole, il
plugin non riconoscerebbe più il blocco esistente, non riuscirebbe a rimuoverlo e
ne scriverebbe un secondo, lasciando due blocchi di rewrite nello stesso file.
Vanno toccate solo insieme a una migrazione che rimuove prima il blocco con i
marcatori vecchi — e non ne vale la pena, sono righe che vede solo chi apre il
file via FTP.

**Prefissi e chiavi dei dati**: il prefisso `FS3D_IO_`, il text domain
`fs3d-image-optimizer`, lo slug del menu, i nomi delle option (`fs3d_io_settings`,
`fs3d_io_stats`, ...) e le chiavi postmeta (`_fs3d_io_data`, `_fs3d_io_status`).
Rinominarli farebbe perdere impostazioni, statistiche e stato di ottimizzazione
dell'intera libreria: il plugin ripartirebbe da zero e considererebbe "da fare"
immagini già convertite. Sono identificatori interni, l'utente non li vede mai.

I nomi visibili sono altri: l'header `Plugin Name`, e in
`includes/class-fs3d-io-admin.php` il titolo della pagina e la voce di menu.

## Note operative

* **GIF ed SVG non vengono trattati.** Le GIF animate in WebP sono fragili e gli SVG sono
  già vettoriali: entrambi restano come sono.
* **Immagini `-scaled`.** Con la modalità "big image" di WordPress viene convertito il file
  effettivamente linkato nelle pagine, non l'originale full-size che non compare mai negli URL.
* **Eliminazione di un allegato.** I file generati vengono rimossi insieme all'allegato,
  senza lasciare orfani in uploads.
* **Disattivazione del plugin.** Rimuove le regole `.htaccess` ma lascia i file convertiti
  al loro posto: riattivandolo si riparte da dove si era rimasti.
* **Disinstallazione.** Cancella impostazioni, log e metadati e rimuove le regole, ma non i
  file generati. Per una pulizia totale, lancia prima *Reset completo*.
