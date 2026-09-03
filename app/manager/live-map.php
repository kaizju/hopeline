<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

$units = [];
try {
    $stmt = $pdo->query("
        SELECT u.id, u.unit_name, u.plate_no, u.driver_name, u.status,
               u.current_lat, u.current_lng,
               d.departed_at, c.clip_ref, c.barangay, c.severity,
               c.latitude AS dest_lat, c.longitude AS dest_lng
        FROM ptv_units u
        LEFT JOIN dispatch d ON d.unit_id = u.id AND d.status = 'en_route'
        LEFT JOIN clip_reports c ON c.id = d.clip_report_id
        ORDER BY u.unit_name ASC
    ");
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Unit Map — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/manager.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<div class="main main-fullscreen">
    <div class="topbar">
        <div>
            <h1>Live Unit Map</h1>
            <div class="live-indicator"><span class="live-dot"></span> Live tracking active</div>
        </div>
        <div class="topbar-right">
            <span class="last-updated" id="lastUpdated">Updated just now</span>
        </div>
    </div>

    <div class="content">
        <div class="units-panel">
            <div class="panel-search">
                <div class="search-input-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" id="unitSearch" placeholder="Search unit, driver, plate no...">
                </div>
                <div class="filter-chips" id="filterChips">
                    <div class="chip active" data-status="all">All</div>
                    <div class="chip" data-status="Available">Available</div>
                    <div class="chip" data-status="En Route">En Route</div>
                    <div class="chip" data-status="On Site">On Site</div>
                    <div class="chip" data-status="Returning">Returning</div>
                </div>
            </div>
            <div class="units-list" id="unitsList"></div>
        </div>

        <div class="map-wrap">
            <div id="map"></div>
            <div class="map-legend">
                <div class="legend-item"><span class="legend-dot" style="background:var(--available)"></span> Available</div>
                <div class="legend-item"><span class="legend-dot" style="background:var(--enroute)"></span> En Route</div>
                <div class="legend-item"><span class="legend-dot" style="background:var(--onsite)"></span> On Site</div>
                <div class="legend-item"><span class="legend-dot" style="background:var(--returning)"></span> Returning</div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Units data rendered server-side (PHP -> JSON). In production, replace the
    // setInterval() simulation below with a Supabase Realtime subscription that
    // pushes actual GPS updates instead of polling.
    const unitsData = <?php echo json_encode($units); ?>;

    const statusColors = {
        'Available': '#3f7a5c',
        'En Route': '#d9752b',
        'On Site': '#b02029',
        'Returning': '#739ab9'
    };

    const map = L.map('map', { zoomControl: true }).setView([8.3736, 124.8694], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    const markers = {};
    const destMarkers = {};

    function unitIcon(unit) {
        const color = statusColors[unit.status] || '#739ab9';
        return L.divIcon({
            className: '',
            html: `<div style="
                width:26px;height:26px;border-radius:50%;
                background:${color};border:2.5px solid #fbf0d8;
                box-shadow:0 2px 6px rgba(0,0,0,0.4);
                display:flex;align-items:center;justify-content:center;">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="#fbf0d8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/>
                    <path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/>
                    <circle cx="6.5" cy="18.5" r="1.8"/><circle cx="17.5" cy="18.5" r="1.8"/>
                </svg>
            </div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        });
    }

    function destIcon(severity) {
        const sevColors = { Critical: '#b02029', High: '#d9752b', Moderate: '#d4ab2b', Low: '#3f7a5c' };
        const color = sevColors[severity] || '#b02029';
        return L.divIcon({
            className: '',
            html: `<div style="
                width:16px;height:16px;border-radius:50% 50% 50% 0;
                background:${color};transform:rotate(-45deg);
                border:2px solid #fbf0d8;"></div>`,
            iconSize: [16, 16],
            iconAnchor: [8, 16]
        });
    }

    function elapsedSince(dateStr) {
        if (!dateStr) return null;
        const start = new Date(dateStr.replace(' ', 'T'));
        const diffSec = Math.max(0, Math.floor((Date.now() - start.getTime()) / 1000));
        const m = Math.floor(diffSec / 60);
        const s = diffSec % 60;
        return m + 'm ' + String(s).padStart(2, '0') + 's';
    }

    function renderMarkers() {
        unitsData.forEach(u => {
            if (u.current_lat == null || u.current_lng == null) return;
            const latlng = [parseFloat(u.current_lat), parseFloat(u.current_lng)];

            if (markers[u.id]) {
                markers[u.id].setLatLng(latlng);
                markers[u.id].setIcon(unitIcon(u));
            } else {
                markers[u.id] = L.marker(latlng, { icon: unitIcon(u) }).addTo(map);
            }

            const popupHtml = `
                <div class="popup-title">${u.unit_name} <span style="color:${statusColors[u.status]}">● ${u.status}</span></div>
                <div class="popup-row">Driver: ${u.driver_name || '—'}</div>
                <div class="popup-row">Plate: ${u.plate_no || '—'}</div>
                ${u.clip_ref ? `<div class="popup-row">Incident: ${u.clip_ref} (${u.barangay || ''})</div>` : ''}
                ${u.departed_at ? `<div class="popup-row">En route: ${elapsedSince(u.departed_at)}</div>` : ''}
                <a class="popup-btn" href="javascript:void(0)">View Incident</a>
            `;
            markers[u.id].bindPopup(popupHtml);

            if (u.dest_lat && u.dest_lng) {
                const destLatLng = [parseFloat(u.dest_lat), parseFloat(u.dest_lng)];
                if (destMarkers[u.id]) {
                    destMarkers[u.id].setLatLng(destLatLng);
                } else {
                    destMarkers[u.id] = L.marker(destLatLng, { icon: destIcon(u.severity) }).addTo(map);
                }
                destMarkers[u.id].bindPopup(`<div class="popup-title">Incident Site</div><div class="popup-row">${u.barangay || ''} — ${u.severity || ''}</div>`);
            }
        });

        const allLatLngs = Object.values(markers).map(m => m.getLatLng());
        if (allLatLngs.length) map.fitBounds(L.latLngBounds(allLatLngs).pad(0.25));
    }

    renderMarkers();

    // ---------- Units list panel ----------
    const unitsListEl = document.getElementById('unitsList');
    let activeFilter = 'all';
    let searchTerm = '';
    let focusedUnitId = null;

    function statusIconBg(status) {
        return statusColors[status] || '#739ab9';
    }

    function renderList() {
        const filtered = unitsData.filter(u => {
            const matchesFilter = activeFilter === 'all' || u.status === activeFilter;
            const haystack = (u.unit_name + ' ' + (u.driver_name||'') + ' ' + (u.plate_no||'')).toLowerCase();
            const matchesSearch = haystack.includes(searchTerm.toLowerCase());
            return matchesFilter && matchesSearch;
        });

        if (!filtered.length) {
            unitsListEl.innerHTML = '<div class="empty-state">No units match your filters.</div>';
            return;
        }

        unitsListEl.innerHTML = filtered.map(u => {
            const alert = u.departed_at ? `<div class="unit-meta alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> En route ${elapsedSince(u.departed_at)}</div>` : '';
            const dest = u.barangay ? `<div class="unit-meta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 21s-7-4.35-7-10a7 7 0 0 1 14 0c0 5.65-7 10-7 10z"/></svg> ${u.barangay}</div>` : '';
            return `
            <div class="unit-card ${focusedUnitId === u.id ? 'focused' : ''}" data-id="${u.id}">
                <div class="unit-icon" style="background:${statusIconBg(u.status)}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="unit-info">
                    <div class="unit-top">
                        <span class="unit-name">${u.unit_name}</span>
                        <span class="status-pill" style="background:${statusIconBg(u.status)}22; color:${statusIconBg(u.status)}">${u.status}</span>
                    </div>
                    <div class="unit-sub">${u.driver_name || '—'} · ${u.plate_no || '—'}</div>
                    ${alert}
                    ${dest}
                </div>
            </div>`;
        }).join('');

        unitsListEl.querySelectorAll('.unit-card').forEach(card => {
            card.addEventListener('click', () => {
                const id = card.dataset.id;
                focusedUnitId = id;
                renderList();
                const m = markers[id];
                if (m) {
                    map.flyTo(m.getLatLng(), 16, { duration: 0.6 });
                    m.openPopup();
                }
            });
        });
    }

    document.getElementById('unitSearch').addEventListener('input', (e) => {
        searchTerm = e.target.value;
        renderList();
    });

    document.getElementById('filterChips').addEventListener('click', (e) => {
        if (!e.target.classList.contains('chip')) return;
        document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
        e.target.classList.add('active');
        activeFilter = e.target.dataset.status;
        renderList();
    });

    renderList();

    // ---------- Live refresh simulation ----------
    // Replace this block with a real Supabase Realtime channel subscription, e.g.:
    //
    // const channel = supabase.channel('ptv-locations')
    //   .on('postgres_changes', { event: 'UPDATE', schema: 'public', table: 'ptv_units' },
    //       payload => updateUnitFromPayload(payload.new))
    //   .subscribe();
    //
    // For now this just refreshes elapsed-time labels and the "Updated" timestamp
    // every few seconds so the UI feels alive during demos.
    setInterval(() => {
        renderList();
        unitsData.forEach(u => {
            if (markers[u.id] && u.departed_at) {
                markers[u.id].setPopupContent(markers[u.id].getPopup().getContent());
            }
        });
        document.getElementById('lastUpdated').textContent = 'Updated just now';
    }, 5000);
</script>

</body>
</html>