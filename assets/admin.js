/* Migrator admin — resumable export driver. Vanilla JS, no dependencies. */
( function () {
	'use strict';

	var data = window.migratorData || {};
	var i18n = data.i18n || {};

	var startBtn = document.getElementById( 'migrator-export-start' );
	if ( ! startBtn ) {
		return;
	}

	var progress = document.getElementById( 'migrator-export-progress' );
	var bar = progress.querySelector( '.migrator-progress__bar' );
	var fill = document.getElementById( 'migrator-export-fill' );
	var status = document.getElementById( 'migrator-export-status' );
	var result = document.getElementById( 'migrator-export-result' );
	var resultMsg = document.getElementById( 'migrator-export-result-msg' );
	var download = document.getElementById( 'migrator-export-download' );

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', data.nonce );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) {
				body.set( k, extra[ k ] );
			} );
		}
		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setProgress( percent, message ) {
		fill.style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', String( percent ) );
		status.textContent = message + ' ' + percent + '%';
	}

	function fail( message ) {
		progress.hidden = true;
		result.hidden = false;
		resultMsg.textContent = message || i18n.failed || 'Export failed.';
		resultMsg.classList.add( 'is-error' );
		download.hidden = true;
		startBtn.disabled = false;
	}

	function finish( job ) {
		setProgress( 100, i18n.done || 'Backup ready.' );
		result.hidden = false;
		resultMsg.classList.remove( 'is-error' );
		resultMsg.textContent = ( i18n.done || 'Backup ready.' ) + ' ' + ( job.fileName || '' ) + ' (' + ( job.size || '' ) + ')';
		download.hidden = false;
		download.setAttribute( 'href', job.download );
		download.setAttribute( 'download', job.fileName || 'backup.migrator' );
		startBtn.disabled = false;
	}

	function loop() {
		post( 'migrator_export_step' ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				fail( res && res.data ? res.data.message : '' );
				return;
			}
			var job = res.data;
			setProgress( job.percent, i18n.archiving || 'Archiving files…' );
			if ( job.done ) {
				finish( job );
			} else {
				loop();
			}
		} ).catch( function () {
			fail();
		} );
	}

	startBtn.addEventListener( 'click', function () {
		startBtn.disabled = true;
		result.hidden = true;
		resultMsg.classList.remove( 'is-error' );
		progress.hidden = false;
		setProgress( 0, i18n.preparing || 'Preparing…' );

		var opts = {};
		document.querySelectorAll( '.migrator-export-opt:checked' ).forEach( function ( cb ) {
			opts[ 'options[' + cb.value + ']' ] = '1';
		} );

		post( 'migrator_export_start', opts ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				fail( res && res.data ? res.data.message : '' );
				return;
			}
			loop();
		} ).catch( function () {
			fail();
		} );
	} );
}() );

/* Restore: drag-drop + chunked upload + run. */
( function () {
	'use strict';

	var data = window.migratorData || {};
	var i18n = data.i18n || {};
	var CHUNK = 4 * 1024 * 1024;

	var drop = document.getElementById( 'migrator-drop' );
	var input = document.getElementById( 'migrator-file' );
	var startBtn = document.getElementById( 'migrator-import-start' );
	if ( ! drop || ! input || ! startBtn ) {
		return;
	}

	var nameEl = document.getElementById( 'migrator-file-name' );
	var filesCb = document.getElementById( 'migrator-import-files' );
	var progress = document.getElementById( 'migrator-import-progress' );
	var bar = progress.querySelector( '.migrator-progress__bar' );
	var fill = document.getElementById( 'migrator-import-fill' );
	var status = document.getElementById( 'migrator-import-status' );
	var result = document.getElementById( 'migrator-import-result' );
	var resultMsg = document.getElementById( 'migrator-import-result-msg' );
	var chosen = null;

	function setProgress( percent, message ) {
		fill.style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', String( Math.round( percent ) ) );
		status.textContent = message;
	}

	function uploadId() {
		var a = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var s = 'u';
		for ( var i = 0; i < 20; i++ ) {
			s += a.charAt( Math.floor( Math.random() * a.length ) );
		}
		return s;
	}

	function pick( file ) {
		if ( ! file ) {
			return;
		}
		chosen = file;
		nameEl.textContent = file.name;
		startBtn.disabled = false;
	}

	input.addEventListener( 'change', function () {
		pick( input.files && input.files[ 0 ] );
	} );

	[ 'dragover', 'dragenter' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) {
			ev.preventDefault();
			drop.classList.add( 'is-over' );
		} );
	} );
	[ 'dragleave', 'drop' ].forEach( function ( e ) {
		drop.addEventListener( e, function ( ev ) {
			ev.preventDefault();
			drop.classList.remove( 'is-over' );
		} );
	} );
	drop.addEventListener( 'drop', function ( ev ) {
		pick( ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[ 0 ] );
	} );

	function fail( message ) {
		result.hidden = false;
		resultMsg.textContent = message || i18n.restoreFailed || 'Restore failed.';
		resultMsg.classList.add( 'is-error' );
		startBtn.disabled = false;
	}

	function uploadChunk( id, index ) {
		var start = index * CHUNK;
		if ( start >= chosen.size ) {
			return runImport( id );
		}
		var body = new FormData();
		body.append( 'action', 'migrator_import_upload' );
		body.append( 'nonce', data.nonce );
		body.append( 'upload_id', id );
		body.append( 'index', String( index ) );
		body.append( 'chunk', chosen.slice( start, start + CHUNK ) );

		fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					fail( res && res.data ? res.data.message : '' );
					return;
				}
				var pct = Math.min( 90, ( start + CHUNK ) / chosen.size * 90 );
				setProgress( pct, ( i18n.uploading || 'Uploading…' ) + ' ' + Math.round( pct ) + '%' );
				uploadChunk( id, index + 1 );
			} )
			.catch( function () { fail(); } );
	}

	function runImport( id ) {
		setProgress( 95, i18n.restoring || 'Restoring…' );
		var body = new URLSearchParams();
		body.set( 'action', 'migrator_import_run' );
		body.set( 'nonce', data.nonce );
		body.set( 'upload_id', id );
		body.set( 'import_files', filesCb && filesCb.checked ? '1' : '' );

		fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					progress.hidden = true;
					fail( res && res.data ? res.data.message : '' );
					return;
				}
				setProgress( 100, i18n.restoreDone || 'Restore complete.' );
				result.hidden = false;
				resultMsg.classList.remove( 'is-error' );
				var d = res.data || {};
				resultMsg.textContent = ( i18n.restoreDone || 'Restore complete.' ) +
					' ' + ( d.statements || 0 ) + ' statements, ' + ( d.replaced || 0 ) + ' rows rewritten, ' + ( d.files || 0 ) + ' files.';
				startBtn.disabled = false;
			} )
			.catch( function () {
				progress.hidden = true;
				fail();
			} );
	}

	startBtn.addEventListener( 'click', function () {
		if ( ! chosen ) {
			return;
		}
		if ( ! window.confirm( i18n.confirmRestore || 'This overwrites the current site. Continue?' ) ) {
			return;
		}
		startBtn.disabled = true;
		result.hidden = true;
		resultMsg.classList.remove( 'is-error' );
		progress.hidden = false;
		setProgress( 0, i18n.uploading || 'Uploading…' );
		uploadChunk( uploadId(), 0 );
	} );
}() );
