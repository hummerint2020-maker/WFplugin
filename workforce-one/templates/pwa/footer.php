<?php
/**
 * The app page's footer for the installable app (PWA): the "Add to Home Screen" banner (with iPhone
 * instructions), the push-notification helpers (ewsEnablePush / ewsDisablePush, used by My Profile)
 * and the service worker registration.
 *
 * @var string $vapid_key   push server public key
 * @var string $push_url    where subscriptions are saved
 * @var string $push_nonce
 * @var int    $push_devices  devices the server has for this user (0: re-save this one now)
 * @var string $sw          service worker URL
 */
if (!defined('ABSPATH')) exit;
?>
            <div id="ews-pwa-install" style="display:none;position:fixed;left:12px;right:12px;bottom:82px;z-index:10000;background:#101828;color:#fff;border-radius:14px;padding:13px 14px;box-shadow:0 12px 30px rgba(16,24,40,.28);font:14px/1.4 Arial,sans-serif;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="flex:1;"><strong>Workforce One</strong><br><span style="opacity:.8;">Add Workforce One to your Home Screen for quick access.</span></div>
                    <button id="ews-pwa-install-btn" type="button" style="border:0;border-radius:9px;padding:10px 12px;background:#fff;color:#101828;font-weight:700;white-space:nowrap;">Add</button>
                    <button id="ews-pwa-install-close" type="button" aria-label="Close" style="border:0;background:transparent;color:#fff;font-size:20px;padding:4px;">×</button>
                </div>
            </div>
            <script>
            (function(){
              document.querySelectorAll('[data-ews-push-card="legacy"]').forEach(function(el){el.remove();});
              
              var promptEvent=null, box=document.getElementById('ews-pwa-install'), btn=document.getElementById('ews-pwa-install-btn'), close=document.getElementById('ews-pwa-install-close');
              window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();promptEvent=e;box.style.display='block';});
              if(btn)btn.addEventListener('click',function(){if(!promptEvent)return;promptEvent.prompt();promptEvent.userChoice.then(function(){promptEvent=null;box.style.display='none';});});
              if(close)close.addEventListener('click',function(){box.style.display='none';});
              var isIOS=/iphone|ipad|ipod/i.test(navigator.userAgent);
              var isStandalone=window.navigator.standalone===true || window.matchMedia('(display-mode: standalone)').matches;
              if(isIOS && !isStandalone){
                box.style.display='block';
                var copy=box.querySelector('span');
                var b=box.querySelector('#ews-pwa-install-btn');
                if(copy) copy.textContent='Safari: tap Share (□↑) then Add to Home Screen.';
                if(b){b.textContent='How';b.addEventListener('click',function(){alert('On iPhone: tap the Share button (□↑) in Safari, then choose “Add to Home Screen”. If you do not see it, scroll the Share menu down.');});}
              }
              window.EWS_PUSH_PUBLIC_KEY=<?php echo wp_json_encode($vapid_key); ?>;
              window.EWS_PUSH_URL=<?php echo wp_json_encode($push_url); ?>;
              window.EWS_PUSH_NONCE=<?php echo wp_json_encode($push_nonce); ?>;
              window.EWS_PUSH_DEVICES=<?php echo (int) $push_devices; ?>;
              function ewsUrlBase64ToUint8Array(base64String){var padding='='.repeat((4-base64String.length%4)%4),base64=(base64String+padding).replace(/-/g,'+').replace(/_/g,'/'),raw=window.atob(base64),out=new Uint8Array(raw.length);for(var i=0;i<raw.length;++i)out[i]=raw.charCodeAt(i);return out;}
              window.ewsEnablePush=function(){
                if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification' in window)){alert('Push notifications are not supported by this browser. On iPhone, add Workforce One to the Home Screen first.');return;}
                if(!window.EWS_PUSH_PUBLIC_KEY){alert('Push notifications are not configured yet.');return;}
                navigator.serviceWorker.ready.then(function(reg){
                  return Notification.requestPermission().then(function(permission){
                    if(permission!=='granted')throw new Error('Notification permission was not granted.');
                    return reg.pushManager.getSubscription().then(function(sub){
                      return sub||reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:ewsUrlBase64ToUint8Array(window.EWS_PUSH_PUBLIC_KEY)});
                    });
                  });
                }).then(function(sub){
                  var data=sub.toJSON();
                  var body=new URLSearchParams();
                  body.set('action','ews_push_subscribe');body.set('_wpnonce',window.EWS_PUSH_NONCE);body.set('subscription',JSON.stringify(data));
                  return fetch(window.EWS_PUSH_URL,{method:'POST',credentials:'same-origin',body:body});
                }).then(function(r){if(!r.ok)throw new Error('Subscription save failed');return r.text();}).then(function(){ewsPushOff(false);alert('Push notifications are enabled on this device.');}).catch(function(e){alert(e.message||'Unable to enable push notifications.');});
              };
              window.ewsDisablePush=function(){
                if(!('serviceWorker' in navigator))return;
                navigator.serviceWorker.ready.then(function(reg){return reg.pushManager.getSubscription();}).then(function(sub){
                  if(!sub)return;
                  var body=new URLSearchParams();body.set('action','ews_push_unsubscribe');body.set('_wpnonce',window.EWS_PUSH_NONCE);body.set('endpoint',sub.endpoint);
                  return fetch(window.EWS_PUSH_URL,{method:'POST',credentials:'same-origin',body:body}).then(function(){return sub.unsubscribe();});
                }).then(function(){ewsPushOff(true);alert('Push notifications disabled on this device.');});
              };
              // Turned off on this device by the user: the app does not turn it back on by itself.
              function ewsPushOff(v){try{if(v===undefined)return localStorage.getItem('ewsPushOff')==='1';if(v)localStorage.setItem('ewsPushOff','1');else localStorage.removeItem('ewsPushOff');}catch(e){}return false;}
              window.ewsPushOff=ewsPushOff;
              /* Keeps the server's copy of this device's subscription current (3.31.87). Browsers renew a
                 subscription from time to time (and after an update or a cleared cache); the server only
                 learned of it when the user pressed "Enable", so push stopped for them while the phone still
                 showed it as on. When permission is granted: a subscription made with another server key is
                 replaced, a missing one is made again, and it is re-saved every 3 days, 1 to 5 minutes after
                 the app opens (at random, so it never adds to the rush at shift start), or at once when the
                 server has no device for this user. */
              function ewsPushSync(){
                if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification' in window))return;
                if(Notification.permission!=='granted'||!window.EWS_PUSH_PUBLIC_KEY||ewsPushOff())return;
                var key=ewsUrlBase64ToUint8Array(window.EWS_PUSH_PUBLIC_KEY);
                function sameKey(sub){var k=sub.options&&sub.options.applicationServerKey;if(!k)return true;k=new Uint8Array(k);if(k.length!==key.length)return false;for(var i=0;i<k.length;i++)if(k[i]!==key[i])return false;return true;}
                navigator.serviceWorker.ready.then(function(reg){
                  return reg.pushManager.getSubscription().then(function(sub){
                    if(sub&&!sameKey(sub))return sub.unsubscribe().then(function(){return null;});
                    return sub;
                  }).then(function(sub){return sub||reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});});
                }).then(function(sub){
                  var last=[],now=Date.now(),none=!(window.EWS_PUSH_DEVICES>0);
                  try{last=(localStorage.getItem('ewsPushSynced')||'').split('|');}catch(e){}
                  // Same subscription saved less than 3 days ago, and the server has it: nothing to do.
                  if(!none&&last.length===2&&last[0]===sub.endpoint&&now-Number(last[1])<3*864e5)return;
                  var send=function(){
                    var body=new URLSearchParams();
                    body.set('action','ews_push_subscribe');body.set('_wpnonce',window.EWS_PUSH_NONCE);body.set('subscription',JSON.stringify(sub.toJSON()));
                    fetch(window.EWS_PUSH_URL,{method:'POST',credentials:'same-origin',body:body}).then(function(r){if(r.ok){try{localStorage.setItem('ewsPushSynced',sub.endpoint+'|'+Date.now());}catch(e){}}}).catch(function(){});
                  };
                  if(none)send();else setTimeout(send,60000+Math.floor(Math.random()*240000));
                }).catch(function(){});
              }
              window.ewsPushSync=ewsPushSync;
              if('serviceWorker' in navigator){navigator.serviceWorker.register(<?php echo wp_json_encode($sw); ?>,{updateViaCache:'none'}).then(ewsPushSync).catch(function(){});}
            })();
            </script>
            