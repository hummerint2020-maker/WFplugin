/* The kiosk's screen (templates/kiosk.php): draw the QR and fetch the next code every slot, the bar
   until the next code and the clock (this device's time). The kiosk link (with its key) is the only
   credential; requests send no cookies. */
(function () {
    'use strict';
    var box = document.getElementById('qr'), timer = document.getElementById('timer'), offline = document.getElementById('offline');
    var bar = document.getElementById('k-bar'), clock = document.getElementById('k-time'), day = document.getElementById('k-date');
    var lang = document.documentElement.lang || undefined;
    function showClock() {
        var d = new Date();
        try {
            if (clock) clock.textContent = d.toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit', hour12: false });
            if (day) day.textContent = d.toLocaleDateString(lang, { weekday: 'long', day: 'numeric', month: 'long' });
        } catch (e) { /* keep the server's text */ }
    }
    if (!box || typeof qrcode !== 'function') return;
    var SLOT = parseInt(box.getAttribute('data-slot'), 10) || 15, URL_ = box.getAttribute('data-url'), CHG = box.getAttribute('data-changes') || '%ds';
    var lastSlot = -1;
    function draw(v) { var q = qrcode(0, 'M'); q.addData(v); q.make(); box.innerHTML = q.createSvgTag({ cellSize: 8, margin: 2, scalable: true }); }
    function tick() {
        var now = Math.floor(Date.now() / 1000), slot = Math.floor(now / SLOT);
        timer.textContent = CHG.replace('%d', SLOT - (now % SLOT));
        if (bar) bar.style.width = (100 * (SLOT - (now % SLOT)) / SLOT) + '%';
        showClock();
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
