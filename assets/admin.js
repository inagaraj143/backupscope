/**
 * BackupScope admin screen.
 * Renders from the server's status JSON and drives backups one slice at a time (the "AJAX runner").
 * Progress shown here is always measured by the server; nothing is estimated in the browser.
 */
( function () {
	'use strict';

	var cfg = window.BackupScope;
	var t = cfg.i18n;
	var app = document.getElementById( 'instabackup-app' );

	var state = {
		status: cfg.status,
		mode: 'full',
		areas: cfg.areas.map( function ( a ) { return a.id; } ),
		database: true,
		running: false,
		connectionLost: false,
		error: '',
		skipped: null,
		showExcluded: false,
		pick: null // Folder/database ticks on the scan screen: { job, off: {groupKey: true}, db: bool, open: {} }
	};

	// ------------------------------------------------------------------ helpers

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( str ).replace( /%(\d+)\$s|%s/g, function ( m, n ) {
			return esc( n ? args[ n - 1 ] : args.shift() );
		} );
	}

	function bytes( n ) {
		n = Number( n ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < units.length - 1 ) { n /= 1024; i++; }
		return ( i === 0 ? n : n.toFixed( n >= 100 ? 0 : n >= 10 ? 1 : 2 ) ) + ' ' + units[ i ];
	}

	function num( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	function duration( s ) {
		s = Math.max( 0, Math.floor( s ) );
		var h = Math.floor( s / 3600 ), m = Math.floor( ( s % 3600 ) / 60 ), sec = s % 60;
		return ( h ? h + ':' + String( m ).padStart( 2, '0' ) : m ) + ':' + String( sec ).padStart( 2, '0' );
	}

	function api( op, data, timeoutMs ) {
		var body = new FormData();
		body.append( 'action', 'instabackup' );
		body.append( 'op', op );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		var controller = window.AbortController ? new AbortController() : null;
		var timer = controller && timeoutMs ? setTimeout( function () { controller.abort(); }, timeoutMs ) : null;
		return fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', signal: controller ? controller.signal : undefined } )
			.then( function ( r ) {
				return r.text().then( function ( text ) {
					var json = null;
					try { json = JSON.parse( text ); } catch ( e ) { /* non-JSON: server error page */ }
					if ( json && json.success ) { return json.data; }
					var err = new Error( json && json.data && json.data.message ? json.data.message : 'HTTP ' + r.status );
					err.code = json && json.data ? json.data.code : 'http_' + r.status;
					err.transient = ! json || r.status >= 500;
					throw err;
				} );
			}, function ( e ) {
				var err = new Error( e && e.message ? e.message : 'network' );
				err.transient = true;
				throw err;
			} )
			.finally( function () { if ( timer ) { clearTimeout( timer ); } } );
	}

	function job() { return state.status.job; }

	/** Server time now, from the offset measured on the last response. */
	function serverNow() {
		var j = job();
		if ( j && j.server_time && j.server_time !== state.lastServerTime ) {
			state.lastServerTime = j.server_time;
			state.clockOffset = j.server_time - Date.now() / 1000;
		}
		return Date.now() / 1000 + ( state.clockOffset || 0 );
	}

	function isActive( j ) {
		return j && [ 'completed', 'completed_with_warnings', 'failed', 'cancelled', 'scanned' ].indexOf( j.status ) === -1;
	}

	// ------------------------------------------------------------------ runner

	function sleep( ms ) { return new Promise( function ( r ) { setTimeout( r, ms ); } ); }

	async function drive() {
		if ( state.running ) { return; }
		state.running = true;
		state.connectionLost = false;
		var failures = 0;
		var poller = null;
		var first = true; // Resume: the first request runs even when the server reports "paused".
		try {
			while ( isActive( job() ) && ( first || ! job().paused ) ) {
				first = false;
				// While a long request runs (single ZIP path), poll status for real progress.
				poller = setInterval( function () {
					api( 'status' ).then( function ( s ) {
						if ( s.job && job() && s.job.id === job().id && state.running && isActive( s.job ) ) {
							state.status.job = s.job;
							render();
						}
					} ).catch( function () {} );
				}, 2500 );
				try {
					state.status = await api( 'step', { job: job().id }, 15 * 60 * 1000 );
					failures = 0;
					if ( job() && job().busy ) { await sleep( 3000 ); }
				} catch ( e ) {
					if ( ! e.transient ) { throw e; }
					failures++;
					if ( failures > 6 ) {
						state.connectionLost = true;
						break;
					}
					await sleep( Math.min( 30000, 2000 * Math.pow( 2, failures - 1 ) ) );
					try { state.status = await api( 'status' ); } catch ( ignore ) { /* keep retrying */ }
				} finally {
					clearInterval( poller );
				}
				render();
			}
		} catch ( e ) {
			state.error = e.message;
		}
		state.running = false;
		render();
	}

	// ------------------------------------------------------------------ actions

	function startScan() {
		state.error = '';
		if ( state.mode === 'custom' && ! state.areas.length && ! state.database ) {
			state.error = t.selectOne;
			render();
			return;
		}
		var selection = { mode: state.mode, areas: state.areas, database: state.database };
		api( 'start', { selection: JSON.stringify( selection ) } ).then( function ( s ) {
			state.status = s;
			render();
			drive();
		} ).catch( showError );
	}

	function confirmBackup() {
		state.error = '';
		var p = picks( job() );
		api( 'confirm', { job: job().id, exclude: JSON.stringify( Object.keys( p.off ) ), database: p.db ? '1' : '0' } ).then( function ( s ) {
			state.status = s;
			render();
			drive();
		} ).catch( showError );
	}

	function cancelJob( ask ) {
		if ( ask && ! window.confirm( t.confirmCancel ) ) { return; }
		api( 'cancel', { job: job().id } ).then( function ( s ) {
			state.status = s;
			if ( s.job && s.job.status === 'cancelled' ) { return dismiss(); }
			render();
		} ).catch( showError );
	}

	function dismiss() {
		var id = job() ? job().id : '';
		state.skipped = null;
		state.showExcluded = false;
		return api( 'dismiss', { job: id } ).then( function ( s ) {
			state.status = s;
			render();
		} ).catch( showError );
	}

	function deleteBackup() {
		if ( ! window.confirm( t.confirmDelete ) ) { return; }
		api( 'delete', { backup: state.status.backup.id } ).then( function ( s ) {
			state.status = s;
			render();
		} ).catch( showError );
	}

	function loadSkipped() {
		api( 'skipped', { job: job().id } ).then( function ( d ) {
			state.skipped = d.items;
			render();
		} ).catch( showError );
	}

	function showError( e ) {
		state.error = e.message;
		render();
	}

	// ------------------------------------------------------------------ views

	function render() {
		var j = job();
		var html = '';
		if ( state.error ) {
			html += '<div class="notice notice-error inline"><p>' + esc( state.error ) + '</p></div>';
		}
		if ( state.status.storage && state.status.storage.exposed === true ) {
			html += '<div class="notice notice-warning inline"><p>' + esc( t.exposed ) + '</p></div>';
		}
		if ( state.connectionLost ) {
			html += '<div class="notice notice-warning inline"><p>' + esc( t.connectionLost ) + ' <button type="button" class="button" data-act="drive">' + esc( t.retry ) + '</button></p></div>';
		}

		if ( ! j ) {
			html += viewBackupCard() + viewStart();
		} else if ( j.status === 'scanning' ) {
			html += viewScanning( j );
		} else if ( j.status === 'scanned' ) {
			html += viewScanResults( j );
		} else if ( j.status === 'failed' ) {
			html += viewFailed( j );
		} else if ( j.status === 'completed' || j.status === 'completed_with_warnings' ) {
			html += viewComplete( j );
		} else if ( j.status === 'cancelled' ) {
			html += viewBackupCard() + viewStart();
		} else if ( j.paused && ! state.running ) {
			html += viewPaused( j );
		} else {
			html += viewProgress( j );
		}
		app.innerHTML = html;
		Array.prototype.forEach.call( app.querySelectorAll( '[data-indet]' ), function ( el ) { el.indeterminate = true; } );
	}

	function viewStart() {
		var areas = cfg.areas.map( function ( a ) {
			return '<label class="ib-check"><input type="checkbox" data-area="' + esc( a.id ) + '"' + ( state.areas.indexOf( a.id ) > -1 ? ' checked' : '' ) + '> ' +
				esc( a.label ) + ' <span class="ib-muted">' + esc( a.hint ) + '</span></label>';
		} ).join( '' );
		var heading = state.status.backup ? '<h2>' + esc( t.newBackup ) + '</h2>' : '';
		return '<div class="ib-card">' + heading +
			'<p class="ib-lead">' + esc( t.intro ) + '</p>' +
			'<fieldset class="ib-modes">' +
			'<label class="ib-radio"><input type="radio" name="ib-mode" value="full"' + ( state.mode === 'full' ? ' checked' : '' ) + '> <strong>' + esc( t.full ) + '</strong> <span class="ib-muted">' + esc( t.fullHint ) + '</span></label>' +
			'<label class="ib-radio"><input type="radio" name="ib-mode" value="custom"' + ( state.mode === 'custom' ? ' checked' : '' ) + '> <strong>' + esc( t.custom ) + '</strong> <span class="ib-muted">' + esc( t.customHint ) + '</span></label>' +
			'</fieldset>' +
			( state.mode === 'custom' ? '<div class="ib-areas">' +
				'<label class="ib-check"><input type="checkbox" data-db="1"' + ( state.database ? ' checked' : '' ) + '> ' + esc( t.database ) + ' <span class="ib-muted">' + esc( t.databaseHint ) + '</span></label>' +
				areas + '</div>' : '' ) +
			'<p><button type="button" class="button button-primary button-hero" data-act="scan">' + esc( t.scan ) + '</button></p>' +
			( state.status.backup ? '<p class="ib-muted">' + esc( t.newBackupNote ) + '</p>' : '' ) +
			'<p class="ib-muted">' + esc( t.noRestore ) + ' <a href="' + esc( cfg.restoreHelp ) + '" target="_blank" rel="noopener">' + esc( t.restoreLink ) + ' →</a></p>' +
			'</div>';
	}

	function viewBackupCard() {
		var b = state.status.backup;
		if ( ! b ) { return ''; }
		var contents = b.files && b.database ? t.filesAndDb : ( b.database ? t.dbOnly : t.filesOnly );
		var others = state.status.others && state.status.others.count ? '<p class="ib-muted">' + fmt( t.others, num( state.status.others.count ), bytes( state.status.others.bytes ) ) + '</p>' : '';
		return '<div class="ib-card ib-backup">' +
			'<h2>' + esc( t.currentBackup ) + '</h2>' +
			'<table class="ib-facts"><tbody>' +
			'<tr><th>' + esc( t.created ) + '</th><td>' + esc( b.created ) + '</td></tr>' +
			'<tr><th>' + esc( t.contents ) + '</th><td>' + esc( contents ) + '</td></tr>' +
			'<tr><th>' + esc( t.size ) + '</th><td>' + esc( bytes( b.size ) ) + '</td></tr>' +
			'<tr><th>' + esc( t.verified ) + '</th><td>' + ( b.verified ? '<span class="ib-ok">✓</span>' : '–' ) + '</td></tr>' +
			'</tbody></table>' +
			( ( b.tree && Object.keys( b.tree ).length ) || ( b.tables && b.tables.length ) ?
				'<details class="ib-contents"><summary>' + esc( t.viewContents ) + '</summary>' +
				viewTree( buildTree( b.tree, b.database ? { bytes: b.db_bytes, tables: b.tables.length, list: b.tables } : null ), false, null ) + '</details>' : '' ) +
			'<p><a class="button button-primary" href="' + esc( b.download_url ) + '">' + esc( t.download ) + '</a> ' +
			'<button type="button" class="button ib-danger" data-act="delete">' + esc( t.delete ) + '</button></p>' +
			( state.status.storage.label ? '<p class="ib-muted">' + fmt( t.storedIn, state.status.storage.label ) + '</p>' : '' ) +
			( cfg.keepOnUninstall ? '' : '<p class="ib-muted">' + esc( t.uninstallNote ) + '</p>' ) +
			others +
			'</div>';
	}

	function viewScanning( j ) {
		var p = j.progress || {};
		return '<div class="ib-card"><h2>' + esc( t.scanning ) + '</h2>' +
			'<p class="ib-big">' + fmt( t.found, num( p.files_found ), bytes( p.bytes_found ) ) + '</p>' +
			'<p><button type="button" class="button" data-act="cancel">' + esc( t.cancelBackup ) + '</button></p></div>';
	}

	function viewScanResults( j ) {
		var s = j.scan;
		var p = picks( j );
		var tree = treeFor( j );
		var sel = selectedTotals( j );
		var tiles = '';
		if ( s.areas.length ) {
			tiles += tile( t.files, bytes( s.files.bytes ), fmt( t.filesN, num( s.files.count ) ) );
		}
		if ( s.database ) {
			tiles += tile( t.database, '~' + bytes( s.database.bytes ), fmt( t.tablesCount, num( s.database.tables ) ) );
		}
		tiles += tile( t.diskFree, s.disk.free != null ? bytes( s.disk.free ) : '–', s.disk.ok === false ? t.notEnough : ( s.disk.ok ? t.ok : t.unknown ) );
		if ( s.excluded_count ) {
			tiles += tile( t.autoExcluded, num( s.excluded_count ), '<a href="#" data-act="toggle-excluded">' + esc( state.showExcluded ? t.hide : t.view ) + '</a>', true );
		}
		var notes = '';
		if ( s.disk.ok === false ) { notes += '<div class="notice notice-error inline"><p>' + esc( s.disk.message ) + '</p></div>'; }
		if ( state.showExcluded && s.excluded_count ) {
			notes += '<ul class="ib-list">' + s.excluded.map( function ( e ) {
				return '<li><code>' + esc( e.path ) + '</code> <span class="ib-muted">' + esc( t.reasons[ e.reason ] || e.reason ) + '</span></li>';
			} ).join( '' ) + '</ul>';
		}
		s.nested.forEach( function ( n ) { notes += '<p class="ib-muted">' + fmt( t.nested, '/' + n ) + '</p>'; } );
		if ( s.skipped ) { notes += '<p class="ib-warn">' + fmt( t.unreadable, num( s.skipped ) ) + '</p>'; }

		var nothing = sel.files === 0 && ! sel.db;
		var big = sel.total > cfg.threshold;
		return '<div class="ib-card ib-scan">' +
			'<h2>' + esc( t.scanComplete ) + '</h2>' +
			'<div class="ib-tiles">' + tiles + '</div>' + notes +
			'<h3 class="ib-h3">' + esc( t.chooseContents ) + '</h3>' +
			'<p class="ib-muted">' + esc( t.chooseHint ) + '</p>' +
			viewTree( tree, true, p ) +
			'<div class="ib-actionbar">' +
			'<div class="ib-selected"><span class="ib-muted">' + esc( t.selected ) + '</span> ' +
			'<strong>' + fmt( t.filesCount, num( sel.files ), bytes( sel.fileBytes ) ) + '</strong>' +
			( sel.db ? ' + ' + esc( t.database ) + ' <strong>~' + esc( bytes( sel.dbBytes ) ) + '</strong>' : '' ) +
			'<div class="ib-estimate">' + fmt( t.estimate, bytes( sel.total ) ) + ( big ? ' · <span class="ib-muted">' + esc( t.batchedNote ) + '</span>' : '' ) + '</div></div>' +
			'<div class="ib-actions"><button type="button" class="button" data-act="change">' + esc( t.changeSelection ) + '</button> ' +
			'<button type="button" class="button button-primary button-hero" data-act="confirm"' + ( s.disk.ok === false || nothing ? ' disabled' : '' ) + '>' + esc( t.createBackup ) + '</button></div>' +
			'</div></div>';
	}

	function tile( label, value, sub, raw ) {
		return '<div class="ib-tile"><div class="ib-tile-label">' + esc( label ) + '</div><div class="ib-tile-value">' + esc( value ) + '</div>' +
			'<div class="ib-tile-sub">' + ( raw ? sub : esc( sub ) ) + '</div></div>';
	}

	function stepDetail( j, step ) {
		var p = j.progress || {};
		if ( step.state === 'skipped' ) { return t.skippedStep; }
		if ( step.state !== 'active' ) { return ''; }
		if ( step.id === 'scan' ) { return fmt( t.found, num( p.files_found ), bytes( p.bytes_found ) ); }
		if ( step.id === 'database' && p.tables_total != null ) {
			return fmt( t.tables, num( p.tables_done ), num( p.tables_total ) ) + ( p.table ? ' · <code>' + esc( p.table ) + '</code>' : '' );
		}
		if ( step.id === 'archive' ) {
			if ( p.fallback ) { return esc( t.fallback ); }
			if ( p.engine === 'single' ) {
				return p.single_fraction != null ? fmt( t.zipFraction, Math.round( p.single_fraction * 100 ) + '%' ) : esc( t.zipWorking );
			}
			if ( p.files_total != null ) {
				return fmt( t.filesProgress, num( p.files_done ), num( p.files_total ), bytes( p.bytes_done ), bytes( p.bytes_total ) );
			}
		}
		if ( step.id === 'verify' && p.bytes_checked != null && p.bytes_total ) {
			return fmt( t.verifyProgress, bytes( p.bytes_checked ), bytes( p.bytes_total ) );
		}
		return '';
	}

	function ratio( done, total ) {
		var d = Number( done ), tt = Number( total );
		return isFinite( d ) && isFinite( tt ) && tt > 0 && done != null ? Math.min( 1, Math.max( 0, d / tt ) ) : null;
	}

	function percent( j, step ) {
		var p = j.progress || {};
		if ( step.state !== 'active' ) { return null; }
		// Progress belongs to the step that wrote it: right after a step finishes, the next step
		// is shown as active while the numbers are still the previous step's.
		if ( step.id === 'archive' && p.engine === 'batched' ) { return ratio( p.bytes_done, p.bytes_total ); }
		if ( step.id === 'archive' && p.engine === 'single' && p.single_fraction != null ) { return ratio( p.single_fraction, 1 ); }
		if ( step.id === 'verify' ) { return ratio( p.bytes_checked, p.bytes_total ); }
		if ( step.id === 'database' ) { return ratio( p.tables_done, p.tables_total ); }
		if ( step.id === 'remote_upload' && p.engine == null ) { return ratio( p.bytes_done, p.bytes_total ); }
		return null;
	}

	function viewProgress( j ) {
		// Between steps (e.g. right after "Create Backup") the next pending step is the one working.
		var steps = j.steps.map( function ( s ) { return Object.assign( {}, s ); } );
		if ( ! steps.some( function ( s ) { return s.state === 'active'; } ) ) {
			var next = steps.filter( function ( s ) { return s.state === 'pending'; } )[ 0 ];
			if ( next ) { next.state = 'active'; }
		}
		var active = null;
		var items = steps.map( function ( s ) {
			if ( s.state === 'active' ) { active = s; }
			var icon = s.state === 'done' ? '<span class="ib-tick">✓</span>' : s.state === 'active' ? '<span class="ib-spinner" aria-hidden="true"></span>' : s.state === 'skipped' ? '–' : '○';
			var pct = percent( j, s );
			var bar = s.state !== 'active' ? '' : pct == null ? '<span class="ib-bar ib-indeterminate"><span></span></span>' :
				'<span class="ib-bar"><span style="width:' + Math.min( 100, Math.max( 0, pct * 100 ) ).toFixed( 1 ) + '%"></span></span><span class="ib-pct">' + Math.floor( pct * 100 ) + '%</span>';
			return '<li class="ib-step ib-' + esc( s.state ) + '"><span class="ib-icon">' + icon + '</span> <span class="ib-label">' + esc( s.label ) + '</span> <span class="ib-detail">' + stepDetail( j, s ) + '</span>' + bar + '</li>';
		} ).join( '' );
		var p = j.progress || {};
		var now = '';
		if ( active && active.id === 'scan' && p.current ) { now = p.current || '/'; }
		if ( active && active.id === 'database' && p.table ) { now = p.table; }
		if ( active && active.id === 'archive' && p.current ) { now = p.current; }
		var elapsed = Date.now() / 1000 - j.started_at;
		return '<div class="ib-card ib-working"><h2>' + esc( t.creating ) + '</h2><ul class="ib-steps">' + items + '</ul>' +
			( now ? '<p class="ib-now"><span class="ib-pulse"></span>' + esc( t.nowProcessing ) + ' <code>' + esc( now ) + '</code></p>' : '' ) +
			'<p class="ib-muted ib-elapsed" data-start="' + esc( j.started_at ) + '">' + fmt( t.elapsed, duration( serverNow() - j.started_at ) ) + '</p>' +
			'<p>' + esc( t.keepOpen ) + '</p>' +
			'<p><button type="button" class="button" data-act="cancel"' + ( j.cancelling ? ' disabled' : '' ) + '>' + esc( j.cancelling ? t.cancelling : t.cancelBackup ) + '</button></p></div>';
	}

	// ------------------------------------------------------------------ contents tree

	/**
	 * Builds a browsable preview of the ZIP from the scan's folder groups (key = folder relative
	 * to the site root, '' = files in the root, '../x' = outside the site root) and DB tables.
	 * Every node knows the groups below it, so ticking a folder ticks exactly those groups.
	 */
	function buildTree( dirs, db ) {
		var index = {};
		function node( key, name ) {
			var n = { key: key, name: name, bytes: 0, count: 0, kids: {}, groups: [], leaf: false, meta: '', db: false };
			index[ key ] = n;
			return n;
		}
		var root = node( '', 'backup.zip' );
		root.isRoot = true;
		function add( parts, size, count, group, leafMeta ) {
			var cur = root;
			cur.bytes += size; cur.count += count;
			if ( group != null ) { cur.groups.push( group ); }
			parts.forEach( function ( part, i ) {
				var key = cur.key ? cur.key + '/' + part : part;
				if ( ! cur.kids[ part ] ) { cur.kids[ part ] = node( key, part ); }
				cur = cur.kids[ part ];
				cur.bytes += size; cur.count += count;
				if ( group != null ) { cur.groups.push( group ); }
				if ( i === parts.length - 1 && leafMeta != null ) { cur.leaf = true; cur.meta = leafMeta; }
			} );
			return cur;
		}
		add( [ 'manifest.json' ], 0, 0, null, '' ).fixed = true;
		if ( db ) {
			var dbDir = add( [ 'database' ], 0, 0, null, null );
			var sql = add( [ 'database', 'database.sql' ], db.bytes || 0, 0, null, db.tables ? fmt( t.tablesCount, num( db.tables ) ) : '' );
			dbDir.db = true;
			sql.db = true;
			( db.list || [] ).forEach( function ( tb ) {
				var n = node( 'database/database.sql/' + tb.name, tb.name );
				n.bytes = tb.bytes || 0; n.leaf = true; n.table = true; n.db = true;
				n.meta = tb.rows != null ? fmt( t.rowsCount, num( tb.rows ) ) : '';
				sql.kids[ tb.name ] = n;
			} );
		}
		Object.keys( dirs || {} ).forEach( function ( key ) {
			var d = dirs[ key ];
			if ( key === '' ) {
				add( [ 'files', 'wordpress', t.rootFiles ], d.bytes, d.count, '', fmt( t.filesN, num( d.count ) ) );
				return;
			}
			var parts = key.indexOf( '../' ) === 0 ? [ 'files', 'external' ].concat( key.slice( 3 ).split( '/' ) ) : [ 'files', 'wordpress' ].concat( key.split( '/' ) );
			add( parts, d.bytes, d.count, key, null );
		} );
		root.index = index;
		return root;
	}

	function treeFor( j ) {
		if ( ! state.treeCache || state.treeCache.job !== j.id ) {
			var s = j.scan;
			state.treeCache = { job: j.id, tree: buildTree( s.tree, s.database ? { bytes: s.database.bytes, tables: s.database.tables, list: s.database.list } : null ) };
		}
		return state.treeCache.tree;
	}

	function picks( j ) {
		if ( ! state.pick || state.pick.job !== j.id ) {
			state.pick = { job: j.id, off: {}, db: !! ( j.scan && j.scan.database ), open: { '': true, files: true, 'files/wordpress': true, 'files/wordpress/wp-content': true } };
		}
		return state.pick;
	}

	function selectedTotals( j ) {
		var p = picks( j );
		var dirs = j.scan.tree || {};
		var out = { files: 0, fileBytes: 0, db: p.db, dbBytes: p.db && j.scan.database ? j.scan.database.bytes : 0 };
		Object.keys( dirs ).forEach( function ( k ) {
			if ( ! p.off[ k ] ) { out.files += dirs[ k ].count; out.fileBytes += dirs[ k ].bytes; }
		} );
		out.total = out.fileBytes + out.dbBytes;
		return out;
	}

	/** 'on' | 'off' | 'mixed' for a node under the current picks. */
	function tickState( n, p ) {
		if ( n.db ) { return p.db ? 'on' : 'off'; }
		var hasDb = n.isRoot && !! n.kids.database;
		var off = n.groups.filter( function ( g ) { return p.off[ g ]; } ).length + ( hasDb && ! p.db ? 1 : 0 );
		var total = n.groups.length + ( hasDb ? 1 : 0 );
		return off === 0 ? 'on' : off === total ? 'off' : 'mixed';
	}

	function toggleNode( key ) {
		var j = job();
		var p = picks( j );
		var n = treeFor( j ).index[ key ];
		if ( ! n ) { return; }
		var turnOn = tickState( n, p ) !== 'on';
		if ( n.db ) {
			p.db = turnOn;
		} else {
			n.groups.forEach( function ( g ) { if ( turnOn ) { delete p.off[ g ]; } else { p.off[ g ] = true; } } );
			if ( n.isRoot && n.kids.database ) { p.db = turnOn; }
		}
		render();
	}

	/** @param p picks object when the tree is selectable, otherwise null. */
	function viewTree( root, selectable, p ) {
		function draw( n, depth ) {
			var kids = Object.keys( n.kids ).map( function ( k ) { return n.kids[ k ]; } );
			kids.sort( function ( a, b ) {
				var af = Object.keys( a.kids ).length > 0, bf = Object.keys( b.kids ).length > 0;
				if ( af !== bf ) { return af ? -1 : 1; }
				return b.bytes - a.bytes;
			} );
			var st = selectable && ! n.fixed ? tickState( n, p ) : 'on';
			var box = selectable && ! n.fixed && ! n.table ? '<input type="checkbox" class="ib-tcheck" data-node="' + esc( n.key ) + '"' + ( st === 'on' ? ' checked' : '' ) + ( st === 'mixed' ? ' data-indet="1"' : '' ) + ' aria-label="' + esc( n.name ) + '">' : '';
			var size = '<span class="ib-tsize">' + ( n.bytes ? esc( bytes( n.bytes ) ) : '' ) + '</span>';
			var meta = n.meta ? ' <span class="ib-muted">' + n.meta + '</span>' : ( ! n.leaf && n.count ? ' <span class="ib-muted">' + fmt( t.filesN, num( n.count ) ) + '</span>' : '' );
			var icon = n.table ? 'dashicons-editor-table' : n.name === 'database.sql' ? 'dashicons-database' : n.isRoot ? 'dashicons-archive' : kids.length ? 'dashicons-category' : 'dashicons-media-default';
			var label = box + '<span class="dashicons ' + icon + '"></span><span class="ib-tname">' + esc( n.name ) + ( kids.length && ! n.leaf && ! n.isRoot ? '/' : '' ) + '</span>' + meta + size;
			var cls = st === 'off' ? ' class="ib-off"' : '';
			if ( ! kids.length ) { return '<li' + cls + '><div class="ib-tleaf">' + label + '</div></li>'; }
			var open = p ? !! p.open[ n.key ] : depth < 2;
			return '<li' + cls + '><details data-key="' + esc( n.key ) + '"' + ( open ? ' open' : '' ) + '><summary>' + label + '</summary><ul>' +
				kids.map( function ( k ) { return draw( k, depth + 1 ); } ).join( '' ) + '</ul></details></li>';
		}
		return '<ul class="ib-tree' + ( selectable ? ' ib-selectable' : '' ) + '">' + draw( root, 0 ) + '</ul>';
	}

	function viewPaused( j ) {
		return '<div class="ib-card"><h2>' + esc( t.interrupted ) + '</h2>' +
			'<p><button type="button" class="button button-primary" data-act="resume">' + esc( t.resume ) + '</button> ' +
			'<button type="button" class="button" data-act="cancel-now">' + esc( t.cancelCleanup ) + '</button></p></div>';
	}

	function viewComplete( j ) {
		var warn = j.status === 'completed_with_warnings';
		var head = warn ? fmt( t.completeWarn, num( j.skipped ) ) + ' (<a href="#" data-act="skipped">' + esc( t.review ) + '</a>)' : esc( t.complete );
		var list = '';
		if ( state.skipped ) {
			list = '<ul class="ib-list">' + state.skipped.map( function ( i ) {
				return '<li><code>' + esc( i.path ) + '</code> <span class="ib-muted">' + esc( t.reasons[ i.reason ] || i.reason ) + '</span></li>';
			} ).join( '' ) + '</ul>';
		}
		return '<div class="ib-card ib-success"><h2>' + head + '</h2>' + list +
			'<p><button type="button" class="button" data-act="dismiss">' + esc( t.done ) + '</button></p></div>' + viewBackupCard();
	}

	function viewFailed( j ) {
		var err = j.error || {};
		return '<div class="ib-card ib-failed"><h2>' + esc( t.failed ) + '</h2>' +
			'<p>' + esc( err.message ) + '</p>' +
			( state.status.backup ? '<p>' + esc( t.previousSafe ) + '</p>' : '' ) +
			'<p><button type="button" class="button button-primary" data-act="dismiss">' + esc( t.tryAgain ) + '</button> ' +
			( j.has_log ? '<a class="button" href="' + esc( j.log_url ) + '">' + esc( t.downloadLog ) + '</a>' : '' ) + '</p></div>' +
			viewBackupCard();
	}

	// ------------------------------------------------------------------ events

	app.addEventListener( 'click', function ( e ) {
		var el = e.target.closest( '[data-act]' );
		if ( ! el ) { return; }
		var act = el.getAttribute( 'data-act' );
		if ( el.tagName === 'A' ) { e.preventDefault(); }
		state.error = act === 'toggle-excluded' || act === 'skipped' ? state.error : '';
		switch ( act ) {
			case 'scan': startScan(); break;
			case 'confirm': confirmBackup(); break;
			case 'change': cancelJob( false ); break;
			case 'cancel': cancelJob( true ); break;
			case 'cancel-now': cancelJob( false ); break;
			case 'resume': drive(); break;
			case 'drive': drive(); break;
			case 'dismiss': dismiss(); break;
			case 'delete': deleteBackup(); break;
			case 'skipped': loadSkipped(); break;
			case 'toggle-excluded': state.showExcluded = ! state.showExcluded; render(); break;
		}
	} );

	app.addEventListener( 'change', function ( e ) {
		var el = e.target;
		if ( el.name === 'ib-mode' ) {
			state.mode = el.value;
		} else if ( el.hasAttribute( 'data-area' ) ) {
			var id = el.getAttribute( 'data-area' );
			state.areas = state.areas.filter( function ( a ) { return a !== id; } );
			if ( el.checked ) { state.areas.push( id ); }
		} else if ( el.classList.contains( 'ib-tcheck' ) ) {
			toggleNode( el.getAttribute( 'data-node' ) );
			return;
		} else if ( el.hasAttribute( 'data-db' ) ) {
			state.database = el.checked;
		} else {
			return;
		}
		render();
	} );

	// Keep the elapsed timer moving while a backup runs.
	// Tick the elapsed time without re-rendering (keeps open folders and focus intact).
	setInterval( function () {
		var el = app.querySelector( '.ib-elapsed' );
		if ( el ) { el.textContent = t.elapsed.replace( '%s', duration( serverNow() - Number( el.getAttribute( 'data-start' ) ) ) ); }
	}, 1000 );

	// Remember which tree folders are open across re-renders ('toggle' does not bubble).
	app.addEventListener( 'toggle', function ( e ) {
		var d = e.target;
		if ( d.tagName === 'DETAILS' && d.hasAttribute( 'data-key' ) && state.pick && job() && state.pick.job === job().id ) {
			state.pick.open[ d.getAttribute( 'data-key' ) ] = d.open;
		}
	}, true );

	if ( cfg.status.error ) { state.error = cfg.status.error; }
	render();
	// A job that is running in another tab or was interrupted shows as paused; otherwise resume driving.
	if ( isActive( job() ) && ! job().paused ) { drive(); }
}() );
