/**
 * Visitrix tracker. Sends one beacon per pageview plus a few engagement pings.
 * No cookies: a random visitor id and the current visit live in localStorage.
 */
(function () {
	'use strict';

	var cfg = window.visitrixCfg;
	var nav = window.navigator;
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}
	if ( cfg.dnt && ( nav.doNotTrack === '1' || window.doNotTrack === '1' ) ) {
		return;
	}
	if ( nav.webdriver || /bot|crawl|spider|headless|lighthouse/i.test( nav.userAgent ) ) {
		return;
	}

	var TIMEOUT = 30 * 60 * 1000; // A visit ends after 30 minutes of inactivity.
	var now = Date.now();

	function rid() {
		var c = window.crypto, a = new Uint8Array( 16 ), out = '', i;
		if ( c && c.getRandomValues ) {
			c.getRandomValues( a );
		} else {
			for ( i = 0; i < 16; i++ ) { a[ i ] = Math.floor( Math.random() * 256 ); }
		}
		for ( i = 0; i < 16; i++ ) { out += ( a[ i ] < 16 ? '0' : '' ) + a[ i ].toString( 16 ); }
		return out;
	}
	function get( k ) {
		try { return window.localStorage.getItem( k ); } catch ( e ) { return null; }
	}
	function set( k, v ) {
		try { window.localStorage.setItem( k, v ); } catch ( e ) {}
	}

	var newVisitor = 0;
	var visitor = get( 'visitrix_v' );
	if ( ! visitor || ! /^[a-f0-9]{32}$/.test( visitor ) ) {
		visitor = rid();
		newVisitor = 1;
		set( 'visitrix_v', visitor );
	}

	var ref = document.referrer || '';
	var external = false;
	if ( ref ) {
		try { external = new URL( ref ).hostname !== location.hostname; } catch ( e ) {}
	}

	var sess = null;
	try { sess = JSON.parse( get( 'visitrix_s' ) || 'null' ); } catch ( e ) {}

	// New visit after a timeout or when the visitor arrives from another source.
	if ( ! sess || ! /^[a-f0-9]{32}$/.test( sess.id ) || now - sess.t > TIMEOUT || ( external && ref !== sess.r ) ) {
		sess = { id: rid(), r: external ? ref : '' };
	} else {
		newVisitor = 0;
	}
	sess.t = now;
	set( 'visitrix_s', JSON.stringify( sess ) );

	function send( data ) {
		data.s = sess.id;
		var body = JSON.stringify( data );
		try {
			if ( nav.sendBeacon && nav.sendBeacon( cfg.endpoint, new Blob( [ body ], { type: 'text/plain' } ) ) ) {
				return;
			}
		} catch ( e ) {}
		try {
			window.fetch( cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } } );
		} catch ( e ) {}
	}

	send( {
		t: 'pv',
		v: visitor,
		nv: newVisitor,
		u: location.pathname,
		q: location.search,
		r: ref,
		ti: document.title,
		tp: nav.maxTouchPoints || 0
	} );

	// Engagement pings keep the visit duration accurate (and the bounce flag honest).
	var lastPing = now, ticks = 0, delays = [ 15, 15, 30 ];
	function ping() {
		var t = Date.now();
		if ( t - lastPing < 2000 ) {
			return;
		}
		lastPing = t;
		sess.t = t;
		set( 'visitrix_s', JSON.stringify( sess ) );
		send( { t: 'ping' } );
	}
	function schedule() {
		if ( ticks >= 40 ) {
			return;
		}
		setTimeout( function () {
			ticks++;
			if ( document.visibilityState === 'visible' ) {
				ping();
			}
			schedule();
		}, ( delays[ ticks ] || 60 ) * 1000 );
	}
	schedule();

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			ping();
		}
	} );
	window.addEventListener( 'pagehide', ping );
})();
