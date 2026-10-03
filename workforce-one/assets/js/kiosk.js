/* The kiosk's screen (templates/kiosk.php): draw the QR and fetch the next code every slot. The
   kiosk link (with its key) is the only credential; requests send no cookies. */
(function () {
    'use strict';
    var box = document.getElementById('qr'), timer = document.getElementById('timer'), offline = document.getElementById('offline');
    if (!box || typeof qrcode !== 'function') return;
    var SLOT = parseInt(box.getAttribute('data-slot'), 10) || 15, URL_ = box.getAttribute('data-url'), CHG = box.getAttribute('data-changes') || '%ds';
    var lastSlot = -1;
    function draw(v) { var q = qrcode(0, 'M'); q.addData(v); q.make(); box.innerHTML = q.createSvgTag({ cellSize: 8, margin: 2, scalable: true }); }
    function tick() {
        var now = Math.floor(Date.now() / 1000), slot = Math.floor(now / SLOT);
        timer.textContent = CHG.replace('%d', SLOT - (now % SLOT));
        if (slot === lastSlot) return;
        lastSlot = slot;
        fetch(URL_ + '&t=' + slot, { cache: 'no-store', credentials: 'omit' })
            .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
            .then(function (v) { if (v.indexOf('wfo1|') !== 0) throw new Error(); draw(v); offline.style.display = 'none'; })
            .catch(function () { offline.style.display = 'block'; lastSlot = -1; });
    }
    draw(box.getAttribute('data-payload'));
    lastSlot = Math.floor(Date.now() / 1000 / SLOT);
    setInterval(tick, 1000);
    tick();
})();
