/* assets/js/gps-tracker.js
 * Loaded on every responder page (via responder_sidebar.php).
 *  - watches the phone's GPS (high accuracy) and uploads it to the server
 *  - keeps the screen awake (Wake Lock) so the browser doesn't suspend GPS
 *  - auto-restarts the watch if it dies, and blocks the screen with an
 *    "Turn on GPS" overlay when location is denied / unavailable
 *  - broadcasts every fix as a `hopegps:position` event for the map page
 */
(function () {
    'use strict';

    var CFG = window.HOPEGPS_CONFIG || {};
    var ENDPOINT = CFG.endpoint;
    if (!ENDPOINT) return;

    var MIN_SEND_GAP_MS = 4000;   // don't upload more often than this
    var HEARTBEAT_MS    = 15000;  // upload even when standing still
    var STALE_MS        = 25000;  // no fix for this long -> restart the watch

    var watchId = null;
    var wakeLock = null;
    var last = null;              // last fix {lat,lng,accuracy,heading,speed,ts}
    var lastFixAt = 0;
    var lastSentAt = 0;
    var state = 'starting';       // starting | ok | searching | denied | unavailable | insecure | offline

    /* ---------- UI: status pill + blocking overlay ---------- */
    var pill = document.createElement('div');
    pill.id = 'gpsPill';
    pill.innerHTML = '<span class="gps-dot"></span><span class="gps-text">GPS…</span>';
    var overlay = document.createElement('div');
    overlay.id = 'gpsOverlay';
    overlay.innerHTML =
        '<div class="gps-box">' +
        '<h2 id="gpsOverlayTitle">Location is off</h2>' +
        '<p id="gpsOverlayMsg"></p>' +
        '<button type="button" id="gpsRetry">Try again</button>' +
        '</div>';

    function mountUI() {
        document.body.appendChild(pill);
        document.body.appendChild(overlay);
        document.getElementById('gpsRetry').addEventListener('click', restart);
    }
    if (document.body) mountUI(); else document.addEventListener('DOMContentLoaded', mountUI);

    var LABELS = {
        starting: 'GPS starting…', ok: 'GPS live', searching: 'Searching for GPS…',
        denied: 'GPS blocked', unavailable: 'GPS off', insecure: 'GPS needs HTTPS', offline: 'No connection'
    };
    function setState(s) {
        state = s;
        pill.className = 'gps-' + s;
        pill.querySelector('.gps-text').textContent = LABELS[s] || s;

        var block = (s === 'denied' || s === 'unavailable' || s === 'insecure');
        overlay.classList.toggle('show', block);
        if (block) {
            var t = document.getElementById('gpsOverlayTitle');
            var m = document.getElementById('gpsOverlayMsg');
            if (s === 'denied') {
                t.textContent = 'Allow location for HopeLine';
                m.textContent = 'Your unit can\'t be tracked or routed. Open your browser\'s site settings, set Location to "Allow", then tap Try again.';
            } else if (s === 'insecure') {
                t.textContent = 'Secure connection required';
                m.textContent = 'Phones only share GPS on HTTPS pages. Open HopeLine using its https:// address.';
            } else {
                t.textContent = 'Turn on your phone\'s GPS';
                m.textContent = 'Switch on Location (GPS) in your phone settings, then tap Try again.';
            }
        }
    }

    /* ---------- GPS watch ---------- */
    function startWatch() {
        if (!window.isSecureContext) { setState('insecure'); return; }
        if (!('geolocation' in navigator)) { setState('unavailable'); return; }
        stopWatch();
        watchId = navigator.geolocation.watchPosition(onPosition, onError, {
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 20000
        });
    }
    function stopWatch() {
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    }
    function restart() { setState('searching'); startWatch(); requestWakeLock(); }

    function onPosition(pos) {
        var c = pos.coords;
        last = {
            lat: c.latitude, lng: c.longitude, accuracy: c.accuracy,
            heading: (c.heading !== null && !isNaN(c.heading)) ? c.heading : (last ? last.heading : null),
            speed: c.speed, ts: pos.timestamp
        };
        lastFixAt = Date.now();
        setState(navigator.onLine ? 'ok' : 'offline');
        window.dispatchEvent(new CustomEvent('hopegps:position', { detail: last }));
        maybeSend(false);
    }

    function onError(err) {
        if (err.code === 1) { setState('denied'); return; }          // PERMISSION_DENIED
        if (err.code === 2) { setState('unavailable'); }             // POSITION_UNAVAILABLE (GPS off)
        else                { setState('searching'); }               // TIMEOUT
        // keep trying – the user may switch GPS on at any moment
        setTimeout(startWatch, 3000);
    }

    /* ---------- upload ---------- */
    function maybeSend(isHeartbeat) {
        if (!last) return;
        var now = Date.now();
        if (now - lastSentAt < (isHeartbeat ? HEARTBEAT_MS : MIN_SEND_GAP_MS)) return;
        lastSentAt = now;
        fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lat: last.lat, lng: last.lng, accuracy: last.accuracy,
                heading: last.heading, speed: last.speed
            })
        }).then(function (res) {
            if (res.status === 401) { pill.querySelector('.gps-text').textContent = 'Session expired – log in again'; }
        }).catch(function () { /* offline: next fix will retry */ });
    }

    /* ---------- keep it alive ---------- */
    function requestWakeLock() {
        if (!('wakeLock' in navigator) || wakeLock) return;
        navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
            lock.addEventListener('release', function () { wakeLock = null; });
        }).catch(function () { /* not allowed right now */ });
    }

    // Watchdog: restart if fixes stop arriving, send heartbeats while parked.
    setInterval(function () {
        if (state === 'denied' || state === 'insecure') return;
        var age = Date.now() - lastFixAt;
        if (lastFixAt && age > STALE_MS) { setState('searching'); startWatch(); }
        else if (lastFixAt) { maybeSend(true); }
    }, 5000);

    // Coming back to the tab (unlocked phone, switched apps): re-arm everything.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            requestWakeLock();
            startWatch();
            if (last) maybeSend(false);
        }
    });

    window.addEventListener('online',  function () { if (state === 'offline') setState('ok'); maybeSend(false); });
    window.addEventListener('offline', function () { setState('offline'); });

    // If the user flips the browser's location permission, react immediately.
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(function (p) {
            p.onchange = function () { if (p.state !== 'denied') restart(); else setState('denied'); };
        }).catch(function () {});
    }

    window.HopeGPS = { getLast: function () { return last; }, restart: restart };

    startWatch();
    requestWakeLock();
})();