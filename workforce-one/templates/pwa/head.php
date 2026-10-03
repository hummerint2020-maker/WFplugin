<?php
/**
 * The app page's head tags for the installable app (PWA): manifest, theme colour, home-screen tags,
 * and the splash screen (inline so it paints before anything else).
 *
 * @var string $manifest     manifest URL
 * @var string $icon         iPhone home-screen icon
 * @var array<string,mixed>|null $splash  splash settings (pwa_splash_settings()), null when off
 */
if (!defined('ABSPATH')) exit;
?>
<link rel="manifest" href="<?php echo esc_url($manifest); ?>"><meta name="theme-color" content="#101828"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default"><meta name="apple-mobile-web-app-title" content="Employee Hub"><style id="ews-pwa-mobile-shell">#wpadminbar{display:none!important}html{margin-top:0!important}body{margin-top:0!important}</style><?php if ($splash): ?><style id="ews-pwa-splash-style">
            #ews-pwa-splash{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:<?php echo esc_attr($splash['background']); ?>;opacity:1;visibility:visible;transition:opacity .22s ease,visibility .22s ease}
            #ews-pwa-splash.is-hidden{opacity:0;visibility:hidden;pointer-events:none}
            #ews-pwa-splash .ews-splash-inner{text-align:center;transform:translateY(-2vh)}
            #ews-pwa-splash .ews-splash-logo{width:86px;height:86px;margin:0 auto 20px;border-radius:22px;display:flex;align-items:center;justify-content:center;background:rgba(97,37,201,.10);box-shadow:0 8px 24px rgba(74,30,130,.10)}
            #ews-pwa-splash .ews-splash-logo span{font-size:44px;line-height:1}
            #ews-pwa-splash .ews-splash-title{font:800 29px/1.1 Arial,sans-serif;letter-spacing:-.7px;color:#17251f}
            #ews-pwa-splash .ews-splash-subtitle{margin-top:9px;font:500 14px/1.4 Arial,sans-serif;color:#69758c}
            #ews-pwa-splash .ews-splash-loader{width:34px;height:3px;margin:28px auto 0;overflow:hidden;border-radius:5px;background:rgba(0,0,0,.10)}
            #ews-pwa-splash .ews-splash-loader:after{content:"";display:block;width:45%;height:100%;border-radius:5px;background:<?php echo esc_attr($splash['accent']); ?>;animation:ewsSplashLoad 1s ease-in-out infinite}
            @keyframes ewsSplashLoad{0%{transform:translateX(-120%)}100%{transform:translateX(330%)}}
            @media(prefers-reduced-motion:reduce){#ews-pwa-splash .ews-splash-loader:after{animation:none}}
            </style><div id="ews-pwa-splash" aria-hidden="false"><div class="ews-splash-inner"><div class="ews-splash-logo" aria-hidden="true"><?php if (!empty($splash['logo'])): ?><img src="<?php echo esc_url($splash['logo']); ?>" alt="" style="width:60px;height:60px;object-fit:contain;border-radius:16px"><?php else: ?><span>🏠</span><?php endif; ?></div><div class="ews-splash-title"><?php echo esc_html($splash['title']); ?></div><div class="ews-splash-subtitle"><?php echo esc_html($splash['subtitle']); ?></div><div class="ews-splash-loader" aria-hidden="true"></div></div></div><script id="ews-pwa-splash-script">
            (function(){
              var splash=document.getElementById("ews-pwa-splash"),start=Date.now(),minDuration=<?php echo (int) $splash['duration_ms']; ?>;
              if(!splash)return;
              function hide(){
                var wait=Math.max(0,minDuration-(Date.now()-start));
                setTimeout(function(){splash.classList.add("is-hidden");splash.setAttribute("aria-hidden","true");setTimeout(function(){if(splash.parentNode)splash.parentNode.removeChild(splash);},260);},wait);
              }
              if(document.readyState==="complete") hide();
              else window.addEventListener("load",hide,{once:true});
            })();
            </script><?php endif; ?><link rel="apple-touch-icon" href="<?php echo esc_url($icon); ?>">