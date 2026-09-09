/**
 * ACS Points picker, classic checkout.
 *
 * Lazy-loads the vendored Leaflet on the first click, fetches the point list
 * from the store's REST route (cached a day by the browser), and hands the
 * chosen id to the server. The server re-renders the summary on
 * update_checkout, so this file never writes point details into the page.
 */
/* global wcAcsPoints, jQuery, L */
( function ( $ ) {
    'use strict';

    var cfg = window.wcAcsPoints || {};
    if ( ! cfg.restUrl ) {
        return;
    }

    var GREECE = { center: [ 38.5, 23.8 ], zoom: 7 };
    var LIST_CAP = 60;

    var state = {
        assets: null,
        points: null,
        overlay: null,
        map: null,
        cluster: null,
        markers: {},
        icons: null,
        filter: 'all',
        search: '',
        userMarker: null
    };

    // ─── Asset loading ────────────────────────────────────────────

    function loadStyle( href ) {
        return new Promise( function ( resolve ) {
            if ( document.querySelector( 'link[href="' + href + '"]' ) ) {
                resolve();
                return;
            }
            var link = document.createElement( 'link' );
            link.rel = 'stylesheet';
            link.href = href;
            link.onload = resolve;
            link.onerror = resolve;
            document.head.appendChild( link );
        } );
    }

    function loadScript( src ) {
        return new Promise( function ( resolve, reject ) {
            if ( document.querySelector( 'script[src="' + src + '"]' ) ) {
                resolve();
                return;
            }
            var script = document.createElement( 'script' );
            script.src = src;
            script.async = true;
            script.onload = resolve;
            script.onerror = function () {
                reject( new Error( 'Failed to load ' + src ) );
            };
            document.head.appendChild( script );
        } );
    }

    function loadAssets() {
        if ( ! state.assets ) {
            state.assets = Promise.all( [
                loadStyle( cfg.assets.leafletCss ),
                loadStyle( cfg.assets.clusterCss ),
                loadStyle( cfg.assets.clusterDefaultCss )
            ] )
                .then( function () { return loadScript( cfg.assets.leafletJs ); } )
                .then( function () { return loadScript( cfg.assets.clusterJs ); } );
        }
        return state.assets;
    }

    function loadPoints() {
        if ( state.points ) {
            return Promise.resolve( state.points );
        }
        return fetch( cfg.restUrl, { credentials: 'omit' } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    throw new Error( 'HTTP ' + response.status );
                }
                return response.json();
            } )
            .then( function ( json ) {
                // Column order matches WC_ACS_Points_Feed::payload().
                state.points = ( json.points || [] ).map( function ( row ) {
                    return {
                        id: String( row[ 0 ] ),
                        type: row[ 1 ],
                        name: row[ 2 ] || '',
                        street: row[ 3 ] || '',
                        city: row[ 4 ] || '',
                        zip: String( row[ 5 ] || '' ),
                        lat: parseFloat( row[ 6 ] ),
                        lon: parseFloat( row[ 7 ] ),
                        station: row[ 8 ],
                        branch: row[ 9 ],
                        cod: !! row[ 10 ],
                        h24: !! row[ 11 ],
                        hours: row[ 12 ] || '',
                        sat: row[ 13 ] || ''
                    };
                } ).filter( function ( p ) {
                    return ! isNaN( p.lat ) && ! isNaN( p.lon ) && ( cfg.pointTypes !== 'lockers' || p.type === 'locker' );
                } );
                return state.points;
            } );
    }

    // ─── Helpers ──────────────────────────────────────────────────

    function escapeHtml( text ) {
        var div = document.createElement( 'div' );
        div.appendChild( document.createTextNode( text == null ? '' : String( text ) ) );
        return div.innerHTML;
    }

    function fold( text ) {
        return String( text || '' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase();
    }

    function distanceKm( lat1, lon1, lat2, lon2 ) {
        var toRad = Math.PI / 180;
        var dLat = ( lat2 - lat1 ) * toRad;
        var dLon = ( lon2 - lon1 ) * toRad;
        var a = Math.sin( dLat / 2 ) * Math.sin( dLat / 2 ) +
            Math.cos( lat1 * toRad ) * Math.cos( lat2 * toRad ) * Math.sin( dLon / 2 ) * Math.sin( dLon / 2 );
        return 6371 * 2 * Math.atan2( Math.sqrt( a ), Math.sqrt( 1 - a ) );
    }

    function median( values ) {
        var sorted = values.slice().sort( function ( a, b ) { return a - b; } );
        var mid = Math.floor( sorted.length / 2 );
        return sorted.length % 2 === 0 ? ( sorted[ mid - 1 ] + sorted[ mid ] ) / 2 : sorted[ mid ];
    }

    function checkoutPostcode() {
        var field = $( '#ship-to-different-address-checkbox' ).is( ':checked' ) ? $( '#shipping_postcode' ) : $( '#billing_postcode' );
        return $.trim( String( field.val() || '' ) );
    }

    /** Same rule as WC_ACS_Points_Feed::centre_for_postcode(). */
    function centreForPostcode( zip, points ) {
        var digits = String( zip || '' ).replace( /\D+/g, '' );
        var rules = [ [ 3, 13 ], [ 2, 10 ] ];
        for ( var r = 0; r < rules.length; r++ ) {
            var len = rules[ r ][ 0 ];
            if ( digits.length < len ) {
                continue;
            }
            var prefix = digits.slice( 0, len );
            var lats = [], lons = [];
            points.forEach( function ( p ) {
                if ( p.zip.indexOf( prefix ) === 0 ) {
                    lats.push( p.lat );
                    lons.push( p.lon );
                }
            } );
            if ( lats.length ) {
                return [ median( lats ), median( lons ), rules[ r ][ 1 ] ];
            }
        }
        return null;
    }

    function hoursHtml( p ) {
        if ( p.h24 ) {
            return '<span class="wc-acs-points-badge wc-acs-points-badge--24">' + escapeHtml( cfg.i18n.open24 ) + '</span>';
        }
        return '<span class="wc-acs-points-hours">' + escapeHtml( cfg.i18n.weekdays ) + ': ' + escapeHtml( p.hours ) +
            ( p.sat ? ' · ' + escapeHtml( cfg.i18n.saturday ) + ': ' + escapeHtml( p.sat ) : '' ) + '</span>';
    }

    function codHtml( p ) {
        return p.cod
            ? '<span class="wc-acs-points-badge wc-acs-points-badge--cod">' + escapeHtml( cfg.i18n.cod ) + '</span>'
            : '<span class="wc-acs-points-badge wc-acs-points-badge--nocod">' + escapeHtml( cfg.i18n.noCod ) + '</span>';
    }

    // ─── Overlay ──────────────────────────────────────────────────

    function buildOverlay() {
        var chips = cfg.pointTypes === 'lockers' ? '' :
            '<div class="wc-acs-points-chips" role="group">' +
                '<button type="button" class="wc-acs-points-chip is-active" data-filter="all">' + escapeHtml( cfg.i18n.all ) + '</button>' +
                '<button type="button" class="wc-acs-points-chip" data-filter="locker">' + escapeHtml( cfg.i18n.lockers ) + '</button>' +
                '<button type="button" class="wc-acs-points-chip" data-filter="store">' + escapeHtml( cfg.i18n.stores ) + '</button>' +
            '</div>';

        var html =
            '<div class="wc-acs-points-overlay" role="dialog" aria-modal="true" aria-label="' + escapeHtml( cfg.i18n.title ) + '">' +
                '<div class="wc-acs-points-modal">' +
                    '<div class="wc-acs-points-header">' +
                        '<span class="wc-acs-points-title">' + escapeHtml( cfg.i18n.title ) + '</span>' +
                        '<button type="button" class="wc-acs-points-close" aria-label="' + escapeHtml( cfg.i18n.close ) + '">&times;</button>' +
                    '</div>' +
                    '<div class="wc-acs-points-body">' +
                        '<aside class="wc-acs-points-sidebar">' +
                            '<button type="button" class="wc-acs-points-sheet-toggle" aria-label="' + escapeHtml( cfg.i18n.close ) + '"><span></span></button>' +
                            '<div class="wc-acs-points-tools">' +
                                '<input type="search" class="wc-acs-points-search" placeholder="' + escapeHtml( cfg.i18n.search ) + '" autocomplete="off" />' +
                                '<button type="button" class="wc-acs-points-locate">' + escapeHtml( cfg.i18n.myLocation ) + '</button>' +
                            '</div>' +
                            chips +
                            '<div class="wc-acs-points-list" aria-live="polite"><p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.loading ) + '</p></div>' +
                        '</aside>' +
                        '<div class="wc-acs-points-map"></div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        state.overlay = $( html ).appendTo( 'body' );
        $( 'body' ).addClass( 'wc-acs-points-noscroll' );

        state.overlay.on( 'click', '.wc-acs-points-close', closeOverlay );
        state.overlay.on( 'click', function ( e ) {
            if ( e.target === state.overlay[ 0 ] ) {
                closeOverlay();
            }
        } );
        state.overlay.on( 'click', '.wc-acs-points-chip', function () {
            state.filter = $( this ).data( 'filter' );
            state.overlay.find( '.wc-acs-points-chip' ).removeClass( 'is-active' );
            $( this ).addClass( 'is-active' );
            renderMarkers();
            renderList();
        } );
        var searchTimer = null;
        state.overlay.on( 'input', '.wc-acs-points-search', function () {
            var value = this.value;
            clearTimeout( searchTimer );
            searchTimer = setTimeout( function () {
                state.search = value;
                renderMarkers();
                renderList();
            }, 250 );
        } );
        state.overlay.on( 'click', '.wc-acs-points-locate', locate );
        state.overlay.on( 'click', '.wc-acs-points-sheet-toggle', function () {
            state.overlay.find( '.wc-acs-points-sidebar' ).toggleClass( 'is-collapsed' );
        } );
        state.overlay.on( 'click', '.wc-acs-points-item', function () {
            var id = $( this ).data( 'id' );
            var marker = state.markers[ id ];
            if ( marker ) {
                state.map.setView( marker.getLatLng(), Math.max( state.map.getZoom(), 15 ) );
                marker.openPopup();
            }
            if ( window.innerWidth < 768 ) {
                state.overlay.find( '.wc-acs-points-sidebar' ).addClass( 'is-collapsed' );
            }
        } );
        state.overlay.on( 'click', '.wc-acs-points-select', function () {
            selectPoint( String( $( this ).data( 'id' ) ) );
        } );
    }

    function closeOverlay() {
        if ( state.overlay ) {
            state.overlay.remove();
            state.overlay = null;
        }
        if ( state.map ) {
            state.map.remove();
            state.map = null;
            state.cluster = null;
            state.markers = {};
            state.userMarker = null;
        }
        $( 'body' ).removeClass( 'wc-acs-points-noscroll' );
    }

    // ─── Map ──────────────────────────────────────────────────────

    function icons() {
        if ( ! state.icons ) {
            var make = function ( url ) {
                return L.icon( { iconUrl: url, iconSize: [ 32, 40 ], iconAnchor: [ 16, 40 ], popupAnchor: [ 0, -36 ] } );
            };
            state.icons = {
                locker: make( cfg.icons.locker ),
                lockerCod: make( cfg.icons.lockerCod ),
                store: make( cfg.icons.store ),
                user: L.icon( { iconUrl: cfg.icons.marker, shadowUrl: cfg.icons.markerShadow, iconSize: [ 25, 41 ], iconAnchor: [ 12, 41 ], shadowSize: [ 41, 41 ] } )
            };
        }
        return state.icons;
    }

    function iconFor( p ) {
        if ( p.type === 'store' ) {
            return icons().store;
        }
        return p.cod ? icons().lockerCod : icons().locker;
    }

    function popupHtml( p ) {
        return '<div class="wc-acs-points-popup">' +
            '<strong>' + escapeHtml( p.name ) + '</strong>' +
            '<span>' + escapeHtml( p.street ) + ', ' + escapeHtml( p.zip ) + ' ' + escapeHtml( p.city ) + '</span>' +
            '<span class="wc-acs-points-popup-meta">' + hoursHtml( p ) + ' ' + codHtml( p ) + '</span>' +
            '<button type="button" class="wc-acs-points-select" data-id="' + escapeHtml( p.id ) + '">' + escapeHtml( cfg.i18n.select ) + '</button>' +
        '</div>';
    }

    function initMap() {
        var el = state.overlay.find( '.wc-acs-points-map' )[ 0 ];
        state.map = L.map( el, { zoomControl: true, minZoom: 6, maxZoom: 18 } );
        L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
        } ).addTo( state.map );

        state.cluster = L.markerClusterGroup( { maxClusterRadius: 60, disableClusteringAtZoom: 15, showCoverageOnHover: false } );
        state.map.addLayer( state.cluster );

        var centre = cfg.postcodeCentre || centreForPostcode( checkoutPostcode(), state.points );
        if ( centre ) {
            state.map.setView( [ centre[ 0 ], centre[ 1 ] ], centre[ 2 ] );
        } else {
            state.map.setView( GREECE.center, GREECE.zoom );
        }

        var moveTimer = null;
        state.map.on( 'moveend', function () {
            clearTimeout( moveTimer );
            moveTimer = setTimeout( renderList, 250 );
        } );

        renderMarkers();
        renderList();
        setTimeout( function () { state.map.invalidateSize(); }, 150 );
    }

    function visiblePoints() {
        var needle = fold( state.search );
        return state.points.filter( function ( p ) {
            if ( state.filter !== 'all' && p.type !== state.filter ) {
                return false;
            }
            if ( ! needle ) {
                return true;
            }
            return fold( p.name + ' ' + p.street + ' ' + p.city + ' ' + p.zip ).indexOf( needle ) !== -1;
        } );
    }

    function renderMarkers() {
        state.cluster.clearLayers();
        state.markers = {};
        visiblePoints().forEach( function ( p ) {
            var marker = L.marker( [ p.lat, p.lon ], { icon: iconFor( p ), title: p.name } );
            marker.bindPopup( popupHtml( p ), { maxWidth: 300 } );
            state.markers[ p.id ] = marker;
            state.cluster.addLayer( marker );
        } );
    }

    function renderList() {
        if ( ! state.map || ! state.overlay ) {
            return;
        }
        var centre = state.map.getCenter();
        var list = state.overlay.find( '.wc-acs-points-list' );
        var points = visiblePoints().map( function ( p ) {
            return { p: p, d: distanceKm( centre.lat, centre.lng, p.lat, p.lon ) };
        } ).sort( function ( a, b ) { return a.d - b.d; } );

        if ( ! points.length ) {
            list.html( '<p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.moreHint ) + '</p>' );
            return;
        }

        var html = points.slice( 0, LIST_CAP ).map( function ( item ) {
            var p = item.p;
            var km = item.d < 10 ? item.d.toFixed( 1 ) : Math.round( item.d );
            return '<button type="button" class="wc-acs-points-item wc-acs-points-item--' + escapeHtml( p.type ) + '" data-id="' + escapeHtml( p.id ) + '">' +
                '<span class="wc-acs-points-item-name">' + escapeHtml( p.name ) + '</span>' +
                '<span class="wc-acs-points-item-address">' + escapeHtml( p.street ) + ', ' + escapeHtml( p.zip ) + ' ' + escapeHtml( p.city ) + '</span>' +
                '<span class="wc-acs-points-item-meta">' + hoursHtml( p ) + ' ' + codHtml( p ) + '<span class="wc-acs-points-km">' + km + ' km</span></span>' +
            '</button>';
        } ).join( '' );

        if ( points.length > LIST_CAP ) {
            html += '<p class="wc-acs-points-status">' + escapeHtml( cfg.i18n.moreHint ) + '</p>';
        }
        list.html( html );
    }

    function locate() {
        if ( ! navigator.geolocation ) {
            window.alert( cfg.i18n.locateError );
            return;
        }
        navigator.geolocation.getCurrentPosition( function ( pos ) {
            var latlng = [ pos.coords.latitude, pos.coords.longitude ];
            if ( state.userMarker ) {
                state.userMarker.setLatLng( latlng );
            } else {
                state.userMarker = L.marker( latlng, { icon: icons().user } ).addTo( state.map );
            }
            state.map.setView( latlng, 14 );
        }, function () {
            window.alert( cfg.i18n.locateError );
        }, { enableHighAccuracy: false, timeout: 8000, maximumAge: 300000 } );
    }

    // ─── Selection ────────────────────────────────────────────────

    function selectPoint( id ) {
        $.post( cfg.ajaxUrl, { action: 'wc_acs_set_point', nonce: cfg.nonce, point_id: id } )
            .done( function ( res ) {
                if ( ! res || ! res.success ) {
                    window.alert( ( res && res.data && res.data.message ) || cfg.i18n.loadError );
                    return;
                }
                $( '#acs_point_id' ).val( id );
                closeOverlay();
                $( document.body ).trigger( 'update_checkout' );
            } )
            .fail( function () {
                window.alert( cfg.i18n.loadError );
            } );
    }

    function openPicker() {
        if ( state.overlay ) {
            return;
        }
        buildOverlay();
        Promise.all( [ loadAssets(), loadPoints() ] )
            .then( function () {
                if ( state.overlay ) {
                    initMap();
                }
            } )
            .catch( function () {
                if ( state.overlay ) {
                    state.overlay.find( '.wc-acs-points-list' ).html( '<p class="wc-acs-points-status wc-acs-points-status--error">' + escapeHtml( cfg.i18n.loadError ) + '</p>' );
                }
            } );
    }

    // ─── Boot ─────────────────────────────────────────────────────

    $( function () {
        $( document.body ).on( 'click', '.wc-acs-points-open, .wc-acs-points-change', function ( e ) {
            e.preventDefault();
            openPicker();
        } );
        $( document ).on( 'keyup', function ( e ) {
            if ( e.key === 'Escape' && state.overlay ) {
                closeOverlay();
            }
        } );
    } );
}( jQuery ) );
