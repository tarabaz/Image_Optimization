/**
 * FS3D Image Optimizer - script dell'area di amministrazione.
 *
 * Le operazioni pesanti girano sempre a piccoli batch: ogni richiesta AJAX
 * elabora poche immagini e restituisce il punto in cui si e' fermata, cosi'
 * su hosting condiviso non si finisce mai in timeout.
 */
( function ( $ ) {
	'use strict';

	var i18n = ( window.FS3DIO && window.FS3DIO.i18n ) || {};
	var running = false;
	var cancelled = false;

	/**
	 * Wrapper delle chiamate AJAX del plugin.
	 *
	 * @param {string} action Suffisso dell'azione (senza prefisso fs3d_io_).
	 * @param {Object} data   Dati aggiuntivi.
	 * @return {jQuery.Deferred} Promise jQuery.
	 */
	function request( action, data ) {
		return $.post(
			window.FS3DIO.ajaxUrl,
			$.extend( { action: 'fs3d_io_' + action, nonce: window.FS3DIO.nonce }, data || {} )
		);
	}

	/**
	 * Riferimenti agli elementi di un pannello di avanzamento.
	 *
	 * @param {string} selector Selettore del pannello.
	 * @return {Object} Mappa di elementi jQuery.
	 */
	function panelOf( selector ) {
		var $panel = $( selector );

		return {
			root: $panel,
			fill: $panel.find( '.fs3d-io-progress__fill' ),
			counter: $panel.find( '.fs3d-io-progress__counter' ),
			saved: $panel.find( '.fs3d-io-progress__saved' ),
			log: $panel.find( '.fs3d-io-progress__log' ),
			summary: $panel.find( '.fs3d-io-progress__summary' ),
			title: $panel.find( '.fs3d-io-progress__title' ),
			cancel: $panel.find( '.fs3d-io-progress__cancel' )
		};
	}

	/**
	 * Prepara il pannello per una nuova operazione.
	 *
	 * @param {Object} panel Pannello.
	 * @param {number} total Totale elementi.
	 */
	function resetPanel( panel, total ) {
		panel.root.prop( 'hidden', false );
		panel.fill.css( 'width', '0%' );
		panel.counter.text( '0 / ' + total );
		panel.saved.text( '' );
		panel.log.empty();
		panel.summary.prop( 'hidden', true ).empty();
		panel.title.text( i18n.working || 'Elaborazione in corso...' );
		panel.cancel.prop( 'hidden', false ).prop( 'disabled', false );
	}

	/**
	 * Aggiunge una riga al registro visibile del pannello.
	 *
	 * @param {Object} panel Pannello.
	 * @param {Object} item  Elemento elaborato.
	 */
	function appendItem( panel, item ) {
		var $li = $( '<li/>' ).addClass( 'fs3d-io-progress__item is-' + ( item.status || 'info' ) );

		$li.append( $( '<span/>' ).addClass( 'fs3d-io-progress__name' ).text( item.name || ( '#' + item.id ) ) );
		$li.append( $( '<span/>' ).addClass( 'fs3d-io-progress__detail' ).text( item.message || '' ) );

		panel.log.prepend( $li );

		// Teniamo corta la lista: interessa solo l'attivita' recente.
		panel.log.children().slice( 40 ).remove();
	}

	/**
	 * Esegue la coda a batch fino al termine.
	 *
	 * @param {Object} panel Pannello di avanzamento.
	 */
	function runQueue( panel ) {
		if ( cancelled ) {
			finish( panel, i18n.stopped || 'Operazione interrotta.' );
			return;
		}

		request( 'process_batch' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( panel, ( response && response.data && response.data.message ) || i18n.genericError );
					return;
				}

				var data = response.data;

				panel.fill.css( 'width', data.percent + '%' );
				panel.counter.text( data.offset + ' / ' + data.total );

				if ( data.saved ) {
					panel.saved.text( '−' + data.saved );
				}

				$.each( data.items || [], function ( _, item ) {
					appendItem( panel, item );
				} );

				if ( data.done ) {
					finish( panel, summaryText( data ), true );
					return;
				}

				runQueue( panel );
			} )
			.fail( function () {
				finish( panel, i18n.genericError || 'Errore di comunicazione con il server.' );
			} );
	}

	/**
	 * Testo di riepilogo di fine operazione.
	 *
	 * @param {Object} data Payload dell'ultimo batch.
	 * @return {string} Riepilogo.
	 */
	function summaryText( data ) {
		if ( data.deleted ) {
			return ( i18n.done || 'Fatto.' ) + ' ' + data.deleted + ' file eliminati.';
		}

		return ( i18n.done || 'Fatto.' ) +
			' ' + data.converted + ' file generati, ' +
			data.skipped + ' immagini saltate, ' +
			data.failed + ' con errori. Spazio risparmiato: ' + data.saved + '.';
	}

	/**
	 * Chiude l'operazione mostrando il riepilogo.
	 *
	 * @param {Object}  panel   Pannello.
	 * @param {string}  message Messaggio.
	 * @param {boolean} ok      Esito positivo.
	 */
	function finish( panel, message, ok ) {
		running = false;
		cancelled = false;

		panel.title.text( ok ? ( i18n.done || 'Operazione completata.' ) : message );
		panel.cancel.prop( 'hidden', true );
		panel.summary
			.prop( 'hidden', false )
			.removeClass( 'is-error is-ok' )
			.addClass( ok ? 'is-ok' : 'is-error' )
			.text( message );
	}

	/**
	 * Avvia una nuova operazione a batch.
	 *
	 * @param {string} targetSelector Selettore del pannello.
	 * @param {Object} payload        Parametri per start_batch.
	 */
	function startBatch( targetSelector, payload ) {
		if ( running ) {
			return;
		}

		var panel = panelOf( targetSelector );

		running = true;
		cancelled = false;
		resetPanel( panel, 0 );

		request( 'start_batch', payload )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					finish( panel, ( response && response.data && response.data.message ) || i18n.genericError );
					return;
				}

				resetPanel( panel, response.data.total );
				runQueue( panel );
			} )
			.fail( function () {
				finish( panel, i18n.genericError || 'Errore di comunicazione con il server.' );
			} );
	}

	/**
	 * ID selezionati nella tabella libreria.
	 *
	 * @return {Array} Elenco di ID.
	 */
	function selectedIds() {
		return $( '.fs3d-io-check:checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();
	}

	/**
	 * Filtri correnti letti dalla query string.
	 *
	 * @return {Object} Filtri.
	 */
	function currentFilters() {
		var params = new URLSearchParams( window.location.search );

		return {
			mime: params.get( 'mime' ) || 'all',
			status: params.get( 'status' ) || 'all',
			size: params.get( 'size' ) || 'all',
			search: params.get( 's' ) || ''
		};
	}

	/**
	 * Mostra un messaggio inline nella tab delle regole.
	 *
	 * @param {string}  message Testo.
	 * @param {boolean} ok      Esito.
	 */
	function rulesMessage( message, ok ) {
		$( '#fs3d-io-rules-message' )
			.prop( 'hidden', false )
			.removeClass( 'is-ok is-error' )
			.addClass( ok ? 'is-ok' : 'is-error' )
			.text( message );
	}

	$( function () {
		// Slider di qualita': valore sempre visibile.
		$( '.fs3d-io-range' ).on( 'input change', function () {
			$( '#' + $( this ).data( 'output' ) ).text( this.value );
		} );

		// Selezione multipla.
		$( '#fs3d-io-check-all' ).on( 'change', function () {
			$( '.fs3d-io-check' ).prop( 'checked', this.checked );
		} );

		$( '#fs3d-io-optimize-selected' ).on( 'click', function () {
			var ids = selectedIds();

			if ( ! ids.length ) {
				window.alert( i18n.noSelection );
				return;
			}

			startBatch( '#fs3d-io-progress', {
				mode: 'optimize',
				ids: ids,
				force: $( '#fs3d-io-force' ).is( ':checked' ) ? 1 : 0
			} );
		} );

		$( '#fs3d-io-optimize-filtered' ).on( 'click', function () {
			startBatch( '#fs3d-io-progress', {
				mode: 'optimize',
				filters: currentFilters(),
				force: $( '#fs3d-io-force' ).is( ':checked' ) ? 1 : 0
			} );
		} );

		$( '#fs3d-io-reset' ).on( 'click', function () {
			if ( ! window.confirm( i18n.confirmReset ) ) {
				return;
			}

			startBatch( $( this ).data( 'target' ) || '#fs3d-io-progress-reset', { mode: 'reset' } );
		} );

		$( document ).on( 'click', '.fs3d-io-progress__cancel', function () {
			if ( ! window.confirm( i18n.confirmCancel ) ) {
				return;
			}

			cancelled = true;
			$( this ).prop( 'disabled', true );

			request( 'cancel_batch' );
		} );

		// Regole .htaccess.
		$( '#fs3d-io-rules-activate' ).on( 'click', function () {
			var $button = $( this ).prop( 'disabled', true );

			request( 'htaccess', { operation: 'activate' } )
				.done( function ( response ) {
					var data = ( response && response.data ) || {};

					rulesMessage( data.message || i18n.genericError, !! ( response && response.success ) );

					if ( response && response.success ) {
						$( '#fs3d-io-rules-deactivate' ).prop( 'disabled', false );
					}
				} )
				.fail( function () {
					rulesMessage( i18n.genericError, false );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		$( '#fs3d-io-rules-deactivate' ).on( 'click', function () {
			if ( ! window.confirm( i18n.confirmDeact ) ) {
				return;
			}

			var $button = $( this ).prop( 'disabled', true );

			request( 'htaccess', { operation: 'deactivate' } )
				.done( function ( response ) {
					var data = ( response && response.data ) || {};

					rulesMessage( data.message || i18n.genericError, !! ( response && response.success ) );
				} )
				.fail( function () {
					rulesMessage( i18n.genericError, false );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		$( '.fs3d-io-restore' ).on( 'click', function () {
			var backup = $( this ).data( 'backup' );

			request( 'htaccess', { operation: 'restore', backup: backup } )
				.done( function ( response ) {
					var data = ( response && response.data ) || {};

					rulesMessage( data.message || i18n.genericError, !! ( response && response.success ) );
				} )
				.fail( function () {
					rulesMessage( i18n.genericError, false );
				} );
		} );

		// Verifica reale della content negotiation.
		$( '#fs3d-io-verify' ).on( 'click', function () {
			var $button = $( this ).prop( 'disabled', true );
			var $box = $( '#fs3d-io-verify-result' ).prop( 'hidden', false ).html(
				$( '<p/>' ).text( i18n.verifying || 'Verifica in corso...' )
			);

			request( 'verify' )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$box.html( $( '<p class="is-error"/>' ).text( i18n.genericError ) );
						return;
					}

					var data = response.data;
					var $list = $( '<ul/>' ).addClass( 'fs3d-io-verify__steps' );

					$.each( data.steps || [], function ( _, step ) {
						$( '<li/>' )
							.addClass( step.ok ? 'is-ok' : 'is-ko' )
							.append( $( '<strong/>' ).text( step.label + ': ' ) )
							.append( document.createTextNode( step.detail || '' ) )
							.appendTo( $list );
					} );

					$box.empty()
						.append(
							$( '<p/>' )
								.addClass( data.success ? 'is-ok' : 'is-error' )
								.text( data.message )
						)
						.append( $list );
				} )
				.fail( function () {
					$box.html( $( '<p class="is-error"/>' ).text( i18n.genericError ) );
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );

		// Statistiche e log.
		$( '#fs3d-io-refresh-stats' ).on( 'click', function () {
			var $button = $( this ).prop( 'disabled', true );

			request( 'refresh_stats' ).always( function () {
				$button.prop( 'disabled', false );
				window.location.reload();
			} );
		} );

		$( '#fs3d-io-clear-log' ).on( 'click', function () {
			request( 'clear_log' ).always( function () {
				window.location.reload();
			} );
		} );

		// Se una coda e' rimasta a meta' (pagina chiusa, connessione caduta) proponiamo di riprenderla.
		if ( $( '#fs3d-io-progress' ).length ) {
			request( 'batch_status' ).done( function ( response ) {
				if ( ! response || ! response.success || ! response.data.pending ) {
					return;
				}

				if ( ! window.confirm( i18n.resumePending ) ) {
					request( 'cancel_batch' );
					return;
				}

				var panel = panelOf( '#fs3d-io-progress' );

				running = true;
				resetPanel( panel, response.data.total );
				panel.counter.text( response.data.offset + ' / ' + response.data.total );
				runQueue( panel );
			} );
		}
	} );
}( jQuery ) );
