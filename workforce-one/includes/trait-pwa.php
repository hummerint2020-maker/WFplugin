<?php
if (!defined('ABSPATH')) exit;

trait EWS_PWA_Trait {

    private function pwa_is_employee_app_page(){
            if(!is_page()) return false;
            $post=get_post();
            if(!$post) return false;
            return has_shortcode($post->post_content,'employee_app');
        }

    public function pwa_manifest_route(){
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='manifest'){
                nocache_headers();
                header('Content-Type: application/manifest+json; charset=utf-8');
                $app=$this->pwa_is_employee_app_page()?get_permalink():home_url('/');
                $root=plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
                echo wp_json_encode([
                    'name'=>'Employee Hub',
                    'short_name'=>'Employee Hub',
                    'description'=>'Employee Schedule & Attendance',
                    'start_url'=>add_query_arg(['ews_view'=>'time'],$app),
                    'scope'=>wp_parse_url(home_url('/'),PHP_URL_PATH) ?: '/',
                    'display'=>'standalone',
                    'orientation'=>'portrait-primary',
                    'background_color'=>'#ffffff',
                    'theme_color'=>'#101828',
                    'icons'=>[
                        ['src'=>$root.'assets/icons/workforce-one-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
                        ['src'=>$root.'assets/icons/workforce-one-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable']
                    ]
                ]);
                exit;
            }
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='icon'){
                nocache_headers();
                header('Content-Type: image/svg+xml; charset=utf-8');
                echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#101828"/><rect x="100" y="110" width="312" height="292" rx="42" fill="#fff"/><path d="M150 185h212M150 245h150M150 305h110" stroke="#101828" stroke-width="28" stroke-linecap="round"/><circle cx="355" cy="318" r="45" fill="#2563eb"/><path d="M355 292v52M329 318h52" stroke="#fff" stroke-width="16" stroke-linecap="round"/></svg>';
                exit;
            }
        }

    public function pwa_sw_route(){
            if(isset($_GET['ews_pwa']) && $_GET['ews_pwa']==='sw'){
                nocache_headers();
                header('Content-Type: application/javascript; charset=utf-8');
                header('Service-Worker-Allowed: ' . (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'));
                $root=plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
                $ver=defined('EWS_VERSION') ? EWS_VERSION : '3.22.81';
                $static=[
                    $root.'assets/css/workforce-one.css?ver='.$ver,
                    $root.'assets/js/workforce-one.js?ver='.$ver,
                    $root.'assets/icons/workforce-one-180.png',
                    $root.'assets/icons/workforce-one-192.png',
                    $root.'assets/icons/workforce-one-512.png'
                ];
                $scope=home_url('/');
                $home=home_url('/');
                $icon_url=$root.'assets/icons/workforce-one-192.png';
                $cache_name='employee-hub-v'.$ver;
                $assets_path=wp_parse_url($root, PHP_URL_PATH).'assets/';
                echo 'const CACHE='.wp_json_encode($cache_name).";\n";
                echo 'const APP_SCOPE='.wp_json_encode($scope).";\n";
                echo 'const STATIC_ASSETS='.wp_json_encode($static).";\n";
                echo 'const HOME_URL='.wp_json_encode($home).";\n";
                echo 'const ICON_URL='.wp_json_encode($icon_url).";\n";
                echo 'const ASSETS_PATH='.wp_json_encode($assets_path).";\n";
                echo "self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(STATIC_ASSETS)).then(()=>self.skipWaiting()));});\n";
                echo "self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});\n";
                echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;const u=new URL(e.request.url);if(!/^https?:$/.test(u.protocol))return;if(!u.pathname.startsWith(ASSETS_PATH))return;if(!/\\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(u.pathname))return;e.respondWith(caches.match(e.request).then(r=>r||fetch(e.request)));});\n";
                echo "self.addEventListener('push',e=>{let d={};try{d=e.data?e.data.json():{};}catch(x){d={body:e.data?e.data.text():'Workforce One notification'};}let title=d.title||'Workforce One';let opts={body:d.body||'You have a new notification.',icon:d.icon||ICON_URL,badge:d.badge||ICON_URL,data:{url:d.url||HOME_URL}};e.waitUntil(self.registration.showNotification(title,opts));});\n";
                echo "self.addEventListener('notificationclick',e=>{e.notification.close();const url=(e.notification.data&&e.notification.data.url)||HOME_URL;e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(clients=>{for(const client of clients){if(client.url.startsWith(self.location.origin)){if('navigate' in client){return client.navigate(url).then(()=>client.focus()).catch(()=>self.clients.openWindow(url));}return client.focus();}}return self.clients.openWindow(url);}).catch(()=>self.clients.openWindow(url)));});\n";
                exit;
            }
        }

    public function pwa_head(){
            if(!$this->pwa_is_employee_app_page()) return;
            $manifest=add_query_arg('ews_pwa','manifest',home_url('/'));
            $root=plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
            $icon=$root.'assets/icons/workforce-one-192.png';
            echo '<link rel="manifest" href="'.esc_url($manifest).'">';
            echo '<meta name="theme-color" content="#101828">';
            echo '<meta name="mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-capable" content="yes">';
            echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
            echo '<meta name="apple-mobile-web-app-title" content="Employee Hub">';
            echo '<style id="ews-pwa-mobile-shell">#wpadminbar{display:none!important}html{margin-top:0!important}body{margin-top:0!important}</style>';
            $splash=$this->pwa_splash_settings();
            if(!empty($splash['enabled'])){
            $splash_bg=esc_attr($splash['background']); $splash_accent=esc_attr($splash['accent']);
            $splash_title=esc_html($splash['title']); $splash_subtitle=esc_html($splash['subtitle']);
            $splash_logo=!empty($splash['logo'])?'<img src="'.esc_url($splash['logo']).'" alt="" style="width:60px;height:60px;object-fit:contain;border-radius:16px">':'<span>🏠</span>';
            echo '<style id="ews-pwa-splash-style">
            #ews-pwa-splash{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:'.$splash_bg.';opacity:1;visibility:visible;transition:opacity .22s ease,visibility .22s ease}
            #ews-pwa-splash.is-hidden{opacity:0;visibility:hidden;pointer-events:none}
            #ews-pwa-splash .ews-splash-inner{text-align:center;transform:translateY(-2vh)}
            #ews-pwa-splash .ews-splash-logo{width:86px;height:86px;margin:0 auto 20px;border-radius:22px;display:flex;align-items:center;justify-content:center;background:rgba(97,37,201,.10);box-shadow:0 8px 24px rgba(74,30,130,.10)}
            #ews-pwa-splash .ews-splash-logo span{font-size:44px;line-height:1}
            #ews-pwa-splash .ews-splash-title{font:800 29px/1.1 Arial,sans-serif;letter-spacing:-.7px;color:#17251f}
            #ews-pwa-splash .ews-splash-subtitle{margin-top:9px;font:500 14px/1.4 Arial,sans-serif;color:#69758c}
            #ews-pwa-splash .ews-splash-loader{width:34px;height:3px;margin:28px auto 0;overflow:hidden;border-radius:5px;background:rgba(0,0,0,.10)}
            #ews-pwa-splash .ews-splash-loader:after{content:"";display:block;width:45%;height:100%;border-radius:5px;background:'.$splash_accent.';animation:ewsSplashLoad 1s ease-in-out infinite}
            @keyframes ewsSplashLoad{0%{transform:translateX(-120%)}100%{transform:translateX(330%)}}
            @media(prefers-reduced-motion:reduce){#ews-pwa-splash .ews-splash-loader:after{animation:none}}
            </style>';
            echo '<div id="ews-pwa-splash" aria-hidden="false"><div class="ews-splash-inner"><div class="ews-splash-logo" aria-hidden="true">'.$splash_logo.'</div><div class="ews-splash-title">'.$splash_title.'</div><div class="ews-splash-subtitle">'.$splash_subtitle.'</div><div class="ews-splash-loader" aria-hidden="true"></div></div></div>';
            echo '<script id="ews-pwa-splash-script">
            (function(){
              var splash=document.getElementById("ews-pwa-splash"),start=Date.now(),minDuration='.(int)$splash['duration_ms'].';
              if(!splash)return;
              function hide(){
                var wait=Math.max(0,minDuration-(Date.now()-start));
                setTimeout(function(){splash.classList.add("is-hidden");splash.setAttribute("aria-hidden","true");setTimeout(function(){if(splash.parentNode)splash.parentNode.removeChild(splash);},260);},wait);
              }
              if(document.readyState==="complete") hide();
              else window.addEventListener("load",hide,{once:true});
            })();
            </script>';
                        }
echo '<link rel="apple-touch-icon" href="'.esc_url($icon).'">';
        }

    public function pwa_footer(){
            if(!$this->pwa_is_employee_app_page()) return;
            $sw=add_query_arg('ews_pwa','sw',home_url('/'));
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
              window.EWS_PUSH_PUBLIC_KEY=<?php echo wp_json_encode($this->get_vapid_public_key()); ?>;
              window.EWS_PUSH_URL=<?php echo wp_json_encode(admin_url('admin-post.php')); ?>;
              window.EWS_PUSH_NONCE=<?php echo wp_json_encode(wp_create_nonce('ews_push_subscription')); ?>;
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
                }).then(function(r){if(!r.ok)throw new Error('Subscription save failed');return r.text();}).then(function(){alert('Push notifications are enabled on this device.');}).catch(function(e){alert(e.message||'Unable to enable push notifications.');});
              };
              window.ewsDisablePush=function(){
                if(!('serviceWorker' in navigator))return;
                navigator.serviceWorker.ready.then(function(reg){return reg.pushManager.getSubscription();}).then(function(sub){
                  if(!sub)return;
                  var body=new URLSearchParams();body.set('action','ews_push_unsubscribe');body.set('_wpnonce',window.EWS_PUSH_NONCE);body.set('endpoint',sub.endpoint);
                  return fetch(window.EWS_PUSH_URL,{method:'POST',credentials:'same-origin',body:body}).then(function(){return sub.unsubscribe();});
                }).then(function(){alert('Push notifications disabled on this device.');});
              };
              if('serviceWorker' in navigator){navigator.serviceWorker.register(<?php echo wp_json_encode($sw); ?>,{updateViaCache:'none'}).catch(function(){});}
            })();
            </script>
            <?php
        }
}
