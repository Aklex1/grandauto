/**
 * Visitrix dashboard. Vanilla JS, no dependencies: SVG charts are drawn in place.
 */
(function () {
	'use strict';

	var cfg = window.visitrixAdmin;
	var root = document.getElementById( 'visitrix-app' );
	if ( ! cfg || ! root ) {
		return;
	}

	var T = cfg.i18n || {};
	var SVGNS = 'http://www.w3.org/2000/svg';
	var STORE = 'visitrix_admin_period';

	var SOURCE_COLORS = {
		direct: '#4c8df6', search: '#f5a623', social: '#9b6bdf', messenger: '#2fb5e8',
		ai: '#e35d9a', referral: '#36b37e', ad: '#e2574c', internal: '#a0a8b5'
	};
	var DEVICE_COLORS = { desktop: '#4c8df6', mobile: '#f5a623', tablet: '#36b37e' };
	var PALETTE = [ '#4c8df6', '#f5a623', '#36b37e', '#9b6bdf', '#e2574c', '#2fb5e8', '#e35d9a', '#c9b037', '#a0a8b5' ];
	var SERIES = [
		{ key: 'v', label: T.visits, color: '#4c8df6', on: true },
		{ key: 'u', label: T.visitors, color: '#f5a623', on: true },
		{ key: 'p', label: T.pageviews, color: '#36b37e', on: false },
		{ key: 'n', label: T.newVisitors, color: '#9b6bdf', on: false }
	];
	var VISITOR_TYPES = {
		1: { label: T.newVisitors, color: '#9b6bdf' },
		0: { label: T.returning, color: '#36b37e' }
	};

	var state = loadState();
	var report = null;
	var request = 0;

	/* ---------- helpers ---------- */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		setAttrs( node, attrs );
		append( node, children );
		return node;
	}
	function svg( tag, attrs, children ) {
		var node = document.createElementNS( SVGNS, tag );
		setAttrs( node, attrs );
		append( node, children );
		return node;
	}
	function setAttrs( node, attrs ) {
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				return;
			}
			if ( k === 'text' ) {
				node.textContent = v;
			} else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) {
				node.addEventListener( k.slice( 2 ), v );
			} else {
				node.setAttribute( k, v );
			}
		} );
	}
	function append( node, children ) {
		( Array.isArray( children ) ? children : [ children ] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' || typeof c === 'number' ? document.createTextNode( String( c ) ) : c );
		} );
	}

	var locale = cfg.locale || 'en-US';
	var nf = safeFormat( {} );
	var nf1 = safeFormat( { maximumFractionDigits: 1, minimumFractionDigits: 1 } );
	var nf2 = safeFormat( { maximumFractionDigits: 2, minimumFractionDigits: 2 } );

	function safeFormat( opts ) {
		try {
			return new Intl.NumberFormat( locale, opts );
		} catch ( e ) {
			return new Intl.NumberFormat( 'en-US', opts );
		}
	}
	function dateFmt( opts ) {
		try {
			return new Intl.DateTimeFormat( locale, opts );
		} catch ( e ) {
			return new Intl.DateTimeFormat( 'en-US', opts );
		}
	}
	var dfShort = dateFmt( { day: 'numeric', month: 'short' } );
	var dfLong = dateFmt( { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' } );
	var dfRange = dateFmt( { day: 'numeric', month: 'long', year: 'numeric' } );

	function num( n ) { return nf.format( n || 0 ); }
	function pct( n ) { return nf1.format( n || 0 ) + ' %'; }
	function dur( s ) {
		s = Math.round( s || 0 );
		var h = Math.floor( s / 3600 ), m = Math.floor( ( s % 3600 ) / 60 ), sec = s % 60;
		var pad = function ( x ) { return ( x < 10 ? '0' : '' ) + x; };
		return h ? h + ':' + pad( m ) + ':' + pad( sec ) : m + ':' + pad( sec );
	}
	function metric( key, value ) {
		if ( key === 'bounce' || key === 'new_visitors' ) {
			return pct( value );
		}
		if ( key === 'depth' ) {
			return nf2.format( value || 0 );
		}
		if ( key === 'duration' ) {
			return dur( value );
		}
		return num( value );
	}

	function parseDay( s ) {
		var p = s.split( /[- ]/ ).map( Number );
		return new Date( p[ 0 ], p[ 1 ] - 1, p[ 2 ], p[ 3 ] || 0 );
	}
	function ymd( d ) {
		var m = d.getMonth() + 1, day = d.getDate();
		return d.getFullYear() + '-' + ( m < 10 ? '0' : '' ) + m + '-' + ( day < 10 ? '0' : '' ) + day;
	}
	function addDays( s, n ) {
		var d = parseDay( s );
		d.setDate( d.getDate() + n );
		return ymd( d );
	}
	function decodePath( p ) {
		try { return decodeURIComponent( p ); } catch ( e ) { return p; }
	}

	/* ---------- period ---------- */

	var PRESETS = [
		{ key: 'today', label: T.today, range: function ( t ) { return [ t, t ]; } },
		{ key: 'yesterday', label: T.yesterday, range: function ( t ) { var y = addDays( t, -1 ); return [ y, y ]; } },
		{ key: 'days3', label: T.days3, range: function ( t ) { return [ addDays( t, -2 ), t ]; } },
		{ key: 'week', label: T.week, range: function ( t ) { return [ addDays( t, -6 ), t ]; } },
		{ key: 'month', label: T.month, range: function ( t ) { return [ addDays( t, -29 ), t ]; } },
		{ key: 'custom', label: T.custom, range: null }
	];

	function loadState() {
		var s = null;
		try { s = JSON.parse( window.localStorage.getItem( STORE ) || 'null' ); } catch ( e ) {}
		if ( ! s || ! s.preset ) {
			s = { preset: 'week' };
		}
		return s;
	}
	function saveState() {
		try { window.localStorage.setItem( STORE, JSON.stringify( state ) ); } catch ( e ) {}
	}
	function currentRange() {
		var preset = PRESETS.filter( function ( p ) { return p.key === state.preset; } )[ 0 ];
		if ( preset && preset.range ) {
			return preset.range( cfg.today );
		}
		var from = state.from || addDays( cfg.today, -6 ), to = state.to || cfg.today;
		return from > to ? [ to, from ] : [ from, to ];
	}

	/* ---------- layout ---------- */

	var header, body, rangeLabel, customBox, fromInput, toInput;

	function buildShell() {
		var seg = el( 'div', { class: 'vsx-seg', role: 'group' } );
		PRESETS.forEach( function ( p ) {
			seg.appendChild( el( 'button', {
				type: 'button',
				class: 'vsx-seg__btn' + ( state.preset === p.key ? ' is-active' : '' ),
				'data-key': p.key,
				'aria-pressed': state.preset === p.key ? 'true' : 'false',
				text: p.label,
				onclick: function () { selectPreset( p.key ); }
			} ) );
		} );

		var r = currentRange();
		fromInput = el( 'input', { type: 'date', class: 'vsx-date', value: r[ 0 ], max: cfg.today } );
		toInput = el( 'input', { type: 'date', class: 'vsx-date', value: r[ 1 ], max: cfg.today } );
		customBox = el( 'div', { class: 'vsx-custom' + ( state.preset === 'custom' ? '' : ' is-hidden' ) }, [
			fromInput, el( 'span', { class: 'vsx-dash', text: '—' } ), toInput,
			el( 'button', { type: 'button', class: 'vsx-btn vsx-btn--action', text: T.apply, onclick: applyCustom } )
		] );

		rangeLabel = el( 'div', { class: 'vsx-head__range' } );
		header = el( 'div', { class: 'vsx-head' }, [
			el( 'div', { class: 'vsx-head__title' }, [ el( 'h2', { text: T.title } ), rangeLabel ] ),
			el( 'div', { class: 'vsx-head__controls' }, [
				seg, customBox,
				el( 'button', { type: 'button', class: 'vsx-btn vsx-btn--icon', title: T.refresh, 'aria-label': T.refresh, onclick: function () { load( true ); } }, [
					svg( 'svg', { viewBox: '0 0 24 24', width: 16, height: 16, 'aria-hidden': 'true' }, [
						svg( 'path', { d: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } )
					] )
				] )
			] )
		] );
		body = el( 'div', { class: 'vsx-body' } );
		root.className = 'vsx-app';
		root.appendChild( header );
		root.appendChild( body );
	}

	function selectPreset( key ) {
		state.preset = key;
		Array.prototype.forEach.call( header.querySelectorAll( '.vsx-seg__btn' ), function ( b ) {
			var on = b.getAttribute( 'data-key' ) === key;
			b.classList.toggle( 'is-active', on );
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		} );
		customBox.classList.toggle( 'is-hidden', key !== 'custom' );
		if ( key === 'custom' ) {
			fromInput.focus();
			return;
		}
		saveState();
		load();
	}

	function applyCustom() {
		if ( ! fromInput.value || ! toInput.value ) {
			return;
		}
		state.from = fromInput.value;
		state.to = toInput.value;
		saveState();
		load();
	}

	/* ---------- data ---------- */

	function load() {
		var r = currentRange();
		var id = ++request;
		rangeLabel.textContent = r[ 0 ] === r[ 1 ]
			? dfRange.format( parseDay( r[ 0 ] ) )
			: dfRange.format( parseDay( r[ 0 ] ) ) + ' — ' + dfRange.format( parseDay( r[ 1 ] ) );

		root.classList.add( 'is-loading' );
		if ( ! report ) {
			body.textContent = '';
			body.appendChild( el( 'div', { class: 'vsx-card vsx-empty', text: T.loading } ) );
		}

		var url = new URL( cfg.rest, window.location.href );
		url.searchParams.set( 'from', r[ 0 ] );
		url.searchParams.set( 'to', r[ 1 ] );

		window.fetch( url.toString(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( res.status );
				}
				return res.json();
			} )
			.then( function ( data ) {
				if ( id !== request ) {
					return;
				}
				report = data;
				render();
			} )
			.catch( function () {
				if ( id !== request ) {
					return;
				}
				body.textContent = '';
				body.appendChild( el( 'div', { class: 'vsx-card vsx-empty vsx-error', text: T.error } ) );
			} )
			.then( function () {
				if ( id === request ) {
					root.classList.remove( 'is-loading' );
				}
			} );
	}

	/* ---------- render ---------- */

	function render() {
		var d = report;
		body.textContent = '';
		body.appendChild( kpis( d.totals, d.previous ) );

		if ( ! d.totals.visits ) {
			body.appendChild( el( 'div', { class: 'vsx-card vsx-empty', text: T.noData } ) );
			return;
		}

		body.appendChild( chartCard( d ) );

		var sources = d.sources.map( function ( r ) {
			return { name: T[ 'src_' + r.name ] || r.name, value: r.visits, color: SOURCE_COLORS[ r.name ] };
		} );
		var devices = d.devices.map( function ( r ) {
			return { name: T[ 'dev_' + r.name ] || r.name, value: r.visits, color: DEVICE_COLORS[ r.name ] };
		} );

		body.appendChild( el( 'div', { class: 'vsx-grid' }, [
			card( T.sources, donut( sources, d.totals.visits ) ),
			card( T.devices, donut( devices, d.totals.visits ) ),
			card( T.search, barList( d.search, d.totals.visits ) ),
			card( T.browsers, barList( d.browsers, d.totals.visits ) ),
			card( T.os, barList( d.os, d.totals.visits ) ),
			card( T.landing, barList( d.landing.map( function ( r ) {
				return Object.assign( {}, r, { name: decodePath( r.name ), href: cfg.home.replace( /\/$/, '' ) + r.name } );
			} ), d.totals.visits ) )
		] ) );

		body.appendChild( returningCard( d.returning || [], d.totals.visits ) );
		body.appendChild( card( T.sourceDetails, metricsTable( d.details, true ), 'vsx-card--wide' ) );
		body.appendChild( card( T.pages, pagesTable( d.pages ), 'vsx-card--wide' ) );
		if ( d.utm && d.utm.length ) {
			body.appendChild( card( T.utm, metricsTable( d.utm, false ), 'vsx-card--wide' ) );
		}
		body.appendChild( el( 'p', { class: 'vsx-foot', text: T.updated + ': ' + d.generated } ) );
	}

	function card( title, content, extra ) {
		return el( 'section', { class: 'vsx-card ' + ( extra || '' ) }, [
			el( 'h3', { class: 'vsx-card__title', text: title } ),
			content
		] );
	}

	function kpis( cur, prev ) {
		var items = [
			{ key: 'visits', label: T.visits },
			{ key: 'visitors', label: T.visitors },
			{ key: 'pageviews', label: T.pageviews },
			{ key: 'bounce', label: T.bounce, hint: T.bounceHint, inverse: true },
			{ key: 'depth', label: T.depth, hint: T.depthHint },
			{ key: 'duration', label: T.duration, hint: T.durationHint },
			{ key: 'new_visitors', label: T.newVisitors, hint: T.newHint }
		];
		return el( 'div', { class: 'vsx-card vsx-kpis' }, items.map( function ( it ) {
			var c = cur[ it.key ], p = prev[ it.key ], delta = null;
			if ( p > 0 ) {
				delta = ( c - p ) / p * 100;
			}
			var good = delta === null || Math.abs( delta ) < 0.05 ? '' : ( ( delta > 0 ) !== !! it.inverse ? 'is-up' : 'is-down' );
			return el( 'div', { class: 'vsx-kpi', title: it.hint || '' }, [
				el( 'div', { class: 'vsx-kpi__label', text: it.label } ),
				el( 'div', { class: 'vsx-kpi__value', text: metric( it.key, c ) } ),
				delta === null ? el( 'div', { class: 'vsx-kpi__delta', text: ' ' } ) : el( 'div', {
					class: 'vsx-kpi__delta ' + good,
					title: T.vsPrev,
					text: ( delta > 0 ? '▲ ' : ( delta < 0 ? '▼ ' : '' ) ) + nf1.format( Math.abs( delta ) ) + ' %'
				} )
			] );
		} ) );
	}

	/* ---------- line chart ---------- */

	var chartBox = null;

	function chartCard( d ) {
		var legend = el( 'div', { class: 'vsx-legend' }, SERIES.map( function ( s ) {
			return el( 'label', { class: 'vsx-chip' + ( s.on ? ' is-on' : '' ), style: '--c:' + s.color }, [
				el( 'input', { type: 'checkbox', checked: s.on ? 'checked' : null, onchange: function ( e ) {
					s.on = e.target.checked;
					e.target.parentNode.classList.toggle( 'is-on', s.on );
					drawChart();
				} } ),
				el( 'span', { class: 'vsx-chip__dot' } ),
				s.label
			] );
		} ) );
		chartBox = el( 'div', { class: 'vsx-chart' } );
		var c = el( 'section', { class: 'vsx-card vsx-card--wide' }, [
			el( 'div', { class: 'vsx-card__head' }, [
				el( 'h3', { class: 'vsx-card__title' }, [ T.traffic, el( 'span', { class: 'vsx-muted', text: ' · ' + ( d.hourly ? T.byHour : T.byDay ) } ) ] ),
				legend
			] ),
			chartBox
		] );
		setTimeout( drawChart, 0 ); // After the card is attached, so its width is known.
		return c;
	}

	function niceMax( v ) {
		if ( v <= 4 ) {
			return 4;
		}
		var exp = Math.pow( 10, Math.floor( Math.log10( v ) ) );
		var f = v / exp;
		var nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return nice * exp;
	}

	function bucketLabel( b, hourly, full ) {
		var d = parseDay( b );
		if ( ! hourly ) {
			return full ? dfLong.format( d ) : dfShort.format( d );
		}
		var hh = ( d.getHours() < 10 ? '0' : '' ) + d.getHours() + ':00';
		return full ? dfLong.format( d ) + ', ' + hh : hh;
	}

	function drawChart() {
		if ( ! chartBox || ! report || ! chartBox.isConnected ) {
			return;
		}
		var data = report.series, hourly = report.hourly;
		var W = Math.max( chartBox.clientWidth, 300 ), H = 280;
		var pad = { l: 44, r: 12, t: 12, b: 30 };
		var iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
		var active = SERIES.filter( function ( s ) { return s.on; } );
		var max = 0;
		active.forEach( function ( s ) {
			data.forEach( function ( p ) { max = Math.max( max, p[ s.key ] ); } );
		} );
		max = niceMax( max );
		var n = data.length;
		var x = function ( i ) { return pad.l + ( n <= 1 ? iw / 2 : i * iw / ( n - 1 ) ); };
		var y = function ( v ) { return pad.t + ih - v / max * ih; };

		var g = svg( 'svg', { class: 'vsx-chart__svg', width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': T.traffic } );

		for ( var t = 0; t <= 4; t++ ) {
			var v = max / 4 * t, yy = Math.round( y( v ) ) + 0.5;
			g.appendChild( svg( 'line', { x1: pad.l, x2: W - pad.r, y1: yy, y2: yy, class: 'vsx-grid-line' } ) );
			g.appendChild( svg( 'text', { x: pad.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'vsx-axis', text: num( v ) } ) );
		}

		// X labels: hourly by local hour, daily by date; thinned to fit.
		var step = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( iw / 64 ) ) ) );
		data.forEach( function ( p, i ) {
			if ( i % step !== 0 ) {
				return;
			}
			var lbl = bucketLabel( p.b, hourly, false );
			if ( hourly && ( i === 0 || p.b.slice( 11 ) === '00' ) && n > 24 ) {
				lbl = dfShort.format( parseDay( p.b ) );
			}
			g.appendChild( svg( 'text', { x: x( i ), y: H - 8, 'text-anchor': 'middle', class: 'vsx-axis', text: lbl } ) );
		} );

		active.slice().reverse().forEach( function ( s, idx ) {
			var pts = data.map( function ( p, i ) { return x( i ).toFixed( 1 ) + ',' + y( p[ s.key ] ).toFixed( 1 ); } );
			if ( idx === active.length - 1 && n > 1 ) {
				g.appendChild( svg( 'path', {
					d: 'M' + x( 0 ) + ',' + y( 0 ) + ' L' + pts.join( ' L' ) + ' L' + x( n - 1 ) + ',' + y( 0 ) + 'Z',
					fill: s.color, 'fill-opacity': 0.1
				} ) );
			}
			g.appendChild( svg( 'polyline', { points: pts.join( ' ' ), fill: 'none', stroke: s.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			if ( n <= 40 ) {
				data.forEach( function ( p, i ) {
					g.appendChild( svg( 'circle', { cx: x( i ), cy: y( p[ s.key ] ), r: n === 1 ? 4 : 2.5, fill: '#fff', stroke: s.color, 'stroke-width': 2 } ) );
				} );
			}
		} );

		var cursor = svg( 'line', { y1: pad.t, y2: pad.t + ih, class: 'vsx-cursor', visibility: 'hidden' } );
		g.appendChild( cursor );
		var tip = el( 'div', { class: 'vsx-tip', hidden: 'hidden' } );

		g.appendChild( svg( 'rect', {
			x: pad.l, y: pad.t, width: iw, height: ih, fill: 'transparent',
			onmousemove: function ( e ) {
				var rect = g.getBoundingClientRect();
				var mx = e.clientX - rect.left;
				var i = n <= 1 ? 0 : Math.round( ( mx - pad.l ) / iw * ( n - 1 ) );
				i = Math.max( 0, Math.min( n - 1, i ) );
				var p = data[ i ];
				cursor.setAttribute( 'x1', x( i ) );
				cursor.setAttribute( 'x2', x( i ) );
				cursor.setAttribute( 'visibility', 'visible' );
				tip.textContent = '';
				tip.appendChild( el( 'div', { class: 'vsx-tip__title', text: bucketLabel( p.b, hourly, true ) } ) );
				SERIES.forEach( function ( s ) {
					if ( s.on ) {
						tip.appendChild( el( 'div', { class: 'vsx-tip__row' }, [
							el( 'span', { class: 'vsx-chip__dot', style: '--c:' + s.color } ),
							el( 'span', { text: s.label } ),
							el( 'b', { text: num( p[ s.key ] ) } )
						] ) );
					}
				} );
				tip.hidden = false;
				var left = x( i ) + 14;
				if ( left + tip.offsetWidth > W ) {
					left = x( i ) - tip.offsetWidth - 14;
				}
				tip.style.left = Math.max( 0, left ) + 'px';
				tip.style.top = pad.t + 'px';
			},
			onmouseleave: function () {
				cursor.setAttribute( 'visibility', 'hidden' );
				tip.hidden = true;
			}
		} ) );

		chartBox.textContent = '';
		chartBox.appendChild( g );
		chartBox.appendChild( tip );
	}

	var resizeTimer;
	window.addEventListener( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( drawChart, 150 );
	} );

	/* ---------- donut ---------- */

	function donut( items, total ) {
		if ( ! items.length ) {
			return el( 'div', { class: 'vsx-empty-sm', text: T.noData } );
		}
		if ( items.length > 6 ) {
			var rest = items.slice( 5 ).reduce( function ( a, b ) { return a + b.value; }, 0 );
			items = items.slice( 0, 5 ).concat( [ { name: T.other, value: rest, color: '#c4c9d1' } ] );
		}
		items.forEach( function ( it, i ) { it.color = it.color || PALETTE[ i % PALETTE.length ]; } );

		var R = 54, C = 2 * Math.PI * R, offset = 0;
		var sum = items.reduce( function ( a, b ) { return a + b.value; }, 0 ) || 1;
		var chart = svg( 'svg', { viewBox: '0 0 140 140', width: 140, height: 140, class: 'vsx-donut', role: 'img' } );
		chart.appendChild( svg( 'circle', { cx: 70, cy: 70, r: R, fill: 'none', stroke: '#eef0f3', 'stroke-width': 18 } ) );
		items.forEach( function ( it ) {
			var len = it.value / sum * C;
			chart.appendChild( svg( 'circle', {
				cx: 70, cy: 70, r: R, fill: 'none', stroke: it.color, 'stroke-width': 18,
				'stroke-dasharray': Math.max( 0, len - ( items.length > 1 ? 1.5 : 0 ) ) + ' ' + C,
				'stroke-dashoffset': -offset,
				transform: 'rotate(-90 70 70)'
			}, [ svg( 'title', { text: it.name + ': ' + num( it.value ) } ) ] ) );
			offset += len;
		} );
		chart.appendChild( svg( 'text', { x: 70, y: 70, 'text-anchor': 'middle', class: 'vsx-donut__num', text: num( total ) } ) );
		chart.appendChild( svg( 'text', { x: 70, y: 88, 'text-anchor': 'middle', class: 'vsx-donut__lbl', text: T.visits } ) );

		var legend = el( 'table', { class: 'vsx-dlegend' }, items.map( function ( it ) {
			return el( 'tr', null, [
				el( 'td', null, [ el( 'span', { class: 'vsx-chip__dot', style: '--c:' + it.color } ), it.name ] ),
				el( 'td', { class: 'vsx-num', text: num( it.value ) } ),
				el( 'td', { class: 'vsx-num vsx-muted', text: pct( it.value / sum * 100 ) } )
			] );
		} ) );
		return el( 'div', { class: 'vsx-donut-wrap' }, [ chart, legend ] );
	}

	/* ---------- bar list ---------- */

	function barList( rows, total ) {
		if ( ! rows.length ) {
			return el( 'div', { class: 'vsx-empty-sm', text: T.noData } );
		}
		var max = rows[ 0 ].visits || 1;
		return el( 'ul', { class: 'vsx-bars' }, rows.map( function ( r ) {
			var name = r.name || '—';
			return el( 'li', { class: 'vsx-bar' }, [
				el( 'div', { class: 'vsx-bar__fill', style: 'width:' + ( r.visits / max * 100 ).toFixed( 1 ) + '%' } ),
				r.href
					? el( 'a', { class: 'vsx-bar__name', href: r.href, target: '_blank', rel: 'noopener noreferrer', title: name, text: name } )
					: el( 'span', { class: 'vsx-bar__name', title: name, text: name } ),
				el( 'span', { class: 'vsx-bar__val', text: num( r.visits ) } ),
				el( 'span', { class: 'vsx-bar__pct', text: pct( total ? r.visits / total * 100 : 0 ) } )
			] );
		} ) );
	}

	/* ---------- tables ---------- */

	/* ---------- new vs returning ---------- */

	function returningCard( rows, total ) {
		rows = rows.map( function ( r ) {
			var t = VISITOR_TYPES[ r.name ] || { label: r.name, color: '#a0a8b5' };
			return Object.assign( {}, r, { name: t.label, color: t.color } );
		} );
		var chart = donut( rows.map( function ( r ) {
			return { name: r.name, value: r.visits, color: r.color };
		} ), total );
		return el( 'section', { class: 'vsx-card vsx-card--wide' }, [
			el( 'h3', { class: 'vsx-card__title', text: T.newReturning } ),
			el( 'div', { class: 'vsx-split' }, [
				el( 'div', { class: 'vsx-split__chart' }, chart ),
				el( 'div', { class: 'vsx-split__table' }, metricsTable( rows, false, T.visitorType ) )
			] )
		] );
	}

	function metricsTable( rows, withType, nameLabel ) {
		if ( ! rows.length ) {
			return el( 'div', { class: 'vsx-empty-sm', text: T.noData } );
		}
		var head = el( 'tr', null, [
			el( 'th', { text: nameLabel || T.source } ),
			el( 'th', { class: 'vsx-num', text: T.visits } ),
			el( 'th', { class: 'vsx-num', text: T.visitors } ),
			el( 'th', { class: 'vsx-num', text: T.bounce } ),
			el( 'th', { class: 'vsx-num', text: T.depth } ),
			el( 'th', { class: 'vsx-num', text: T.duration } )
		] );
		var tbody = el( 'tbody', null, rows.map( function ( r ) {
			var nameCell = [ el( 'div', { class: 'vsx-cell-name', text: r.name || '—' } ) ];
			if ( r.color ) {
				nameCell.unshift( el( 'span', { class: 'vsx-chip__dot', style: '--c:' + r.color } ) );
			} else if ( withType && r.type ) {
				nameCell.unshift( el( 'span', { class: 'vsx-chip__dot', style: '--c:' + ( SOURCE_COLORS[ r.type ] || '#a0a8b5' ) } ) );
				nameCell.push( el( 'div', { class: 'vsx-cell-sub', text: T[ 'src_' + r.type ] || r.type } ) );
			}
			return el( 'tr', null, [
				el( 'td', { class: 'vsx-cell' }, nameCell ),
				el( 'td', { class: 'vsx-num', text: num( r.visits ) } ),
				el( 'td', { class: 'vsx-num', text: num( r.visitors ) } ),
				el( 'td', { class: 'vsx-num', text: pct( r.bounce ) } ),
				el( 'td', { class: 'vsx-num', text: nf2.format( r.depth ) } ),
				el( 'td', { class: 'vsx-num', text: dur( r.duration ) } )
			] );
		} ) );
		return el( 'div', { class: 'vsx-table-wrap' }, el( 'table', { class: 'vsx-table' }, [ el( 'thead', null, head ), tbody ] ) );
	}

	function pagesTable( rows ) {
		if ( ! rows.length ) {
			return el( 'div', { class: 'vsx-empty-sm', text: T.noData } );
		}
		var max = rows[ 0 ].views || 1;
		var home = cfg.home.replace( /\/$/, '' );
		return el( 'div', { class: 'vsx-table-wrap' }, el( 'table', { class: 'vsx-table' }, [
			el( 'thead', null, el( 'tr', null, [
				el( 'th', { text: T.page } ),
				el( 'th', { class: 'vsx-num', text: T.views } ),
				el( 'th', { class: 'vsx-num', text: T.visits } )
			] ) ),
			el( 'tbody', null, rows.map( function ( r ) {
				var path = decodePath( r.name );
				return el( 'tr', null, [
					el( 'td', { class: 'vsx-cell' }, [
						el( 'div', { class: 'vsx-cell-name', text: r.title || path } ),
						el( 'a', { class: 'vsx-cell-sub', href: home + r.name, target: '_blank', rel: 'noopener noreferrer', text: path } ),
						el( 'div', { class: 'vsx-mini-bar', style: 'width:' + ( r.views / max * 100 ).toFixed( 1 ) + '%' } )
					] ),
					el( 'td', { class: 'vsx-num', text: num( r.views ) } ),
					el( 'td', { class: 'vsx-num', text: num( r.visits ) } )
				] );
			} ) )
		] ) );
	}

	buildShell();
	load();
})();
