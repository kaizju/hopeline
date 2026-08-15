<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

// Guard: only logged-in managers (dispatchers) can view the live map

/**
 * Pulls current PTV units + their active incident (if any) from the DB.
 * Falls back to demo data if the tables don't exist yet, so this page
 * still renders for defense/demo purposes.
 *
 * Suggested tables (adjust to match your actual schema):
 *
 * CREATE TABLE ptv_units (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     unit_name VARCHAR(100) NOT NULL,
 *     plate_no VARCHAR(20) NULL,
 *     driver_name VARCHAR(150) NULL,
 *     status ENUM('Available','En Route','On Site','Returning') DEFAULT 'Available',
 *     current_lat DECIMAL(10,7) NULL,
 *     current_lng DECIMAL(10,7) NULL,
 *     updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * dispatch table links ptv_units.id -> clip_reports.id with departed_at,
 * used here to compute "en route" elapsed time and destination pin.
 */

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
    // Demo fallback so the page still renders before tables are set up
    $units = [
        ['id' => 1, 'unit_name' => 'PTV Alpha',   'plate_no' => 'LGU-101', 'driver_name' => 'R. Santos', 'status' => 'En Route', 'current_lat' => 8.3745, 'current_lng' => 124.8670, 'departed_at' => date('Y-m-d H:i:s', strtotime('-6 minutes')), 'clip_ref' => 'CLIP-20260815-A1B2C', 'barangay' => 'Maluko', 'severity' => 'Critical', 'dest_lat' => 8.4310, 'dest_lng' => 124.8420],
        ['id' => 2, 'unit_name' => 'PTV Bravo',   'plate_no' => 'LGU-102', 'driver_name' => 'J. Reyes',  'status' => 'Available', 'current_lat' => 8.3736, 'current_lng' => 124.8694, 'departed_at' => null, 'clip_ref' => null, 'barangay' => null, 'severity' => null, 'dest_lat' => null, 'dest_lng' => null],
        ['id' => 3, 'unit_name' => 'PTV Charlie', 'plate_no' => 'LGU-103', 'driver_name' => 'M. Cruz',   'status' => 'On Site', 'current_lat' => 8.3980, 'current_lng' => 124.8550, 'departed_at' => date('Y-m-d H:i:s', strtotime('-14 minutes')), 'clip_ref' => 'CLIP-20260815-D4E5F', 'barangay' => 'Damilag', 'severity' => 'High', 'dest_lat' => 8.3980, 'dest_lng' => 124.8550],
        ['id' => 4, 'unit_name' => 'PTV Delta',   'plate_no' => 'LGU-104', 'driver_name' => 'A. Lim',    'status' => 'Returning', 'current_lat' => 8.3600, 'current_lng' => 124.8800, 'departed_at' => null, 'clip_ref' => null, 'barangay' => null, 'severity' => null, 'dest_lat' => null, 'dest_lng' => null],
        ['id' => 5, 'unit_name' => 'PTV Echo',    'plate_no' => 'LGU-105', 'driver_name' => 'K. Torres', 'status' => 'Available', 'current_lat' => 8.3690, 'current_lng' => 124.8610, 'departed_at' => null, 'clip_ref' => null, 'barangay' => null, 'severity' => null, 'dest_lat' => null, 'dest_lng' => null],
    ];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Unit Map — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            --burnt-umber: #6d120b;
            --redwood: #b02029;
            --macadamia: #fbf0d8;
            --cool-blue: #113047;
            --light-grayish: #739ab9;
            --available: #3f7a5c;
            --enroute: #d9752b;
            --onsite: #b02029;
            --returning: #739ab9;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { height: 100%; }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0c2334;
            display: flex;
        }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ===== TOP BAR ===== */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(115, 154, 185, 0.15);
            flex-shrink: 0;
        }

        .topbar h1 { font-size: 18px; color: var(--macadamia); margin-bottom: 3px; }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--light-grayish);
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #3f7a5c;
            box-shadow: 0 0 0 0 rgba(63,122,92, 0.5);
            animation: pulse 1.8s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(63,122,92, 0.5); }
            70% { box-shadow: 0 0 0 6px rgba(63,122,92, 0); }
            100% { box-shadow: 0 0 0 0 rgba(63,122,92, 0); }
        }

        .topbar-right { display: flex; align-items: center; gap: 14px; }

        /* ===== CONTENT ===== */
        .content { flex: 1; display: flex; overflow: hidden; }

        /* ===== UNITS PANEL ===== */
        .units-panel {
            width: 320px;
            flex-shrink: 0;
            border-right: 1px solid rgba(115, 154, 185, 0.15);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .panel-search { padding: 14px; border-bottom: 1px solid rgba(115, 154, 185, 0.12); }

        .search-input-wrap { position: relative; margin-bottom: 10px; }

        .search-input-wrap svg {
            position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
            width: 14px; height: 14px; color: var(--light-grayish);
        }

        .search-input-wrap input {
            width: 100%;
            background: rgba(251, 240, 216, 0.06);
            border: 1px solid rgba(115, 154, 185, 0.25);
            border-radius: 7px;
            padding: 8px 10px 8px 30px;
            color: var(--macadamia);
            font-size: 12.5px;
            outline: none;
        }

        .search-input-wrap input:focus { border-color: var(--redwood); }

        .filter-chips { display: flex; gap: 6px; flex-wrap: wrap; }

        .chip {
            font-size: 10.5px;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 20px;
            border: 1px solid rgba(115, 154, 185, 0.3);
            color: var(--light-grayish);
            cursor: pointer;
            user-select: none;
            transition: all 0.15s;
        }

        .chip.active { background: var(--burnt-umber); border-color: var(--burnt-umber); color: var(--macadamia); }

        .units-list { flex: 1; overflow-y: auto; padding: 10px; }

        .unit-card {
            display: flex;
            gap: 10px;
            padding: 11px;
            border-radius: 8px;
            border: 1px solid rgba(115, 154, 185, 0.16);
            margin-bottom: 8px;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
        }

        .unit-card:hover { border-color: var(--light-grayish); background: rgba(251,240,216,0.03); }
        .unit-card.focused { border-color: var(--redwood); background: rgba(176,32,41,0.08); }

        .unit-icon {
            width: 34px; height: 34px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .unit-icon svg { width: 18px; height: 18px; color: var(--macadamia); }

        .unit-info { flex: 1; min-width: 0; }

        .unit-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 2px; }
        .unit-name { font-size: 13px; font-weight: 700; color: var(--macadamia); }

        .status-pill {
            font-size: 9.5px; font-weight: 700; padding: 2px 7px; border-radius: 20px;
            white-space: nowrap; text-transform: uppercase; letter-spacing: 0.3px;
        }

        .unit-sub { font-size: 11px; color: var(--light-grayish); margin-bottom: 4px; }

        .unit-meta {
            display: flex; align-items: center; gap: 5px;
            font-size: 10.5px; color: var(--light-grayish);
        }

        .unit-meta.alert { color: #f0b884; }
        .unit-meta svg { width: 11px; height: 11px; }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--light-grayish); font-size: 12.5px; }

        /* ===== MAP ===== */
        .map-wrap { flex: 1; position: relative; }
        #map { height: 100%; width: 100%; }

        .map-legend {
            position: absolute;
            bottom: 16px;
            left: 16px;
            z-index: 500;
            background: rgba(17, 48, 71, 0.92);
            border: 1px solid rgba(115, 154, 185, 0.25);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 11px;
            color: var(--macadamia);
            display: flex;
            gap: 14px;
        }

        .legend-item { display: flex; align-items: center; gap: 5px; }
        .legend-dot { width: 8px; height: 8px; border-radius: 50%; }

        .last-updated {
            font-size: 10.5px;
            color: var(--light-grayish);
        }

        /* Leaflet popup theming */
        .leaflet-popup-content-wrapper {
            background: var(--cool-blue);
            color: var(--macadamia);
            border-radius: 8px;
        }
        .leaflet-popup-tip { background: var(--cool-blue); }
        .popup-title { font-weight: 700; font-size: 13px; margin-bottom: 4px; }
        .popup-row { font-size: 11.5px; color: var(--light-grayish); margin-bottom: 2px; }
        .popup-btn {
            display: inline-block; margin-top: 8px; background: var(--burnt-umber);
            color: var(--macadamia); font-size: 11px; font-weight: 700;
            padding: 6px 12px; border-radius: 20px; text-decoration: none;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<div class="main">
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

    const markers = {};   // id -> leaflet marker
    const destMarkers = {}; // id -> destination pin (for en route units)

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

            // Destination pin for en-route/on-site units
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

        // Fit map to show all markers on first render
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

        // Click a card -> fly to marker + open its popup
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