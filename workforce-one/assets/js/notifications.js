/* Employee app → Notifications (templates/app/notifications.php): the "alerts on this device" strip.
   Subscribes / unsubscribes this browser for Web Push through admin-post (ews_push_subscribe /
   ews_push_unsubscribe). The strip stays hidden where push is not available or not set up. */
(function () {
    'use strict';
    function b64(s) {
        var p = '='.repeat((4 - s.length % 4) % 4), x = (s + p).replace(/-/g, '+').replace(/_/g, '/'), r = atob(x), a = new Uint8Array(r.length);
        for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i);
        return a;
    }

    function init() {
        var box = document.getElementById('ews-notification-push-settings');
        if (!box) return;
        var key = box.getAttribute('data-push-key') || '';
        var url = box.getAttribute('data-push-url');
        var nonce = box.getAttribute('data-push-nonce');
        var on = document.getElementById('ews-notification-push-enable');
        var off = document.getElementById('ews-notification-push-disable');
        if (!key || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

        function post(fields) {
            var body = new URLSearchParams();
            Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
            body.set('_wpnonce', nonce);
            return fetch(url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
                if (!r.ok) throw new Error('push ' + r.status);
                return r.text();
            });
        }
        function sameKey(sub) {
            var k = sub.options && sub.options.applicationServerKey, want = b64(key);
            if (!k) return true;
            k = new Uint8Array(k);
            if (k.length !== want.length) return false;
            for (var i = 0; i < k.length; i++) if (k[i] !== want[i]) return false;
            return true;
        }
        function state() {
            navigator.serviceWorker.ready
                .then(function (reg) { return reg.pushManager.getSubscription(); })
                .then(function (sub) { box.classList.toggle('is-on', !!sub); box.hidden = false; })
                .catch(function () {});
        }
        on.addEventListener('click', function () {
            on.disabled = true;
            navigator.serviceWorker.ready.then(function (reg) {
                return Notification.requestPermission().then(function (p) {
                    if (p !== 'granted') throw new Error('Notification permission was not granted.');
                    return reg.pushManager.getSubscription().then(function (s) {
                        // Made with another server key (the site's keys were renewed): it can never receive.
                        if (s && !sameKey(s)) return s.unsubscribe().then(function () { return null; });
                        return s;
                    }).then(function (s) {
                        return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(key) });
                    });
                });
            }).then(function (sub) {
                return post({ action: 'ews_push_subscribe', subscription: JSON.stringify(sub.toJSON()) });
            }).then(function () { if (window.ewsPushOff) window.ewsPushOff(false); }).catch(function () {}).then(function () { on.disabled = false; state(); });
        });
        off.addEventListener('click', function () {
            off.disabled = true;
            navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
                if (!sub) return null;
                return post({ action: 'ews_push_unsubscribe', endpoint: sub.endpoint }).then(function () { return sub.unsubscribe(); });
            }).then(function () { if (window.ewsPushOff) window.ewsPushOff(true); }).catch(function () {}).then(function () { off.disabled = false; state(); });
        });
        state();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
