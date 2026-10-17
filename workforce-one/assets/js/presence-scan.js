/* Employee app → Presence Verification (templates/app/presence.php): scan the kiosk QR with the
   camera (jsQR) and submit it with the device location, which the server checks against the
   requested work location (as for QR Sign In). Without a location the form is still sent, and the
   server answers with the "location required" message. */
(function () {
    'use strict';
    function init() {
        var box = document.getElementById('wfo-presence-scanner'), input = document.getElementById('wfo-presence-payload'), form = document.getElementById('wfo-presence-form');
        if (!box || !input || !form) return;
        var status = document.getElementById('wfo-presence-location');
        countdown();
        var located = false, locating = false, waiting = false;
        function say(key) { if (status) { status.textContent = status.getAttribute('data-' + key) || ''; status.setAttribute('data-state', key); } }
        function field(id, v) { var el = document.getElementById(id); if (el) el.value = v; }
        function send() { waiting = false; form.submit(); }
        function locate() {
            if (!window.isSecureContext || !navigator.geolocation) { say(window.isSecureContext ? 'failed' : 'insecure'); return; }
            locating = true;
            say('waiting');
            navigator.geolocation.getCurrentPosition(function (p) {
                var c = p.coords;
                field('wfo-presence-lat', c.latitude);
                field('wfo-presence-lng', c.longitude);
                field('wfo-presence-acc', c.accuracy || '');
                field('wfo-presence-ts', p.timestamp || Date.now());
                located = true; locating = false;
                say('ready');
                if (waiting) send();
            }, function (err) {
                locating = false;
                say(err && err.code === 1 ? 'denied' : 'failed');
                if (waiting) send();
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
        }
        locate();
        // The Verify button: wait for a location that is still on its way.
        form.addEventListener('submit', function (e) { if (locating && !located) { e.preventDefault(); waiting = true; } });

        var video = document.createElement('video'), canvas = document.createElement('canvas');
        canvas.width = 640; canvas.height = 480;
        function fail() { box.textContent = box.getAttribute('data-unavailable') || ''; if (box.parentNode) box.parentNode.classList.add('is-off'); }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof jsQR !== 'function') { fail(); return; }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then(function (stream) {
            video.setAttribute('playsinline', '');
            video.autoplay = true;
            video.srcObject = stream;
            box.innerHTML = '';
            box.appendChild(video);
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            function scan() {
                if (video.readyState >= 2) {
                    canvas.width = video.videoWidth || 640; canvas.height = video.videoHeight || 480;
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    var code = jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' });
                    if (code && code.data && code.data.indexOf('wfo1|') === 0) {
                        input.value = code.data;
                        stream.getTracks().forEach(function (t) { t.stop(); });
                        if (locating && !located) waiting = true; // sent as soon as the location arrives
                        else send();
                        return;
                    }
                }
                requestAnimationFrame(scan);
            }
            scan();
        }).catch(fail);
    }
    // The time left: the ring empties and the clock counts down; at zero the page reloads (expired).
    function countdown() {
        var ring = document.querySelector('.wfo-pres-ring'), clock = document.getElementById('wfo-presence-clock'), line = document.getElementById('wfo-presence-countdown');
        if (!ring || !clock) return;
        var total = parseInt(ring.getAttribute('data-total') || '0', 10), circle = parseFloat(ring.getAttribute('data-circle') || '0');
        var started = parseInt(ring.getAttribute('data-left') || '0', 10), end = Date.now() + started * 1000, bar = ring.querySelector('.bar');
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function tick() {
            var left = Math.max(0, Math.round((end - Date.now()) / 1000)), text = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
            clock.textContent = text;
            if (line) line.textContent = (line.getAttribute('data-label') || '%s').replace('%s', text);
            if (bar && total > 0) bar.setAttribute('stroke-dashoffset', String(circle * (1 - Math.min(1, left / total))));
            ring.classList.toggle('is-low', left <= 60);
            if (left === 0) { if (started > 0) window.location.reload(); return; }
            setTimeout(tick, 1000);
        }
        tick();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
