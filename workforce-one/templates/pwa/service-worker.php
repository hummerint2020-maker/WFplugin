<?php
/**
 * The installable app's service worker (served at ?ews_pwa=sw for the whole site): offline copies
 * of the plugin's static files, push notifications and notification clicks.
 * Keep the output stable: phones compare it byte for byte to decide whether to update.
 *
 * @var array{cache:string,static:string[],icon:string,assets_path:string} $sw  Manifest::serviceWorker()
 * @var string $scope
 * @var string $home
 * @var string $push_url   admin-post.php (ews_push_resubscribe)
 * @var string $vapid_key  push server public key
 * @var array{url:string,timeout:int,paths:string[],post:string} $offline  offline screen (3.31.93): its page
 *      ('' = off), seconds to wait for an app page, the app pages' paths, and admin-post.php's path
 */
if (!defined('ABSPATH')) exit;
?>
const CACHE=<?php echo wp_json_encode($sw['cache']); ?>;
const APP_SCOPE=<?php echo wp_json_encode($scope); ?>;
const STATIC_ASSETS=<?php echo wp_json_encode($sw['static']); ?>;
const HOME_URL=<?php echo wp_json_encode($home); ?>;
const ICON_URL=<?php echo wp_json_encode($sw['icon']); ?>;
const ASSETS_PATH=<?php echo wp_json_encode($sw['assets_path']); ?>;
const PUSH_URL=<?php echo wp_json_encode($push_url); ?>;
const VAPID_KEY=<?php echo wp_json_encode($vapid_key); ?>;
<?php if ($offline['url'] !== '') : ?>
const OFFLINE_URL=<?php echo wp_json_encode($offline['url']); ?>;
const OFFLINE_WAIT=<?php echo (int) $offline['timeout'] * 1000; ?>;
const APP_PATHS=<?php echo wp_json_encode($offline['paths']); ?>;
const POST_PATH=<?php echo wp_json_encode($offline['post']); ?>;
<?php endif; ?>
// The offline screen's page is kept with the files; failing to fetch it never stops the update.
const keepOffline=c=>typeof OFFLINE_URL==='undefined'?null:c.match(OFFLINE_URL).then(r=>r||c.add(OFFLINE_URL)).catch(()=>{});
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(STATIC_ASSETS).then(()=>keepOffline(c))).then(()=>self.skipWaiting()));});
// Only this app's older caches are removed (3.31.92): other apps on the same site keep theirs.
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE&&k.indexOf('employee-hub-v')===0).map(k=>caches.delete(k)))).then(()=>caches.open(CACHE)).then(keepOffline).then(()=>self.clients.claim()));});
<?php if ($offline['url'] !== '') : ?>
/* Offline screen (3.31.93): an app page that cannot be loaded shows the kept page instead of the
   browser's blank one. Opening a page: no network, a server error or no answer within OFFLINE_WAIT.
   A form sent to admin-post.php (Sign In / Out): no network or the server down (502/503/504) only,
   never a time limit, as the server may still record it. */
const offlinePage=()=>caches.open(CACHE).then(c=>c.match(OFFLINE_URL)).then(r=>r||Response.error());
const openPage=req=>new Promise(done=>{let over=false;const end=r=>{if(!over){over=true;clearTimeout(t);done(r);}};const t=setTimeout(()=>end(offlinePage()),OFFLINE_WAIT);fetch(req).then(r=>end(r.status>=500?offlinePage():r)).catch(()=>end(offlinePage()));});
const sendForm=req=>fetch(req).then(r=>[502,503,504].indexOf(r.status)>=0?offlinePage():r).catch(()=>offlinePage());
self.addEventListener('fetch',e=>{if(e.request.mode!=='navigate')return;const u=new URL(e.request.url);if(u.origin!==self.location.origin)return;if(e.request.method==='GET'&&APP_PATHS.indexOf(u.pathname)>=0)e.respondWith(openPage(e.request));else if(e.request.method==='POST'&&u.pathname===POST_PATH)e.respondWith(sendForm(e.request));});
<?php endif; ?>
self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(!/^https?:$/.test(u.protocol))return;if(!u.pathname.startsWith(ASSETS_PATH))return;if(!/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(u.pathname))return;e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request)));});
self.addEventListener('push',e=>{let d={};try{d=e.data?e.data.json():{};}catch(x){d={body:e.data?e.data.text():'Workforce One notification'};}let title=d.title||'Workforce One';let opts={body:d.body||'You have a new notification.',icon:d.icon||ICON_URL,badge:d.badge||ICON_URL,data:{url:d.url||HOME_URL}};e.waitUntil(self.registration.showNotification(title,opts));});
// The push service replaced the subscription while the app was closed (3.31.87): subscribe again and tell
// the server, which recognises the device by its old endpoint. Without this, push stopped until the app was opened.
self.addEventListener('pushsubscriptionchange',e=>{const old=e.oldSubscription;const key=s=>{const p='='.repeat((4-s.length%4)%4),b=atob((s+p).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(b,c=>c.charCodeAt(0));};e.waitUntil((e.newSubscription?Promise.resolve(e.newSubscription):(VAPID_KEY?self.registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key(VAPID_KEY)}):Promise.reject())).then(sub=>{if(!old||!old.endpoint)return;const b=new URLSearchParams();b.set('action','ews_push_resubscribe');b.set('old_endpoint',old.endpoint);b.set('subscription',JSON.stringify(sub.toJSON()));return fetch(PUSH_URL,{method:'POST',credentials:'include',body:b});}).catch(()=>{}));});
self.addEventListener('notificationclick',e=>{e.notification.close();const url=(e.notification.data&&e.notification.data.url)||HOME_URL;e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(clients=>{for(const client of clients){if(client.url.startsWith(self.location.origin)){if('navigate' in client){return client.navigate(url).then(()=>client.focus()).catch(()=>self.clients.openWindow(url));}return client.focus();}}return self.clients.openWindow(url);}).catch(()=>self.clients.openWindow(url)));});
