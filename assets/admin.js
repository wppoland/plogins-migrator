/* Migrator admin, resumable export driver. Vanilla JS, no dependencies. */
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
		var ti = 0;
		document.querySelectorAll( '.migrator-export-table:checked' ).forEach( function ( cb ) {
			opts[ 'options[exclude_tables][' + ti++ + ']' ] = cb.value;
		} );
		var pi = 0;
		document.querySelectorAll( '.migrator-export-path:checked' ).forEach( function ( cb ) {
			opts[ 'options[exclude_paths][' + pi++ + ']' ] = cb.value;
		} );
		var comp = document.querySelector( 'input[name="migrator-compress"]:checked' );
		if ( comp && comp.value === 'gzip' ) {
			opts.compress = '1';
		}
		// Optional encryption controls, injected by the Pro add-on.
		var enc = document.getElementById( 'migrator-encrypt' );
		if ( enc && enc.checked ) {
			var pw = document.getElementById( 'migrator-encrypt-password' );
			opts.encrypt = '1';
			opts.password = pw ? pw.value : '';
		}

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

	// --- File-size explorer: scan wp-content, see sizes, tick to exclude ------
	var scanBtn = document.getElementById( 'migrator-scan' );
	var treeEl = document.getElementById( 'migrator-tree' );
	var scanSummary = document.getElementById( 'migrator-scan-summary' );

	function fmtSize( bytes ) {
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ], i = 0, n = bytes || 0;
		while ( n >= 1024 && i < u.length - 1 ) { n /= 1024; i++; }
		return ( i === 0 ? n : n.toFixed( n < 10 ? 1 : 0 ) ) + ' ' + u[ i ];
	}

	function buildNode( node, depth ) {
		var hasKids = node.children && node.children.length;
		var wrap = document.createElement( 'div' );
		var row = document.createElement( 'div' );
		row.className = 'migrator-tree__row' + ( node.dir ? ' is-dir' : '' );
		row.style.paddingLeft = ( 8 + depth * 18 ) + 'px';

		var caret = document.createElement( 'button' );
		caret.type = 'button';
		caret.className = 'migrator-tree__caret';
		caret.textContent = hasKids ? '▸' : '';
		caret.disabled = ! hasKids;
		row.appendChild( caret );

		var label = document.createElement( 'label' );
		label.className = 'migrator-tree__label';
		if ( node.rel ) {
			var cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.className = 'migrator-export-path';
			cb.value = node.rel;
			label.appendChild( cb );
		}
		var name = document.createElement( 'span' );
		name.className = 'migrator-tree__name';
		name.textContent = node.name + ( node.dir ? '/' : '' );
		label.appendChild( name );
		row.appendChild( label );

		var meta = document.createElement( 'span' );
		meta.className = 'migrator-tree__meta';
		meta.textContent = ( node.dir && node.nodes ? node.nodes + ' · ' : '' ) + fmtSize( node.size );
		row.appendChild( meta );
		wrap.appendChild( row );

		if ( hasKids ) {
			var kids = document.createElement( 'div' );
			kids.className = 'migrator-tree__kids';
			kids.hidden = depth >= 1;
			caret.textContent = kids.hidden ? '▸' : '▾';
			caret.setAttribute( 'aria-expanded', String( ! kids.hidden ) );
			node.children.forEach( function ( c ) { kids.appendChild( buildNode( c, depth + 1 ) ); } );
			caret.addEventListener( 'click', function () {
				kids.hidden = ! kids.hidden;
				caret.textContent = kids.hidden ? '▸' : '▾';
				caret.setAttribute( 'aria-expanded', String( ! kids.hidden ) );
			} );
			wrap.appendChild( kids );
		}
		return wrap;
	}

	if ( scanBtn && treeEl ) {
		scanBtn.addEventListener( 'click', function () {
			scanBtn.disabled = true;
			scanSummary.textContent = i18n.scanning || 'Scanning…';
			var scanBody = new URLSearchParams();
			scanBody.set( 'action', 'migrator_scan_tree' );
			scanBody.set( 'nonce', data.nonce );
			fetch( data.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: scanBody.toString()
			} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
				scanBtn.disabled = false;
				if ( ! res || ! res.success || ! res.data ) {
					scanSummary.textContent = i18n.scanFailed || 'Scan failed.';
					return;
				}
				var root = res.data;
				treeEl.innerHTML = '';
				( root.children || [] ).forEach( function ( c ) {
					treeEl.appendChild( buildNode( c, 0 ) );
				} );
				treeEl.hidden = false;
				scanSummary.textContent = ( root.nodes || 0 ) + ' ' + ( i18n.filesWord || 'files' ) + ' · ' + fmtSize( root.size );
			} ).catch( function () {
				scanBtn.disabled = false;
				scanSummary.textContent = i18n.scanFailed || 'Scan failed.';
			} );
		} );
	}
}() );
