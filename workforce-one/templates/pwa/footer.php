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
 * @var int    $push_user     the signed-in user (another account on this device: re-save at once)
 * @var array<string,mixed> $attempts  refused attempts kept (3.31.92): url, nonce, serverMs (empty = off)
 * @var array<string,mixed> $offline  offline screen (3.31.93): user, toast texts, schedule days (empty = off)
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
              window.EWS_PUSH_USER=<?php echo (int) $push_user; ?>;
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
                 server has no device for this user. The saved mark carries the account (3.31.90): when
                 another account opens the app on this device, the subscription is re-saved at once and
                 moves to that account. Logging out alone changes nothing: the phone keeps receiving the
                 last account's notifications (owner's decision). */
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
                  var last=[],now=Date.now(),user=String(window.EWS_PUSH_USER||0),none=!(window.EWS_PUSH_DEVICES>0);
                  try{last=(localStorage.getItem('ewsPushSynced')||'').split('|');}catch(e){}
                  // Saved for another account (or by a version before 3.31.90): re-save at once.
                  var other=last.length!==3||last[0]!==user;
                  // Same account and subscription saved less than 3 days ago, and the server has it: nothing to do.
                  if(!none&&!other&&last[1]===sub.endpoint&&now-Number(last[2])<3*864e5)return;
                  var send=function(){
                    var body=new URLSearchParams();
                    body.set('action','ews_push_subscribe');body.set('_wpnonce',window.EWS_PUSH_NONCE);body.set('subscription',JSON.stringify(sub.toJSON()));
                    fetch(window.EWS_PUSH_URL,{method:'POST',credentials:'same-origin',body:body}).then(function(r){if(r.ok){try{localStorage.setItem('ewsPushSynced',user+'|'+sub.endpoint+'|'+Date.now());}catch(e){}}}).catch(function(){});
                  };
                  if(none||other)send();else setTimeout(send,60000+Math.floor(Math.random()*240000));
                }).catch(function(){});
              }
              window.ewsPushSync=ewsPushSync;
              /* A Sign In / Out pressed with no network never reaches the server (3.31.92). The phone notes it
                 when the button is pressed; the page the server answers with (time_error / time_success)
                 clears the note. A note still there the next time the app opens is sent then. Its time is
                 counted from the server's clock when this page opened (serverMs) plus the milliseconds since,
                 which changing the phone's clock does not move; the server keeps both and shows any gap. */
              (function(C){
                // Signed out on purpose (the Log out link): a note left on this phone is removed (3.31.118). A session that ended on its
                // own keeps it, so it is sent when the same account signs in again.
                if(!(window.EWS_PUSH_USER>0)&&/[?&]loggedout=1/.test(location.search)){try{localStorage.removeItem('ewsPendingAttempt');}catch(e){}}
                if(!C||!C.url)return;
                var K='ewsPendingAttempt',t0=(window.performance&&performance.now)?performance.now():0;
                function now(){return (window.performance&&performance.now)?performance.now():0;}
                function get(){try{return JSON.parse(localStorage.getItem(K)||'null');}catch(e){return null;}}
                function clear(){try{localStorage.removeItem(K);}catch(e){}}
                var answered=/[?&](time_error|time_success)=/.test(location.search);
                if(answered)clear();
                var p=get();
                // Another account's note on a shared phone: removed, never sent (the server would drop it too).
                if(p&&p.user!=null&&String(p.user)!==String(C.user)){clear();p=null;}
                if(p&&!answered){
                  var b=new URLSearchParams();b.set('action','ews_signin_offline');b.set('_wpnonce',C.nonce);
                  for(var k in p)if(Object.prototype.hasOwnProperty.call(p,k))b.set(k,p[k]==null?'':String(p[k]));
                  // The offline screen's "back online" message waits for this (3.31.93): the attempt sent, or null.
                  window.ewsAttemptSent=fetch(C.url,{method:'POST',credentials:'same-origin',body:b}).then(function(r){if(r.ok){clear();return p;}return null;}).catch(function(){return null;});
                }
                window.ewsNoteAttempt=function(f){
                  if(!f||!f.querySelector)return;
                  var a=f.querySelector('input[name="action"]');if(!a||(a.value!=='ews31_time_event'&&a.value!=='ews_presence_qr_signin'))return;
                  var g=function(n){var i=f.querySelector('input[name="'+n+'"]');return i&&i.value?i.value:'';};
                  try{localStorage.setItem(K,JSON.stringify({user:C.user,event_type:g('event_type')||'sign_in',phone_ms:Date.now(),server_ms:C.serverMs,elapsed_ms:Math.max(0,Math.round(now()-t0)),latitude:g('latitude'),longitude:g('longitude'),accuracy:g('accuracy')}));}catch(e){}
                };
                // A form sent by its own button (a form held for the face check is noted when it is sent).
                document.addEventListener('submit',function(e){if(!e.defaultPrevented)window.ewsNoteAttempt(e.target);},false);
              })(<?php echo wp_json_encode($attempts); ?>);
              /* Offline screen (3.31.93). The employee's coming week is kept on this phone for the offline
                 page (removed when nobody is signed in or the screen is off). Back from the offline page,
                 a short message says so, with the Sign In / Out sent if there was one. */
              (function(O){
                try{if(O&&O.user&&O.days&&O.days.length)localStorage.setItem('ewsOfflineSchedule',JSON.stringify({user:O.user,saved_ms:Date.now(),days:O.days}));else localStorage.removeItem('ewsOfflineSchedule');}catch(e){}
                var back=false;try{back=sessionStorage.getItem('ewsCameBack')==='1';sessionStorage.removeItem('ewsCameBack');}catch(e){}
                if(!O||!O.user||!back)return;
                (window.ewsAttemptSent||Promise.resolve(null)).then(function(p){
                  var at=p?(Number(p.server_ms)>0?Number(p.server_ms)+Number(p.elapsed_ms||0):Number(p.phone_ms)):0,act=p&&p.event_type==='sign_out'?'Sign Out':'Sign In',hm='';
                  try{hm=at?new Date(at).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'}):'';}catch(e){}
                  var msg=p?String(O.toast).replace(/\{time\}/g,hm).replace(/\{action\}/g,act):String(O.toast0);
                  var t=document.createElement('div');t.id='ews-back-online';t.setAttribute('role','status');t.textContent=msg;
                  t.style.cssText='position:fixed;left:12px;right:12px;top:max(12px,env(safe-area-inset-top));z-index:2147483646;background:#e1f4ea;color:#12352a;border-radius:14px;padding:12px 14px;box-shadow:0 10px 26px rgba(16,24,40,.18);font:14px/1.45 system-ui,-apple-system,Arial,sans-serif;max-width:520px;margin:0 auto';
                  t.addEventListener('click',function(){t.remove();});
                  document.body.appendChild(t);setTimeout(function(){if(t.parentNode)t.remove();},8000);
                });
              })(<?php echo wp_json_encode($offline); ?>);
              if('serviceWorker' in navigator){navigator.serviceWorker.register(<?php echo wp_json_encode($sw); ?>,{updateViaCache:'none'}).then(ewsPushSync).catch(function(){});}
            })();
            </script>
            