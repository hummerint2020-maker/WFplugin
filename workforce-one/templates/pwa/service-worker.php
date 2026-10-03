<?php
/**
 * The installable app's service worker (served at ?ews_pwa=sw for the whole site): offline copies
 * of the plugin's static files, push notifications and notification clicks.
 * Keep the output stable: phones compare it byte for byte to decide whether to update.
 *
 * @var array{cache:string,static:string[],icon:string,assets_path:string} $sw  Manifest::serviceWorker()
 * @var string $scope
 * @var string $home
 */
if (!defined('ABSPATH')) exit;
?>
const CACHE=<?php echo wp_json_encode($sw['cache']); ?>;
const APP_SCOPE=<?php echo wp_json_encode($scope); ?>;
const STATIC_ASSETS=<?php echo wp_json_encode($sw['static']); ?>;
const HOME_URL=<?php echo wp_json_encode($home); ?>;
const ICON_URL=<?php echo wp_json_encode($sw['icon']); ?>;
const ASSETS_PATH=<?php echo wp_json_encode($sw['assets_path']); ?>;
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(STATIC_ASSETS)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(!/^https?:$/.test(u.protocol))return;if(!u.pathname.startsWith(ASSETS_PATH))return;if(!/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(u.pathname))return;e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request)));});
self.addEventListener('push',e=>{let d={};try{d=e.data?e.data.json():{};}catch(x){d={body:e.data?e.data.text():'Workforce One notification'};}let title=d.title||'Workforce One';let opts={body:d.body||'You have a new notification.',icon:d.icon||ICON_URL,badge:d.badge||ICON_URL,data:{url:d.url||HOME_URL}};e.waitUntil(self.registration.showNotification(title,opts));});
self.addEventListener('notificationclick',e=>{e.notification.close();const url=(e.notification.data&&e.notification.data.url)||HOME_URL;e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(clients=>{for(const client of clients){if(client.url.startsWith(self.location.origin)){if('navigate' in client){return client.navigate(url).then(()=>client.focus()).catch(()=>self.clients.openWindow(url));}return client.focus();}}return self.clients.openWindow(url);}).catch(()=>self.clients.openWindow(url)));});
