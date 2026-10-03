/**
 * Sign In / Sign Out page (templates/app/time.php).
 * Loaded only on that page; strings are translated with wp.i18n.
 */
(function () {
    var i18n = (window.wp && window.wp.i18n) ? window.wp.i18n : { __: function (s) { return s; } };
    var __ = i18n.__;

    function byId(id) { return document.getElementById(id); }

    /* ---- Elapsed break timer ------------------------------------------------------------ */
    function initBreakTimer() {
        var el = byId('ews-break-timer');
        if (!el || !el.dataset.start) return;
        var start = parseInt(el.dataset.start, 10);
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function tick() {
            var sec = Math.max(0, Math.floor((Date.now() - start) / 1000));
            el.textContent = pad(Math.floor(sec / 60)) + ':' + pad(sec % 60);
        }
        tick();
        setInterval(tick, 1000);
    }

    /* ---- Location permission gate: Sign In / Out stay disabled until we have a position -- */
    function initLocationGate() {
        var gate = byId('ews-location-gate');
        if (!gate) return;
        var message = byId('ews-location-message');
        var retry = byId('ews-location-retry');
        var spinner = byId('ews-location-spinner');
        var forms = document.querySelectorAll('.ews-time-actions form');
        var ready = false;

        function setForms(enabled) {
            forms.forEach(function (form) { var b = form.querySelector('button'); if (b) b.disabled = !enabled; });
        }
        function fill(selector, value) {
            document.querySelectorAll(selector).forEach(function (i) { i.value = value; });
        }
        function finish(pos) {
            var c = pos.coords;
            fill('input.ews-lat', c.latitude);
            fill('input.ews-lng', c.longitude);
            fill('input.ews-accuracy', c.accuracy || '');
            fill('input.ews-location-timestamp', pos.timestamp || Date.now());
            ready = true;
            setForms(true);
            gate.classList.add('is-ready');
            gate.setAttribute('aria-hidden', 'true');
            setTimeout(function () { if (gate.parentNode) gate.parentNode.removeChild(gate); }, 220);
        }
        function fail(text) {
            ready = false;
            setForms(false);
            spinner.style.display = 'none';
            retry.hidden = false;
            message.textContent = text;
        }
        function requestLocation() {
            retry.hidden = true;
            spinner.style.display = 'block';
            message.textContent = __('Please allow location access when your browser asks. Your location is required to record Sign In / Sign Out.', 'workforce-one');
            if (!window.isSecureContext) { fail(__('Location access requires HTTPS. Please open Workforce One using a secure connection.', 'workforce-one')); return; }
            if (!navigator.geolocation) { fail(__('Location Services are not supported by this browser. Please use a modern browser.', 'workforce-one')); return; }
            navigator.geolocation.getCurrentPosition(finish, function (err) {
                if (err && err.code === 1) fail(__('Location permission was denied. Please allow Location Services for this site, then try again.', 'workforce-one'));
                else if (err && err.code === 2) fail(__('Your location could not be determined. Turn on Location Services/GPS and try again.', 'workforce-one'));
                else if (err && err.code === 3) fail(__('Location request timed out. Turn on Location Services/GPS and try again.', 'workforce-one'));
                else fail(__('We need your location to continue. Please allow Location Services for this site.', 'workforce-one'));
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
        }

        forms.forEach(function (form) {
            form.addEventListener('submit', function (e) { if (!ready) { e.preventDefault(); requestLocation(); } });
        });
        retry.addEventListener('click', requestLocation);
        setForms(false);
        requestLocation();
    }

    /* ---- QR Sign-In: scan the kiosk QR and attach the device's own location ------------- */
    function initQrSignIn() {
        var open = byId('wfo-qr-open'), modal = byId('wfo-qr-modal'), close = byId('wfo-qr-close');
        var video = byId('wfo-qr-video'), status = byId('wfo-qr-status');
        var payload = byId('wfo-qr-payload'), submit = byId('wfo-qr-submit');
        if (!open || !modal) return;
        var stream = null, raf = 0, canvas = document.createElement('canvas'), hasQr = false, hasLoc = false;

        function sync() {
            submit.disabled = !(hasQr && hasLoc);
            if (hasQr && !hasLoc) status.textContent = __('QR detected. Waiting for your location…', 'workforce-one');
            else if (hasQr && hasLoc) status.textContent = __('QR detected. Review and tap Sign In with QR.', 'workforce-one');
        }
        function locate() {
            if (!navigator.geolocation) { status.textContent = __('Location Services are required for QR Sign In.', 'workforce-one'); return; }
            navigator.geolocation.getCurrentPosition(function (p) {
                var c = p.coords;
                byId('wfo-qr-lat').value = c.latitude;
                byId('wfo-qr-lng').value = c.longitude;
                byId('wfo-qr-acc').value = c.accuracy || '';
                byId('wfo-qr-ts').value = p.timestamp || Date.now();
                hasLoc = true;
                sync();
            }, function () {
                hasLoc = false;
                status.textContent = __('Location permission is required for QR Sign In. Please allow Location Services and try again.', 'workforce-one');
                sync();
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        }
        function stop() {
            if (raf) cancelAnimationFrame(raf);
            raf = 0;
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }
        function scan() {
            if (!stream) return;
            if (video.readyState >= 2) {
                canvas.width = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                var d = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var code = typeof jsQR === 'function' ? jsQR(d.data, d.width, d.height, { inversionAttempts: 'dontInvert' }) : null;
                if (code && code.data && code.data.indexOf('wfo1|') === 0) {
                    payload.value = code.data;
                    hasQr = true;
                    stop();
                    modal.classList.add('is-open');
                    sync();
                    return;
                }
            }
            raf = requestAnimationFrame(scan);
        }
        function start() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof jsQR !== 'function') {
                status.textContent = __('Camera QR scanning is unavailable on this browser/device.', 'workforce-one');
                return;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            status.textContent = __('Starting camera…', 'workforce-one');
            hasQr = false;
            payload.value = '';
            submit.disabled = true;
            locate();
            navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false }).then(function (s) {
                stream = s;
                video.srcObject = s;
                status.textContent = __('Point the camera at the workplace QR.', 'workforce-one');
                scan();
            }).catch(function () {
                status.textContent = __('Camera permission was denied or unavailable.', 'workforce-one');
            });
        }

        open.addEventListener('click', start);
        if (close) close.addEventListener('click', stop);
        modal.addEventListener('click', function (e) { if (e.target === modal) stop(); });
    }

    function init() {
        initBreakTimer();
        initLocationGate();
        initQrSignIn();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
