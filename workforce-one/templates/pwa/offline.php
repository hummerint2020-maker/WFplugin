<?php
/**
 * The installed app's offline screen (3.31.93): kept on the phone by the service worker and shown when
 * an app page cannot be loaded. The same for everyone (no employee data in it): the schedule and a Sign
 * In / Out that did not reach the server are read from what the app saved on this phone. Self-contained
 * (inline styles and script): nothing else may be reachable when it shows.
 *
 * @var array<string,string> $texts  OfflineScreen texts
 * @var int    $keep_note  show "keep the app open" under a pending attempt
 * @var int    $schedule   show the schedule kept on the phone
 * @var string $app_name   the app's name (as on its icon)
 * @var string $color      the app's colour (as on its icon)
 * @var string $app        where to go back to
 * @var string $ping       answers 204 while the server works
 * @var string $icon
 * @var string $preview    '' on phones; in wp-admin's preview the state to show (off, press, down), with
 *                         sample data, no going back, and texts taken live from the settings form
 */
if (!defined('ABSPATH')) exit;
$cfg = ['t' => $texts, 'keep' => (int) $keep_note, 'schedule' => (int) $schedule, 'app' => $app, 'ping' => $ping, 'preview' => $preview];
?><!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="<?php echo esc_attr($color); ?>">
<title><?php echo esc_html($app_name); ?></title>
<style>
:root{--bg:#f2f3f8;--paper:#fff;--ink:#1b1e33;--muted:#626783;--line:#e1e3ee;--hero:<?php echo esc_attr($color); ?>;--accent:<?php echo esc_attr($color); ?>;--warn:#9a5b00;--warn-soft:#fdf1dc;--ok:#0f7a4a;--ok-soft:#e1f4ea}
@media (prefers-color-scheme:dark){:root{--bg:#11131e;--paper:#1a1d2c;--ink:#e9eaf4;--muted:#a4a9c7;--line:#2d3146;--warn:#f2b55a;--warn-soft:#3a2a12;color-scheme:dark}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;min-height:100vh}
.wrap{max-width:460px;margin:0 auto;padding:max(14px,env(safe-area-inset-top)) 16px 28px;display:grid;gap:12px}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:4px 2px}
.top b{font-size:17px;display:flex;align-items:center;gap:8px}
.top img{width:26px;height:26px;border-radius:7px}
.badge{font-size:12px;border-radius:999px;padding:2px 10px;font-weight:600;background:var(--warn-soft);color:var(--warn);white-space:nowrap}
.hero{background:var(--hero);color:#fff;border-radius:20px;padding:22px 18px;display:grid;gap:6px;justify-items:center;text-align:center}
.hero svg{width:44px;height:44px}
.hero h1{margin:4px 0 0;font-size:19px;line-height:1.35}
.hero p{margin:0;font-size:14px;opacity:.88;white-space:pre-line}
.btn{display:block;width:100%;border:0;background:var(--accent);color:#fff;border-radius:14px;padding:13px;font:inherit;font-weight:600;cursor:pointer}
.btn.ghost{background:var(--paper);color:var(--ink);border:1px solid var(--line)}
.card{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:13px 15px;display:grid;gap:6px}
.card h2{margin:0;font-size:15px}
.row{display:flex;justify-content:space-between;gap:10px;font-size:14px}
.row span:last-child{color:var(--muted);text-align:right}
.note{font-size:13px;color:var(--muted)}
.saved{background:var(--warn-soft);border-radius:16px;padding:13px 15px;display:grid;gap:4px;font-size:14px}
.saved b{color:var(--warn);font-size:15px}
.saved span{white-space:pre-line}
.clock{background:var(--paper);border:1px solid var(--line);border-radius:20px;padding:16px;text-align:center;display:grid;gap:2px}
.clock .t{font-size:32px;font-weight:700;font-variant-numeric:tabular-nums}
[hidden]{display:none!important}
</style>
</head>
<body>
<main class="wrap" id="wfo-offline" data-state="off">
  <div class="top"><b><img src="<?php echo esc_url($icon); ?>" alt="" onerror="this.remove()"><?php echo esc_html($app_name); ?></b><span class="badge" id="o-badge"></span></div>
  <div class="hero" id="o-hero">
    <svg id="o-i-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 8.8a15 15 0 0 1 20 0"/><path d="M5.5 12.3a10 10 0 0 1 13 0"/><path d="M9 15.8a5 5 0 0 1 6 0"/><circle cx="12" cy="19" r="1"/><path d="M3 3l18 18"/></svg>
    <svg id="o-i-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="16" height="6" rx="1.5"/><rect x="4" y="14" width="16" height="6" rx="1.5"/><path d="M8 7h.01M8 17h.01M14 9l4 4M18 9l-4 4"/></svg>
    <h1 id="o-title"></h1><p id="o-msg"></p>
  </div>
  <div class="clock" id="o-clock"><span class="note" id="o-now"></span><span class="t" id="o-clock-t"></span></div>
  <div class="saved" id="o-saved" role="status"><b id="o-ns-title"></b><span id="o-ns-msg"></span></div>
  <button type="button" class="btn" id="o-retry"></button>
  <div class="note" id="o-keep"></div>
  <div class="card" id="o-today"><h2 id="o-today-h"></h2><div id="o-today-rows"></div><div class="note" id="o-upd"></div></div>
  <div class="card" id="o-week"><h2 id="o-week-h"></h2><div id="o-week-rows"></div></div>
</main>
<script>
(function(C){
  var T=C.t,P=C.preview,$=function(id){return document.getElementById(id);};
  function get(k){try{return JSON.parse(localStorage.getItem(k)||'null');}catch(e){return null;}}
  // wp-admin's preview: sample data instead of this browser's.
  if(P){var d0=new Date(),n=Date.now(),demo=[['Office','09:00 – 17:00'],['Work from home',''],['Office',''],['Leave','']].map(function(x,i){var d=new Date(d0.getFullYear(),d0.getMonth(),d0.getDate()+i);return {date:ymd(d),day:d.toLocaleDateString('en-US',{weekday:'short'}),label:d.toLocaleDateString('en-US',{weekday:'short',day:'numeric',month:'short'}),status:x[0],place:'',hours:x[1]};});
    get=function(k){return k==='ewsOfflineSchedule'?{saved_ms:n-5*60e3,days:demo}:(k==='ewsPendingAttempt'&&P!=='off'?{phone_ms:n-60e3,server_ms:n-120e3,elapsed_ms:60e3,event_type:'sign_in'}:null);};}
  function hm(ms){try{return new Date(ms).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});}catch(e){return '';}}
  function ymd(d){return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);}
  function show(id,on){var e=$(id);if(on)e.removeAttribute('hidden');else e.setAttribute('hidden','');}
  function row(a,b){var r=document.createElement('div');r.className='row';var x=document.createElement('span');x.textContent=a;var y=document.createElement('span');y.textContent=b;r.appendChild(x);r.appendChild(y);return r;}
  // A Sign In / Out that did not reach the server (saved by the app when it was pressed, 3.31.92).
  function pending(){
    var p=get('ewsPendingAttempt');if(!p||!p.phone_ms)return null;
    // Another account's note on a shared phone (3.31.118): not shown to the one whose week this phone keeps.
    var s=get('ewsOfflineSchedule');if(p.user!=null&&s&&s.user!=null&&String(s.user)!==String(p.user))return null;
    var at=Number(p.server_ms)>0?Number(p.server_ms)+Number(p.elapsed_ms||0):Number(p.phone_ms);
    if(Math.abs(Date.now()-Number(p.phone_ms))>12*3600e3)return null;
    return {at:at,action:p.event_type==='sign_out'?'Sign Out':'Sign In',out:p.event_type==='sign_out'};
  }
  function fill(s,p,upd){return String(s).replace(/\{time\}/g,p?hm(p.at):'').replace(/\{action\}/g,p?p.action:'Sign In').replace(/\{updated\}/g,upd||'');}
  function draw(){
    var p=pending(),online=P?P==='down':navigator.onLine!==false,state=online?'down':(p?'press':'off');
    $('wfo-offline').setAttribute('data-state',state);
    $('o-badge').textContent=online?T.down_badge:T.off_badge;
    show('o-hero',state!=='press');show('o-i-down',online);show('o-i-off',!online);
    $('o-title').textContent=online?T.down_title:T.off_title;
    $('o-msg').textContent=online?T.down_msg:T.off_msg;
    show('o-clock',state==='press');$('o-now').textContent=T.now;$('o-clock-t').textContent=hm(Date.now());
    show('o-saved',!!p);
    if(p){$('o-ns-title').textContent=p.out?T.ns_out_title:T.ns_title;$('o-ns-msg').textContent=fill(online?T.ns_down:T.ns_msg,p);}
    $('o-retry').textContent=T.retry;$('o-retry').className=state==='press'?'btn ghost':'btn';
    show('o-keep',!!p&&!!C.keep);$('o-keep').textContent=T.keep;
    // The schedule the app kept on this phone: today's day and the ones after it.
    var s=C.schedule&&state!=='press'?get('ewsOfflineSchedule'):null,today=ymd(new Date()),days=s&&s.days?s.days:[],t=null,rest=[];
    for(var i=0;i<days.length;i++){if(days[i].date===today)t=days[i];else if(days[i].date>today)rest.push(days[i]);}
    show('o-today',!!t);show('o-week',!!t&&rest.length>0);
    if(t){
      $('o-today-h').textContent=T.today;var tr=$('o-today-rows');tr.textContent='';
      tr.appendChild(row(t.label,t.status+(t.place?' · '+t.place:'')));
      if(t.hours)tr.appendChild(row(T.hours,t.hours));
      var sv=new Date(Number(s.saved_ms)||0),upd=(ymd(sv)===today?'today':sv.toLocaleDateString('en-US',{weekday:'short',day:'numeric',month:'short'}))+' at '+hm(sv.getTime());
      $('o-upd').textContent=fill(T.upd,null,upd);
      $('o-week-h').textContent=T.week;var wr=$('o-week-rows');wr.textContent='';
      rest.forEach(function(d){wr.appendChild(row(d.day,d.status+(d.place?' · '+d.place:'')));});
    }
  }
  // Back as soon as the server answers: the page the employee was opening, or the app after a form.
  var busy=false;
  function back(){
    if(busy)return;busy=true;
    fetch(C.ping+(C.ping.indexOf('?')<0?'?':'&')+'t='+Date.now(),{cache:'no-store',credentials:'same-origin'}).then(function(r){
      busy=false;if(!r.ok){draw();return;}
      try{sessionStorage.setItem('ewsCameBack','1');}catch(e){}
      location.replace(/admin-post\.php/.test(location.pathname)||location.pathname.indexOf('ews_pwa')>=0||/[?&]ews_pwa=offline/.test(location.search)?C.app:location.href);
    }).catch(function(){busy=false;draw();});
  }
  if(P){window.addEventListener('message',function(e){if(e.origin!==location.origin||!e.data||e.data.wfoOffline!==1)return;T=e.data.t||T;C.keep=e.data.keep;C.schedule=e.data.schedule;draw();});draw();return;}
  $('o-retry').addEventListener('click',back);
  window.addEventListener('online',function(){draw();back();});
  window.addEventListener('offline',draw);
  setInterval(function(){$('o-clock-t').textContent=hm(Date.now());},15000);
  setInterval(back,5000);
  draw();
})(<?php echo wp_json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>);
</script>
</body>
</html>
