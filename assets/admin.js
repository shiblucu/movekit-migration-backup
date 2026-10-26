/* global MoveKit, wp */
( function () {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;

	// Inline config is JSON, but make sure the chunk size is a number.
	var CHUNK = parseInt( MoveKit.chunk, 10 ) || 1048576;
	var STORE = 'movekit_job_' + MoveKit.home;
	var busy = false;

	function $( id ) {
		return document.getElementById( id );
	}

	function sleep( ms ) {
		return new Promise( function ( r ) {
			setTimeout( r, ms );
		} );
	}

	/**
	 * Normalize a failed request into an Error. `fatal` is true when the
	 * plugin itself answered with a reason, so retrying would not help.
	 */
	function toError( e ) {
		// Our own errors and REST permission errors won't change on retry.
		var ours = !! ( e && e.code && /^(movekit|rest)_/.test( e.code ) );
		var err = new Error( ( e && e.message ) || __( 'Lost connection to the site.', 'movekit-migration-backup' ) );
		err.code = e && e.code;
		err.fatal = ours;
		return err;
	}

	/**
	 * Call one of the plugin's REST routes as the logged-in user.
	 */
	function api( method, path, body ) {
		var opts = { path: 'movekit/v1/' + path, method: method };
		if ( body instanceof FormData ) {
			opts.body = body;
		} else if ( body ) {
			opts.data = body;
		}
		return apiFetch( opts ).catch( function ( e ) {
			throw toError( e );
		} );
	}

	/**
	 * Run a job step. Sent without the REST nonce on purpose, so WordPress
	 * treats it as logged out: the step is authorized by the job token, which
	 * still works after a restore has replaced the users table.
	 */
	function stepRequest( job ) {
		var body = new FormData();
		body.append( 'token', job.token );
		return fetch( MoveKit.restUrl + 'jobs/' + job.job + '/step', {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
		} ).then(
			function ( res ) {
				return res.json().then(
					function ( data ) {
						if ( res.ok ) {
							return data;
						}
						throw toError( data );
					},
					function () {
						throw toError( {
							message: sprintf( /* translators: %d: HTTP status code. */ __( 'The server returned an error (%d).', 'movekit-migration-backup' ), res.status ),
						} );
					}
				);
			},
			function () {
				throw toError( null );
			}
		);
	}

	/**
	 * Retry temporary failures with growing pauses.
	 */
	function retry( fn, onRetry ) {
		var attempt = 0;
		function go() {
			return fn().catch( function ( err ) {
				if ( err.fatal || attempt >= 8 ) {
					throw err;
				}
				attempt++;
				var wait = Math.min( 15000, 1000 * Math.pow( 2, attempt - 1 ) );
				onRetry( err, wait );
				return sleep( wait ).then( go );
			} );
		}
		return go();
	}

	function retryNote( err, wait ) {
		return sprintf( /* translators: 1: error message, 2: seconds. */ __( '%1$s Retrying in %2$d seconds…', 'movekit-migration-backup' ), err.message, Math.round( wait / 1000 ) );
	}

	/* ------------------------------------------------------------ display */

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s === null || s === undefined ? '' : String( s );
		// innerHTML leaves quotes alone; escape them too so the result is safe in attributes.
		return d.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/* -------------------------------------------------------------- parts */

	var ALL_PARTS = Object.keys( MoveKit.parts );
	var PARTS_KEY = 'movekit_parts';

	function selectedParts() {
		return Array.prototype.filter
			.call( document.querySelectorAll( '#movekit-parts input' ), function ( el ) {
				return el.checked;
			} )
			.map( function ( el ) {
				return el.value;
			} );
	}

	function isAll( parts ) {
		return parts.length === ALL_PARTS.length;
	}

	function describeParts( parts ) {
		return parts
			.map( function ( p ) {
				return MoveKit.parts[ p ] || p;
			} )
			.join( ', ' );
	}

	function hasDatabase( parts ) {
		return ! parts || parts.indexOf( 'database' ) !== -1;
	}

	/**
	 * The parts to use, or null (after saying why) when nothing is ticked.
	 */
	function partsOrWarn() {
		var parts = selectedParts();
		if ( ! parts.length ) {
			showResult( 'error', '<p>' + esc( __( 'Select at least one item under What to include.', 'movekit-migration-backup' ) ) + '</p>' );
			return null;
		}
		return parts;
	}

	/**
	 * What gets replaced, for confirmation messages.
	 */
	function replacesText( parts, target ) {
		if ( isAll( parts ) ) {
			return sprintf( /* translators: %s: site address. */ __( 'This replaces the database and wp-content files of %s.', 'movekit-migration-backup' ), target );
		}
		return sprintf( /* translators: 1: site address, 2: list such as Database, Media. */ __( 'This replaces only these parts of %1$s: %2$s.', 'movekit-migration-backup' ), target, describeParts( parts ) );
	}

	/**
	 * Tell screen reader users what is happening, without reading out
	 * every progress message.
	 */
	function announce( text ) {
		var region = $( 'movekit-status' );
		region.textContent = '';
		setTimeout( function () {
			region.textContent = text;
		}, 100 );
	}

	var announced = 0;

	function showProgress( title ) {
		$( 'movekit-result' ).hidden = true;
		$( 'movekit-progress' ).hidden = false;
		$( 'movekit-progress-title' ).textContent = title;
		announced = 0;
		setProgress( 0, __( 'Starting…', 'movekit-migration-backup' ) );
		announce( title );
		reveal( $( 'movekit-progress' ) );
	}

	function setProgress( pct, msg ) {
		pct = Math.max( 0, Math.min( 100, Math.floor( pct ) ) );
		$( 'movekit-bar' ).style.width = pct + '%';
		$( 'movekit-bar-track' ).setAttribute( 'aria-valuenow', pct );
		$( 'movekit-progress-pct' ).textContent = pct + '%';
		if ( msg !== undefined ) {
			$( 'movekit-progress-msg' ).textContent = msg;
		}
		// Every quarter of the way.
		if ( pct >= announced + 25 && pct < 100 ) {
			announced = pct - ( pct % 25 );
			announce( sprintf( /* translators: %d: percentage. */ __( '%d%% done.', 'movekit-migration-backup' ), pct ) );
		}
	}

	function showResult( kind, html ) {
		$( 'movekit-progress' ).hidden = true;
		var box = $( 'movekit-result' );
		box.className = 'movekit-result notice notice-' + kind;
		box.innerHTML = html;
		box.hidden = false;
		if ( kind === 'error' ) {
			box.setAttribute( 'role', 'alert' );
		} else {
			box.removeAttribute( 'role' );
		}
		reveal( box );
		// Move keyboard and screen reader focus to the outcome.
		box.focus( { preventScroll: true } );
	}

	// Push and pull start at the bottom of the screen; bring the outcome into view.
	function reveal( el ) {
		var r = el.getBoundingClientRect();
		if ( r.top < 0 || r.bottom > window.innerHeight ) {
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function warningsHtml( list ) {
		if ( ! list || ! list.length ) {
			return '';
		}
		return (
			'<details><summary>' +
			esc( sprintf( /* translators: %d: number of warnings. */ __( 'Warnings (%d)', 'movekit-migration-backup' ), list.length ) ) +
			'</summary><ul>' +
			list.map( function ( w ) {
				return '<li>' + esc( w ) + '</li>';
			} ).join( '' ) +
			'</ul></details>'
		);
	}

	function setBusy( on ) {
		busy = on;
		document.querySelectorAll( '.movekit .button, .movekit button' ).forEach( function ( el ) {
			el.classList.toggle( 'disabled', on );
			if ( el.tagName === 'BUTTON' ) {
				el.disabled = on;
			}
		} );
	}

	function fail( err ) {
		stopCountdown();
		setBusy( false );
		showResult( 'error', '<p><strong>' + esc( __( 'Something went wrong.', 'movekit-migration-backup' ) ) + '</strong> ' + esc( err.message ) + '</p>' );
	}

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( busy ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* --------------------------------------------------------------- jobs */

	function remember( job ) {
		try {
			if ( job ) {
				localStorage.setItem( STORE, JSON.stringify( job ) );
			} else {
				localStorage.removeItem( STORE );
			}
		} catch ( e ) {}
	}

	/* ---------------------------------------------------------- countdown */

	var countdown = null;

	function clock( secs ) {
		secs = Math.max( 0, Math.round( secs ) );
		return Math.floor( secs / 60 ) + ':' + ( '0' + ( secs % 60 ) ).slice( -2 );
	}

	/**
	 * While the other site can't be reached, count down the time left
	 * before the transfer pauses.
	 */
	function showCountdown( message, secs ) {
		stopCountdown();
		var ends = Date.now() + secs * 1000;
		function tick() {
			$( 'movekit-progress-msg' ).textContent =
				message + ' ' + sprintf( /* translators: %s: time left, such as 4:32. */ __( '%s left before the transfer pauses.', 'movekit-migration-backup' ), clock( ( ends - Date.now() ) / 1000 ) );
		}
		tick();
		countdown = setInterval( tick, 1000 );
		$( 'movekit-progress' ).classList.add( 'movekit-waiting' );
	}

	function stopCountdown() {
		if ( countdown ) {
			clearInterval( countdown );
			countdown = null;
		}
		$( 'movekit-progress' ).classList.remove( 'movekit-waiting' );
	}

	function runJob( job ) {
		var lastPct = 0;
		remember( job );
		function step() {
			return retry(
				function () {
					return stepRequest( job );
				},
				function ( err, wait ) {
					setProgress( lastPct, retryNote( err, wait ) );
				}
			).then( function ( s ) {
				lastPct = s.progress;
				setProgress( s.progress, s.message );
				if ( s.status === 'running' && typeof s.wait_left === 'number' ) {
					showCountdown( s.message, s.wait_left );
				} else {
					stopCountdown();
				}
				if ( s.status === 'running' ) {
					return s.busy ? sleep( 1500 ).then( step ) : step();
				}
				remember( null );
				return s;
			} );
		}
		return step();
	}

	function untilText( info ) {
		return info.untilText || new Date( info.until * 1000 ).toLocaleString();
	}

	/**
	 * After a restore that logged the user out: where to find Undo.
	 */
	function undoLater( info ) {
		if ( ! info ) {
			return '';
		}
		return (
			'<p>' +
			esc( sprintf( /* translators: %s: date and time. */ __( 'Not what you expected? After logging in, you can undo the restore on the MoveKit screen until %s.', 'movekit-migration-backup' ), untilText( info ) ) ) +
			'</p>'
		);
	}

	function exportDone( s ) {
		setBusy( false );
		if ( s.status === 'error' ) {
			showResult( 'error', '<p><strong>' + esc( __( 'Backup failed.', 'movekit-migration-backup' ) ) + '</strong> ' + esc( s.message ) + '</p>' + warningsHtml( s.log ) );
			return;
		}
		var r = s.result;
		showResult(
			'success',
			'<p><strong>' + esc( __( 'Backup complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
				esc( sprintf( /* translators: 1: number of tables, 2: number of files. */ __( '%1$d tables and %2$d files.', 'movekit-migration-backup' ), r.tables, r.files ) ) + '</p>' +
				'<p><a class="button button-primary" href="' + esc( MoveKit.download + encodeURIComponent( r.name ) ) + '">' +
				esc( sprintf( /* translators: %s: file name. */ __( 'Download %s', 'movekit-migration-backup' ), r.name ) ) + '</a></p>' +
				warningsHtml( s.log )
		);
		return loadList();
	}

	function importDone( s ) {
		setBusy( false );
		if ( s.status === 'error' ) {
			showResult(
				'error',
				'<p><strong>' + esc( __( 'Restore failed.', 'movekit-migration-backup' ) ) + '</strong> ' + esc( s.message ) + '</p>' + warningsHtml( s.log )
			);
			return;
		}
		if ( s.result && s.result.database === false ) {
			showResult( 'success', '<p><strong>' + esc( __( 'Restore complete.', 'movekit-migration-backup' ) ) + '</strong></p>' + warningsHtml( s.log ) );
			return Promise.all( [ loadList(), loadUndo() ] );
		}
		showResult(
			'success',
			'<p><strong>' + esc( __( 'Restore complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
				esc( __( 'Log in with the username and password from the site you restored.', 'movekit-migration-backup' ) ) + '</p>' +
				'<p><a class="button button-primary" href="' + esc( MoveKit.login ) + '">' + esc( __( 'Log in', 'movekit-migration-backup' ) ) + '</a></p>' +
				undoLater( s.result && s.result.undo ) +
				warningsHtml( s.log )
		);
	}

	function startExport() {
		var parts = partsOrWarn();
		if ( busy || ! parts ) {
			return;
		}
		setBusy( true );
		showProgress( isAll( parts ) ? __( 'Creating backup', 'movekit-migration-backup' ) : sprintf( /* translators: %s: list such as Database, Media. */ __( 'Creating backup of %s', 'movekit-migration-backup' ), describeParts( parts ) ) );
		api( 'POST', 'backups', { parts: parts, exclude: $( 'movekit-exclude' ).value } )
			.then( function ( job ) {
				job.kind = 'export';
				return runJob( job );
			} )
			.then( exportDone )
			.catch( fail );
	}

	/**
	 * Run the checks for a restore, push or pull. Resolves with the checks,
	 * or with null after showing why it can't go ahead.
	 */
	function preflight( path ) {
		setBusy( true );
		return api( 'GET', path ).then(
			function ( c ) {
				setBusy( false );
				if ( c.errors && c.errors.length ) {
					showResult(
						'error',
						'<p><strong>' + esc( __( 'This can\'t go ahead:', 'movekit-migration-backup' ) ) + '</strong></p><ul>' +
							c.errors.map( function ( e ) {
								return '<li>' + esc( e ) + '</li>';
							} ).join( '' ) +
							'</ul>'
					);
					return null;
				}
				return c;
			},
			function ( err ) {
				setBusy( false );
				throw err;
			}
		);
	}

	function warningLines( list ) {
		if ( ! list || ! list.length ) {
			return '';
		}
		return '\n\n' + __( 'Please note:', 'movekit-migration-backup' ) + '\n' + list.map( function ( w ) {
			return '• ' + w;
		} ).join( '\n' );
	}

	function startImport( b ) {
		if ( busy ) {
			return;
		}
		preflight( 'backups/' + encodeURIComponent( b.name ) + '/preflight' )
			.then( function ( c ) {
				if ( c ) {
					confirmImport( b, c );
				}
			} )
			.catch( fail );
	}

	function confirmImport( b, c ) {
		var msg =
			sprintf( /* translators: 1: file name, 2: source site address, 3: this site's address. */ __( 'Restore %1$s from %2$s over %3$s?', 'movekit-migration-backup' ), b.name, b.from, MoveKit.home ) +
			'\n\n' +
			replacesText( b.parts, MoveKit.home ) +
			( hasDatabase( b.parts ) ? ' ' + __( 'Afterwards you log in with the username and password from the restored site.', 'movekit-migration-backup' ) : '' ) +
			( MoveKit.local ? '' : '\n\n' + __( 'This is a live site. Visitors see the restored site as soon as it finishes.', 'movekit-migration-backup' ) ) +
			warningLines( c.warnings ) +
			'\n\n' +
			( c.undo
				? __( 'You can undo this restore for a day. The earlier restore can then no longer be undone.', 'movekit-migration-backup' )
				: __( 'You can undo this restore for a day.', 'movekit-migration-backup' ) );
		if ( ! window.confirm( msg ) ) {
			return;
		}
		setBusy( true );
		showProgress( __( 'Restoring backup', 'movekit-migration-backup' ) );
		api( 'POST', 'backups/' + encodeURIComponent( b.name ) + '/restore' )
			.then( function ( job ) {
				job.kind = 'import';
				return runJob( job );
			} )
			.then( importDone )
			.catch( fail );
	}

	function transferDone( s ) {
		setBusy( false );
		var push = s.result ? s.result.direction === 'push' : false;
		if ( s.status === 'error' && s.resumable ) {
			showResult( 'warning', pausedHtml( s.id, s.direction, s.remote, s.message ) + warningsHtml( s.log ) );
			return;
		}
		if ( s.status === 'error' ) {
			showResult(
				'error',
				'<p><strong>' + esc( __( 'The transfer failed.', 'movekit-migration-backup' ) ) + '</strong> ' + esc( s.message ) + '</p>' + warningsHtml( s.log )
			);
			return;
		}
		var remote = s.result.remote;
		var viaRemote = s.result.media
			? '<p>' + esc( sprintf( /* translators: %s: site address. */ __( 'Uploads were not copied. Missing images are shown from %s.', 'movekit-migration-backup' ), remote ) ) + '</p>'
			: '';
		var same = s.result.unchanged
			? '<p>' +
			  esc(
					sprintf(
						/* translators: %s: number of files. */
						_n( 'Left out %s file that was already the same on both sites.', 'Left out %s files that were already the same on both sites.', s.result.unchanged, 'movekit-migration-backup' ),
						s.result.unchanged.toLocaleString()
					)
			  ) +
			  '</p>'
			: '';
		var withDb = hasDatabase( s.result.parts );
		var undoThere =
			'<p>' +
			esc( sprintf( /* translators: %s: site address. */ __( 'To put %s back as it was, use Undo on its MoveKit screen within a day.', 'movekit-migration-backup' ), remote ) ) +
			'</p>';
		if ( push && ! withDb ) {
			showResult(
				'success',
				'<p><strong>' + esc( __( 'Push complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
					esc( sprintf( /* translators: 1: list such as Database, Media, 2: site address. */ __( 'Sent %1$s to %2$s.', 'movekit-migration-backup' ), describeParts( s.result.parts ), remote ) ) + '</p>' +
					same +
					undoThere +
					warningsHtml( s.log )
			);
			return;
		}
		if ( ! push && ! withDb ) {
			showResult(
				'success',
				'<p><strong>' + esc( __( 'Pull complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
					esc( sprintf( /* translators: 1: list such as Database, Media, 2: site address. */ __( 'Copied %1$s from %2$s.', 'movekit-migration-backup' ), describeParts( s.result.parts ), remote ) ) + '</p>' +
					same +
					viaRemote +
					warningsHtml( s.log )
			);
			return Promise.all( [ loadList(), loadUndo() ] );
		}
		var partial = ! isAll( s.result.parts || ALL_PARTS );
		if ( push ) {
			showResult(
				'success',
				'<p><strong>' + esc( __( 'Push complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
					esc(
						partial
							? sprintf( /* translators: 1: list such as Database, Media, 2: site address. */ __( 'Sent %1$s to %2$s. Log in there with this site\'s username and password.', 'movekit-migration-backup' ), describeParts( s.result.parts ), remote )
							: sprintf( /* translators: %s: site address. */ __( '%s is now a copy of this site. Log in there with this site\'s username and password.', 'movekit-migration-backup' ), remote )
					) + '</p>' +
					same +
					'<p><a class="button button-primary" target="_blank" rel="noopener" href="' + esc( remote.replace( /\/+$/, '' ) + '/wp-admin/' ) + '">' +
					esc( sprintf( /* translators: %s: site address. */ __( 'Open %s', 'movekit-migration-backup' ), remote ) ) + '</a></p>' +
					undoThere +
					warningsHtml( s.log )
			);
			return;
		}
		showResult(
			'success',
			'<p><strong>' + esc( __( 'Pull complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
				esc(
					partial
						? sprintf( /* translators: 1: list such as Database, Media, 2: site address. */ __( 'Copied %1$s from %2$s. Log in with the username and password from that site.', 'movekit-migration-backup' ), describeParts( s.result.parts ), remote )
						: sprintf( /* translators: %s: site address. */ __( 'This site is now a copy of %s. Log in with the username and password from that site.', 'movekit-migration-backup' ), remote )
				) + '</p>' +
				same +
				'<p><a class="button button-primary" href="' + esc( MoveKit.login ) + '">' + esc( __( 'Log in', 'movekit-migration-backup' ) ) + '</a></p>' +
				viaRemote +
				undoLater( s.result.undo ) +
				warningsHtml( s.log )
		);
	}

	function startTransfer( r, direction ) {
		if ( busy ) {
			return;
		}
		var parts = partsOrWarn();
		if ( ! parts ) {
			return;
		}
		preflight( 'remotes/' + r.id + '/' + direction + '/preflight' )
			.then( function ( c ) {
				if ( c ) {
					confirmTransfer( r, direction, parts, c );
				}
			} )
			.catch( fail );
	}

	function confirmTransfer( r, direction, parts, c ) {
		var live = direction === 'push' ? c.live : ! MoveKit.local;
		var target = direction === 'push' ? r.url : MoveKit.home;
		var msg =
			( direction === 'push'
				? sprintf( /* translators: %s: site address. */ __( 'Push this site to %s?', 'movekit-migration-backup' ), r.url )
				: sprintf( /* translators: 1: other site's address, 2: this site's address. */ __( 'Pull %1$s into %2$s?', 'movekit-migration-backup' ), r.url, MoveKit.home ) ) +
			'\n\n' +
			replacesText( parts, target ) +
			' ' +
			__( 'Each site keeps its own address.', 'movekit-migration-backup' ) +
			( hasDatabase( parts )
				? ' ' +
				  ( direction === 'push'
						? __( 'Afterwards you log in there with this site\'s username and password.', 'movekit-migration-backup' )
						: __( 'Afterwards you log in with the username and password from the other site.', 'movekit-migration-backup' ) )
				: '' ) +
			( live
				? '\n\n' +
				  ( direction === 'push'
						? sprintf( /* translators: %s: site address. */ __( '%s is a live site. Visitors see this site\'s content there as soon as the push finishes.', 'movekit-migration-backup' ), r.url )
						: __( 'This is a live site. Visitors see the pulled site as soon as it finishes.', 'movekit-migration-backup' ) )
				: '' ) +
			warningLines( c.warnings ) +
			'\n\n' +
			( direction === 'push'
				? sprintf( /* translators: %s: site address. */ __( 'You can undo it for a day on the MoveKit screen of %s.', 'movekit-migration-backup' ), r.url )
				: __( 'You can undo it for a day.', 'movekit-migration-backup' ) );
		if ( ! window.confirm( msg ) ) {
			return;
		}
		setBusy( true );
		showProgress( direction === 'push' ? sprintf( /* translators: %s: site address. */ __( 'Pushing to %s', 'movekit-migration-backup' ), r.url ) : sprintf( /* translators: %s: site address. */ __( 'Pulling from %s', 'movekit-migration-backup' ), r.url ) );
		api( 'POST', 'remotes/' + r.id + '/' + direction, { parts: parts, exclude: $( 'movekit-exclude' ).value, changed_only: $( 'movekit-changed-only' ).checked, remote_media: $( 'movekit-remote-media' ).checked } )
			.then( function ( job ) {
				job.kind = direction;
				job.remote = r.url;
				return runJob( job );
			} )
			.then( transferDone )
			.catch( fail );
	}

	/* ------------------------------------------------- paused transfers */

	function pausedHtml( id, direction, remote, message ) {
		var title =
			direction === 'push'
				? sprintf( /* translators: %s: site address. */ __( 'Push to %s is paused.', 'movekit-migration-backup' ), remote )
				: sprintf( /* translators: %s: site address. */ __( 'Pull from %s is paused.', 'movekit-migration-backup' ), remote );
		return (
			'<div class="movekit-paused"><p><strong>' + esc( title ) + '</strong> ' + esc( message ) + '</p>' +
			'<p><button type="button" class="button button-primary" data-resume="' + esc( id ) + '" data-direction="' + esc( direction ) + '" data-remote="' + esc( remote ) + '">' +
			esc( __( 'Resume', 'movekit-migration-backup' ) ) + '</button> ' +
			'<button type="button" class="button" data-discard="' + esc( id ) + '" data-remote="' + esc( remote ) + '">' +
			esc( __( 'Clean up', 'movekit-migration-backup' ) ) + '</button></p></div>'
		);
	}

	function resumeTransfer( id, direction, remote ) {
		if ( busy ) {
			return;
		}
		setBusy( true );
		showProgress(
			direction === 'push'
				? sprintf( /* translators: %s: site address. */ __( 'Resuming push to %s', 'movekit-migration-backup' ), remote )
				: sprintf( /* translators: %s: site address. */ __( 'Resuming pull from %s', 'movekit-migration-backup' ), remote )
		);
		api( 'POST', 'jobs/' + id + '/resume' )
			.then( function ( job ) {
				job.kind = direction;
				job.remote = remote;
				return runJob( job );
			} )
			.then( transferDone )
			.catch( fail );
	}

	function discardTransfer( id, remote ) {
		if ( busy ) {
			return;
		}
		var msg = sprintf(
			/* translators: %s: site address. */ __( 'Clean up the unfinished transfer? This deletes its temporary backup files and unfinished database tables on this site and on %s. Neither site\'s content is changed.', 'movekit-migration-backup' ),
			remote
		);
		if ( ! window.confirm( msg ) ) {
			return;
		}
		setBusy( true );
		api( 'POST', 'jobs/' + id + '/discard' )
			.then( function ( r ) {
				setBusy( false );
				showResult( 'success', '<p><strong>' + esc( __( 'Cleaned up.', 'movekit-migration-backup' ) ) + '</strong></p>' + warningsHtml( r.warnings ) );
				return loadList();
			} )
			.catch( fail );
	}

	function loadPaused() {
		return api( 'GET', 'transfers' ).then( function ( list ) {
			if ( ! list.length || busy ) {
				return;
			}
			showResult(
				'warning',
				list
					.map( function ( t ) {
						return pausedHtml(
							t.job,
							t.direction,
							t.remote,
							sprintf( /* translators: %d: percentage. */ __( 'It stopped at %d%% because the connection was lost.', 'movekit-migration-backup' ), t.progress )
						);
					} )
					.join( '' )
			);
		} );
	}

	$( 'movekit-result' ).addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( el.hasAttribute( 'data-resume' ) ) {
			resumeTransfer( el.getAttribute( 'data-resume' ), el.getAttribute( 'data-direction' ), el.getAttribute( 'data-remote' ) );
		} else if ( el.hasAttribute( 'data-discard' ) ) {
			discardTransfer( el.getAttribute( 'data-discard' ), el.getAttribute( 'data-remote' ) );
		}
	} );

	var DONE = { export: exportDone, import: importDone, push: transferDone, pull: transferDone };

	function resumeTitle( job ) {
		switch ( job.kind ) {
			case 'import':
				return __( 'Resuming restore', 'movekit-migration-backup' );
			case 'push':
				return sprintf( /* translators: %s: site address. */ __( 'Resuming push to %s', 'movekit-migration-backup' ), job.remote );
			case 'pull':
				return sprintf( /* translators: %s: site address. */ __( 'Resuming pull from %s', 'movekit-migration-backup' ), job.remote );
			default:
				return __( 'Resuming backup', 'movekit-migration-backup' );
		}
	}

	/**
	 * Pick up a job that was running when the page was closed or reloaded.
	 */
	function resume() {
		var job = null;
		try {
			job = JSON.parse( localStorage.getItem( STORE ) || 'null' );
		} catch ( e ) {}
		if ( ! job || ! job.job ) {
			return;
		}
		setBusy( true );
		showProgress( resumeTitle( job ) );
		runJob( job )
			.then( DONE[ job.kind ] || exportDone )
			.catch( function ( err ) {
				remember( null );
				if ( err.code === 'movekit_no_job' ) {
					setBusy( false );
					$( 'movekit-progress' ).hidden = true;
					return;
				}
				fail( err );
			} );
	}

	/* ------------------------------------------------------------- upload */

	function randomId() {
		var a = new Uint8Array( 16 );
		crypto.getRandomValues( a );
		return Array.prototype.map.call( a, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function size( b ) {
		var u = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( b >= 1024 && i < u.length - 1 ) {
			b /= 1024;
			i++;
		}
		return b.toFixed( i ? 1 : 0 ) + ' ' + u[ i ];
	}

	function upload( file ) {
		if ( busy || ! file ) {
			return;
		}
		if ( ! /\.movekit$/i.test( file.name ) ) {
			showResult( 'error', '<p>' + esc( __( 'Choose a .movekit backup file.', 'movekit-migration-backup' ) ) + '</p>' );
			return;
		}
		setBusy( true );
		showProgress( sprintf( /* translators: %s: file name. */ __( 'Uploading %s', 'movekit-migration-backup' ), file.name ) );
		var id = randomId();
		var offset = 0;
		var started = Date.now();

		function next() {
			return retry(
				function () {
					var body = new FormData();
					body.append( 'upload_id', id );
					body.append( 'name', file.name );
					body.append( 'total', file.size );
					body.append( 'offset', offset );
					body.append( 'chunk', file.slice( offset, offset + CHUNK ), 'chunk' );
					return api( 'POST', 'uploads', body );
				},
				function ( err, wait ) {
					setProgress( ( offset / file.size ) * 100, retryNote( err, wait ) );
				}
			).then( function ( d ) {
				offset = d.offset;
				var secs = ( Date.now() - started ) / 1000;
				var msg = sprintf( /* translators: 1: amount sent, 2: total size. */ __( '%1$s of %2$s', 'movekit-migration-backup' ), size( offset ), size( file.size ) );
				if ( secs > 1 ) {
					msg += ' (' + size( offset / secs ) + '/s)';
				}
				setProgress( ( offset / file.size ) * 100, msg );
				return d.done ? d : next();
			} );
		}

		next()
			.then( function ( d ) {
				setBusy( false );
				showResult(
					'success',
					'<p><strong>' + esc( __( 'Upload complete.', 'movekit-migration-backup' ) ) + '</strong> ' +
						esc( sprintf( /* translators: %s: file name. */ __( '%s is ready to restore.', 'movekit-migration-backup' ), d.name ) ) + '</p>'
				);
				return loadList();
			} )
			.catch( fail );
	}

	/* --------------------------------------------------------------- list */

	function button( label, cls ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = cls;
		b.textContent = label;
		return b;
	}

	function cell( tr, text ) {
		var td = document.createElement( 'td' );
		td.textContent = text;
		tr.appendChild( td );
		return td;
	}

	function renderList( backups ) {
		var body = $( 'movekit-list' );
		body.innerHTML = '';
		counts.backups = backups.length;
		showIntro();
		if ( ! backups.length ) {
			var empty = document.createElement( 'tr' );
			cell( empty, __( 'No backups yet.', 'movekit-migration-backup' ) ).colSpan = 5;
			body.appendChild( empty );
			return;
		}
		backups.forEach( function ( b ) {
			var tr = document.createElement( 'tr' );
			var name = cell( tr, '' );
			var strong = document.createElement( 'strong' );
			strong.textContent = b.name;
			name.appendChild( strong );
			if ( b.ok && b.parts && ! isAll( b.parts ) ) {
				var partial = document.createElement( 'span' );
				partial.className = 'movekit-partial';
				partial.textContent = describeParts( b.parts );
				name.appendChild( partial );
			}
			if ( ! b.ok ) {
				var bad = document.createElement( 'span' );
				bad.className = 'movekit-bad';
				bad.textContent = __( 'Incomplete or damaged', 'movekit-migration-backup' );
				name.appendChild( bad );
			}
			cell( tr, b.from );
			cell( tr, b.sizeText );
			cell( tr, b.date );
			var actions = cell( tr, '' );
			actions.className = 'movekit-row-actions';
			if ( b.ok ) {
				var dl = document.createElement( 'a' );
				dl.className = 'button';
				dl.href = MoveKit.download + encodeURIComponent( b.name );
				dl.textContent = __( 'Download', 'movekit-migration-backup' );
				dl.setAttribute( 'aria-label', sprintf( /* translators: %s: file name. */ __( 'Download %s', 'movekit-migration-backup' ), b.name ) );
				var restore = button( __( 'Restore', 'movekit-migration-backup' ), 'button movekit-restore' );
				restore.setAttribute( 'aria-label', sprintf( /* translators: %s: file name. */ __( 'Restore %s', 'movekit-migration-backup' ), b.name ) );
				restore.onclick = function () {
					startImport( b );
				};
				actions.appendChild( dl );
				actions.appendChild( document.createTextNode( ' ' ) );
				actions.appendChild( restore );
			}
			var del = button( __( 'Delete', 'movekit-migration-backup' ), 'button-link movekit-delete' );
			del.setAttribute( 'aria-label', sprintf( /* translators: %s: file name. */ __( 'Delete %s', 'movekit-migration-backup' ), b.name ) );
			del.onclick = function () {
				if ( busy || ! window.confirm( sprintf( /* translators: %s: file name. */ __( 'Delete %s? This cannot be undone.', 'movekit-migration-backup' ), b.name ) ) ) {
					return;
				}
				api( 'DELETE', 'backups/' + encodeURIComponent( b.name ) ).then( loadList ).catch( fail );
			};
			actions.appendChild( del );
			tr.setAttribute( 'data-name', b.name );
			body.appendChild( tr );
		} );
		setBusy( busy );
	}

	function loadList() {
		return api( 'GET', 'backups' ).then( renderList );
	}

	/* -------------------------------------------------------------- intro */

	var counts = { backups: null, remotes: null };

	// A short how-to until the first backup or connection.
	function showIntro() {
		$( 'movekit-intro' ).hidden = ! ( counts.backups === 0 && counts.remotes === 0 );
	}

	/* --------------------------------------------------------------- undo */

	function renderUndo( info ) {
		var box = $( 'movekit-undo' );
		if ( ! info ) {
			box.hidden = true;
			box.innerHTML = '';
			return;
		}
		box.innerHTML =
			'<p><strong>' +
			esc( sprintf( /* translators: %s: date and time. */ __( 'The last restore can be undone until %s.', 'movekit-migration-backup' ), untilText( info ) ) ) +
			'</strong> ' +
			esc( sprintf( /* translators: %s: site address. */ __( 'It replaced this site with a copy of %s. Undo puts back what this site had before.', 'movekit-migration-backup' ), info.from ) ) +
			'</p><p><button type="button" class="button button-primary" data-undo>' +
			esc( __( 'Undo restore', 'movekit-migration-backup' ) ) +
			'</button> <button type="button" class="button" data-keep>' +
			esc( __( 'Keep the restore', 'movekit-migration-backup' ) ) +
			'</button></p>';
		box.hidden = false;
		box.setAttribute( 'data-from', info.from );
		setBusy( busy );
	}

	function loadUndo() {
		return api( 'GET', 'undo' ).then( renderUndo );
	}

	function runUndo() {
		var from = $( 'movekit-undo' ).getAttribute( 'data-from' );
		if (
			busy ||
			! window.confirm(
				sprintf( /* translators: %s: site address. */ __( 'Undo the restore of %s? This puts back the database and files this site had before it, and removes the files the restore added.', 'movekit-migration-backup' ), from )
			)
		) {
			return;
		}
		setBusy( true );
		showProgress( __( 'Undoing the restore', 'movekit-migration-backup' ) );
		api( 'POST', 'undo' )
			.then( function ( r ) {
				setBusy( false );
				renderUndo( null );
				var html = '<p><strong>' + esc( __( 'The restore was undone.', 'movekit-migration-backup' ) ) + '</strong> ';
				if ( r.tables ) {
					html +=
						esc( __( 'Log in with the username and password this site had before the restore.', 'movekit-migration-backup' ) ) +
						'</p><p><a class="button button-primary" href="' + esc( MoveKit.login ) + '">' + esc( __( 'Log in', 'movekit-migration-backup' ) ) + '</a>';
				}
				html += '</p>';
				if ( r.failed ) {
					html +=
						'<p>' +
						esc( sprintf( /* translators: %d: number of files. */ _n( '%d file could not be put back. Check the folder permissions in wp-content.', '%d files could not be put back. Check the folder permissions in wp-content.', r.failed, 'movekit-migration-backup' ), r.failed ) ) +
						'</p>';
				}
				showResult( r.failed ? 'warning' : 'success', html );
				if ( ! r.tables ) {
					return loadList();
				}
			} )
			.catch( fail );
	}

	function keepRestore() {
		if ( busy || ! window.confirm( __( 'Keep the restored site? The copy of what it replaced is deleted, and the restore can no longer be undone.', 'movekit-migration-backup' ) ) ) {
			return;
		}
		api( 'DELETE', 'undo' )
			.then( function () {
				renderUndo( null );
				announce( __( 'The restore was kept.', 'movekit-migration-backup' ) );
			} )
			.catch( fail );
	}

	$( 'movekit-undo' ).addEventListener( 'click', function ( e ) {
		if ( e.target.hasAttribute( 'data-undo' ) ) {
			runUndo();
		} else if ( e.target.hasAttribute( 'data-keep' ) ) {
			keepRestore();
		}
	} );

	/* ------------------------------------------------------- push and pull */

	function renderRemotes( remotes ) {
		var body = $( 'movekit-remotes' );
		body.innerHTML = '';
		counts.remotes = remotes.length;
		showIntro();
		if ( ! remotes.length ) {
			var empty = document.createElement( 'tr' );
			cell( empty, __( 'No connected sites yet. Paste a key above to connect one.', 'movekit-migration-backup' ) ).colSpan = 3;
			body.appendChild( empty );
			return;
		}
		remotes.forEach( function ( r ) {
			var tr = document.createElement( 'tr' );
			var name = cell( tr, '' );
			var strong = document.createElement( 'strong' );
			strong.textContent = r.url;
			name.appendChild( strong );
			cell( tr, r.addedText );
			var actions = cell( tr, '' );
			actions.className = 'movekit-row-actions';
			var pull = button( __( 'Pull', 'movekit-migration-backup' ), 'button movekit-pull' );
			pull.title = sprintf( /* translators: %s: site address. */ __( 'Replace this site with %s', 'movekit-migration-backup' ), r.url );
			pull.setAttribute( 'aria-label', pull.title );
			pull.onclick = function () {
				startTransfer( r, 'pull' );
			};
			var push = button( __( 'Push', 'movekit-migration-backup' ), 'button movekit-push' );
			push.title = sprintf( /* translators: %s: site address. */ __( 'Replace %s with this site', 'movekit-migration-backup' ), r.url );
			push.setAttribute( 'aria-label', push.title );
			push.onclick = function () {
				startTransfer( r, 'push' );
			};
			var del = button( __( 'Disconnect', 'movekit-migration-backup' ), 'button-link movekit-delete' );
			del.setAttribute( 'aria-label', sprintf( /* translators: %s: site address. */ __( 'Disconnect %s', 'movekit-migration-backup' ), r.url ) );
			del.onclick = function () {
				if ( busy || ! window.confirm( sprintf( /* translators: %s: site address. */ __( 'Disconnect %s? You can connect it again with its key.', 'movekit-migration-backup' ), r.url ) ) ) {
					return;
				}
				api( 'DELETE', 'remotes/' + r.id ).then( renderRemotes ).catch( fail );
			};
			actions.appendChild( pull );
			actions.appendChild( document.createTextNode( ' ' ) );
			actions.appendChild( push );
			actions.appendChild( del );
			tr.setAttribute( 'data-remote', r.url );
			body.appendChild( tr );
		} );
		setBusy( busy );
	}

	function loadRemotes() {
		return api( 'GET', 'remotes' ).then( renderRemotes );
	}

	function connect( e ) {
		e.preventDefault();
		var input = $( 'movekit-connect-key' );
		var key = input.value.trim();
		if ( busy || ! key ) {
			return;
		}
		setBusy( true );
		$( 'movekit-result' ).hidden = true;
		api( 'POST', 'remotes', { key: key } )
			.then( function ( list ) {
				setBusy( false );
				input.value = '';
				renderRemotes( list );
				showResult( 'success', '<p>' + esc( __( 'Connected. You can now push to or pull from that site.', 'movekit-migration-backup' ) ) + '</p>' );
			} )
			.catch( function ( err ) {
				setBusy( false );
				showResult( 'error', '<p><strong>' + esc( __( 'Could not connect.', 'movekit-migration-backup' ) ) + '</strong> ' + esc( err.message ) + '</p>' );
			} );
	}

	function setKey( data ) {
		$( 'movekit-key' ).value = data.key;
	}

	function copyKey() {
		var input = $( 'movekit-key' );
		var done = function () {
			$( 'movekit-key-copy' ).textContent = __( 'Copied', 'movekit-migration-backup' );
			setTimeout( function () {
				$( 'movekit-key-copy' ).textContent = __( 'Copy', 'movekit-migration-backup' );
			}, 2000 );
		};
		var fallback = function () {
			var type = input.type;
			input.type = 'text';
			input.select();
			document.execCommand( 'copy' );
			input.type = type;
			input.setSelectionRange( 0, 0 );
			done();
		};
		// The Clipboard API only works on https or localhost; local sites are often plain http.
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done, fallback );
			return;
		}
		fallback();
	}

	function toggleKey() {
		var input = $( 'movekit-key' );
		var show = input.type === 'password';
		input.type = show ? 'text' : 'password';
		$( 'movekit-key-show' ).textContent = show ? __( 'Hide', 'movekit-migration-backup' ) : __( 'Show', 'movekit-migration-backup' );
	}

	function regenerateKey() {
		if ( busy || ! window.confirm( __( 'Create a new key? Sites connected with the current key stop working until you paste the new key into them.', 'movekit-migration-backup' ) ) ) {
			return;
		}
		api( 'POST', 'connection/regenerate' )
			.then( function ( data ) {
				setKey( data );
				showResult( 'success', '<p>' + esc( __( 'New key created. Paste it into the sites that connect to this one.', 'movekit-migration-backup' ) ) + '</p>' );
			} )
			.catch( fail );
	}

	function restoreParts() {
		var saved = null;
		try {
			saved = JSON.parse( localStorage.getItem( PARTS_KEY ) || 'null' );
		} catch ( e ) {}
		if ( Array.isArray( saved ) && saved.length ) {
			document.querySelectorAll( '#movekit-parts input' ).forEach( function ( el ) {
				el.checked = saved.indexOf( el.value ) !== -1;
			} );
		}
	}

	// Name the parts on the backup button when not everything is included.
	function exportLabel() {
		var parts = selectedParts();
		$( 'movekit-export' ).textContent =
			isAll( parts ) || ! parts.length
				? __( 'Create backup', 'movekit-migration-backup' )
				: sprintf( /* translators: %s: list such as Database, Media. */ __( 'Back up %s', 'movekit-migration-backup' ), describeParts( parts ) );
	}

	$( 'movekit-parts' ).addEventListener( 'change', function () {
		exportLabel();
		try {
			localStorage.setItem( PARTS_KEY, JSON.stringify( selectedParts() ) );
		} catch ( e ) {}
	} );
	$( 'movekit-select-all' ).addEventListener( 'click', function () {
		document.querySelectorAll( '#movekit-parts input' ).forEach( function ( el ) {
			el.checked = true;
		} );
		$( 'movekit-parts' ).dispatchEvent( new Event( 'change' ) );
	} );
	restoreParts();
	exportLabel();
	$( 'movekit-connect' ).addEventListener( 'submit', connect );
	$( 'movekit-key-show' ).addEventListener( 'click', toggleKey );
	$( 'movekit-key-copy' ).addEventListener( 'click', copyKey );
	$( 'movekit-key-regenerate' ).addEventListener( 'click', regenerateKey );
	$( 'movekit-export' ).addEventListener( 'click', startExport );
	$( 'movekit-choose' ).addEventListener( 'click', function () {
		$( 'movekit-file' ).click();
	} );
	$( 'movekit-file' ).addEventListener( 'change', function ( e ) {
		upload( e.target.files[ 0 ] );
		e.target.value = '';
	} );
	loadList().catch( fail );
	loadRemotes().catch( fail );
	loadPaused().catch( fail );
	if ( $( 'movekit-media-stop' ) ) {
		$( 'movekit-media-stop' ).addEventListener( 'click', function () {
			api( 'DELETE', 'media-source' )
				.then( function () {
					$( 'movekit-media-note' ).hidden = true;
				} )
				.catch( fail );
		} );
	}
	loadUndo().catch( fail );
	api( 'GET', 'connection' ).then( setKey ).catch( fail );
	resume();
} )();
