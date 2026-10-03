/* Employee app → Presence Verification (templates/app/presence.php): scan the kiosk QR with the
   camera (jsQR) and submit it. */
(function () {
    'use strict';
    function init() {
        var box = document.getElementById('wfo-presence-scanner'), input = document.getElementById('wfo-presence-payload'), form = document.getElementById('wfo-presence-form');
        if (!box || !input || !form) return;
        var video = document.createElement('video'), canvas = document.createElement('canvas');
        canvas.width = 640; canvas.height = 480;
        function fail() { box.textContent = box.getAttribute('data-unavailable') || ''; }
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
                        form.submit();
                        return;
                    }
                }
                requestAnimationFrame(scan);
            }
            scan();
        }).catch(fail);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
