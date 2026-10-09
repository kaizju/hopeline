(function () {
    'use strict';
    var API = window.LIVEMAP_INCIDENT_API, scope = 'active', pins = {};
    var layer = L.layerGroup().addTo(map);   // `map` comes from live-map.php
    var ICONS = { 'Fire': '🔥', 'Medical Emergency': '🚑', 'Vehicular Accident': '🚗',
                  'Flood / Landslide': '🌊', 'Violence / Assault': '⚠️', 'Other': '❓' };
    var SEV = { Critical: '#e5484d', High: '#d9752b', Moderate: '#d4ab2b', Low: '#3f7a5c' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fmt(d) {
        return d ? new Date(d.replace(' ', 'T')).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    }
    function row(k, v) { return v ? '<div class="popup-row"><strong>' + k + ':</strong> ' + esc(v) + '</div>' : ''; }

    function icon(i) {
        var c = SEV[i.severity] || '#888';
        return L.divIcon({
            className: '',
            html: '<div style="width:30px;height:30px;border-radius:50%;background:#fff;border:3px solid ' + c +
                  ';box-shadow:0 2px 6px rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1">' +
                  (ICONS[i.incident_type] || '❓') + '</div>',
            iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -15]
        });
    }
    function popup(i) {
        var st = (i.dispatch_status || i.status).replace('_', ' ');
        return '<div class="popup-title">' + esc(i.incident_type) + ' <span style="color:' + (SEV[i.severity] || '#888') + '">● ' + esc(i.severity) + '</span></div>' +
            row('Ref', i.clip_ref) + row('Status', st) +
            row('Caller', i.caller_name + (i.caller_contact ? ' (' + i.caller_contact + ')' : '')) +
            row('Location', [i.sitio_purok, i.barangay].filter(Boolean).join(', ')) +
            row('Landmark', i.landmark) + row('Needs', i.problem_resources) + row('Notes', i.problem_notes) +
            row('Unit', i.unit_name) + row('Reported', fmt(i.created_at)) +
            (i.departed_at ? row('Departed', fmt(i.departed_at)) : '') +
            (i.arrived_at ? row('Arrived', fmt(i.arrived_at)) : '');
    }

    function sync(list) {
        var seen = {};
        list.forEach(function (i) {
            seen[i.id] = true;
            var ll = [parseFloat(i.latitude), parseFloat(i.longitude)];
            if (pins[i.id]) {
                pins[i.id].setLatLng(ll).setIcon(icon(i)).setPopupContent(popup(i));
            } else {
                pins[i.id] = L.marker(ll, { icon: icon(i), zIndexOffset: 500 }).bindPopup(popup(i), { maxWidth: 280 }).addTo(layer);
            }
        });
        Object.keys(pins).forEach(function (id) {
            if (!seen[id]) { layer.removeLayer(pins[id]); delete pins[id]; }
        });
    }
    function poll() {
        fetch(API + '?scope=' + scope, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.ok) sync(d.incidents); })
            .catch(function () {});
    }

    // scope toggle (top-right of the map)
    var ctl = L.control({ position: 'topright' });
    ctl.onAdd = function () {
        var d = L.DomUtil.create('div');
        d.innerHTML = '<select style="background:var(--cool-blue);color:var(--macadamia);border:1px solid rgba(var(--border-rgb),.4);border-radius:6px;padding:6px 8px;font-size:12px">' +
                      '<option value="active">Active incidents</option><option value="all">Last 30 days</option></select>';
        L.DomEvent.disableClickPropagation(d);
        d.firstChild.addEventListener('change', function () { scope = this.value; poll(); });
        return d;
    };
    ctl.addTo(map);

    poll();
    setInterval(poll, 10000);
})();