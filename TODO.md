# Da fare alla prossima modifica

## Rinominare il plugin in "Francy Image Optimizer"

Richiesto da Valerio. Da applicare quando si mette mano al codice per altro,
non serve un intervento dedicato.

### Stringhe da cambiare

| File | Riga | Adesso | Diventa |
|---|---|---|---|
| `fs3d-image-optimizer.php` | 3 | `Plugin Name: FS3D Image Optimizer` | `Plugin Name: Francy Image Optimizer` |
| `includes/class-fs3d-io-admin.php` | 48 | `'Ottimizzazione immagini'` (titolo pagina) | `'Francy Image Optimizer'` |
| `includes/class-fs3d-io-admin.php` | 49 | `'Ottimizza immagini'` (voce di menu) | `'Francy Image Optimizer'` |
| `admin/views/page.php` | 18 | `'Ottimizzazione immagini'` (intestazione H1) | `'Francy Image Optimizer'` |

### Cosa NON toccare, e perché

**I marcatori `.htaccess`** in `includes/class-fs3d-io-htaccess.php`:

```php
const MARKER_START = '# BEGIN FS3D Image Optimizer';
const MARKER_END   = '# END FS3D Image Optimizer';
```

Sono già scritti dentro `wp-content/uploads/.htaccess` sul sito in produzione.
Cambiandoli, il plugin non riconoscerebbe più il blocco esistente: non riuscirebbe
a rimuoverlo e ne aggiungerebbe un secondo, lasciando due blocchi di rewrite nel
file. Vanno cambiati solo insieme a una migrazione che rimuove prima il blocco
vecchio con i marcatori vecchi, e non ne vale la pena: quelle righe non le vede
nessuno tranne chi apre il file via FTP.

**Prefissi, slug e chiavi dei dati:**

* prefisso classi `FS3D_IO_` e costanti `FS3D_IO_*`
* text domain `fs3d-image-optimizer`
* slug del menu `FS3D_IO_Admin::PAGE_SLUG`
* nomi delle option (`fs3d_io_settings`, `fs3d_io_stats`, `fs3d_io_log`, ...)
* chiavi postmeta (`_fs3d_io_data`, `_fs3d_io_status`)

Rinominarli farebbe perdere impostazioni salvate, statistiche e lo stato di
ottimizzazione di tutta la libreria: il plugin ripartirebbe da zero e
considererebbe "da fare" immagini già convertite. Sono identificatori interni,
l'utente non li vede mai.

### Da confermare con Valerio

Il nome nella lista plugin di WordPress: si presume vada allineato anche quello
a "Francy Image Optimizer", ma la richiesta parlava solo della voce di menu.

### Dopo la modifica

Rilanciare `php tests/smoke-test.php` (52 controlli): i marcatori `.htaccess`
sono coperti dai test, quindi se qualcuno li toccasse per sbaglio il test lo
segnala subito.
