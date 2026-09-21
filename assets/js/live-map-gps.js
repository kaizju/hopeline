/* assets/js/live-map-gps.js
 * Include on app/manager/live-map.php AFTER its main <script> block.
 * Polls api/unit-locations.php and moves each unit marker to its real
 * phone-GPS position. Units with no fresh GPS keep the old simulated route.
 * Relies on globals defined by live-map.php: map, markers, destMarkers,
 * unitsData, activeRoutes, routeLines, unitIcon, destIcon, setupUnitRoute,
 * renderList, elapsedSince.
 */
(function () {
    'use strict';

    var API = window.LIVEMAP_GPS_API;
    var POLL_MS = 4000;
    var FRESH_SECONDS = 30; // older than this = phone isn't reporting

    function isFresh(u) { return u.gps_age !== null && u.gps_age !== undefined && Number(u.gps_age) <= FRESH_SECONDS; }

    function popupHtml(u) {
        var gps = isFresh(u)
            ? '<span style="color:#3f7a5c">● GPS live (' + u.gps_age + 's ago)</span>'
            : '<span style="color:#b02029">● GPS offline</span>';
        return '<div class="popup-title">' + u.unit_name + '</div>' +
               '<div class="popup-row">Status: ' + u.status + '</div>' +
               '<div class="popup-row">Driver: ' + (u.driver_name || '—') + '</div>' +
               '<div class="popup-row">Plate: ' + (u.plate_no || '—') + '</div>' +
               (u.clip_ref ? '<div class="popup-row">Incident: ' + u.clip_ref + ' (' + (u.barangay || '') + ')</div>' : '') +
               (u.gps_speed != null ? '<div class="popup-row">Speed: ' + Math.round(u.gps_speed * 3.6) + ' km/h</div>' : '') +
               '<div class="popup-row">' + gps + '</div>';
    }

    function applyUnit(u) {
        var existing = null;
        for (var i = 0; i < unitsData.length; i++) if (String(unitsData[i].id) === String(u.id)) { existing = unitsData[i]; break; }
        if (!existing) { unitsData.push(u); existing = u; } else { Object.assign(existing, u); }

        var fresh = isFresh(u);
        var moving = u.dispatch_status === 'en_route' || u.dispatch_status === 'returning';

        // Real GPS replaces the simulation for this unit
        if (fresh) { delete activeRoutes[u.id]; }
        // Simulation fallback for newly dispatched units without GPS
        else if (moving && !activeRoutes[u.id]) { setupUnitRoute(existing); }

        // Dispatch finished -> remove its route line
        if (!moving) {
            delete activeRoutes[u.id];
            if (routeLines[u.id]) { map.removeLayer(routeLines[u.id]); delete routeLines[u.id]; }
        }

        if (u.current_lat != null && u.current_lng != null && (fresh || !moving)) {
            var ll = [parseFloat(u.current_lat), parseFloat(u.current_lng)];
            if (markers[u.id]) { markers[u.id].setLatLng(ll); markers[u.id].setIcon(unitIcon(u)); }
            else { markers[u.id] = L.marker(ll, { icon: unitIcon(u) }).addTo(map); }
        } else if (markers[u.id]) {
            markers[u.id].setIcon(unitIcon(u));
        }
        if (markers[u.id]) markers[u.id].bindPopup(popupHtml(u));

        // Incident pins
        if (u.dest_lat && u.dest_lng) {
            var d = [parseFloat(u.dest_lat), parseFloat(u.dest_lng)];
            if (destMarkers[u.id]) destMarkers[u.id].setLatLng(d);
            else destMarkers[u.id] = L.marker(d, { icon: destIcon(u.severity) }).addTo(map);
        } else if (destMarkers[u.id]) {
            map.removeLayer(destMarkers[u.id]); delete destMarkers[u.id];
        }
    }

    function poll() {
        fetch(API, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) return;
                data.units.forEach(applyUnit);
                renderList();
                var t = document.getElementById('lastUpdated');
                if (t) t.textContent = 'Updated ' + new Date().toLocaleTimeString();
            })
            .catch(function () {});
    }

    poll();
    setInterval(poll, POLL_MS);
})();