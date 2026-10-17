/* wp-admin → Appearance (templates/admin/appearance.php): theme cards fill the colour fields, and the
   preview follows every change. The same rules as src/Settings/Appearance.php (mix, contrast). */
(function(){
    var form=document.getElementById('wfo-appearance-form'), pv=document.getElementById('wfo-appearance-preview');
    if(!form||!pv)return;
    var MIN=parseFloat(pv.getAttribute('data-min-contrast'))||4.5;
    function hex(v){v=String(v||'').trim().replace(/^#/,'');if(/^[0-9a-f]{3}$/i.test(v))v=v[0]+v[0]+v[1]+v[1]+v[2]+v[2];return /^[0-9a-f]{6}$/i.test(v)?'#'+v.toUpperCase():'';}
    function rgb(h){h=hex(h)||'#000000';return [parseInt(h.substr(1,2),16),parseInt(h.substr(3,2),16),parseInt(h.substr(5,2),16)];}
    function mix(a,b,t){var x=rgb(a),y=rgb(b);return '#'+[0,1,2].map(function(i){return ('0'+Math.round(x[i]+(y[i]-x[i])*t).toString(16)).slice(-2);}).join('').toUpperCase();}
    function lum(h){var c=rgb(h).map(function(v){v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);});return 0.2126*c[0]+0.7152*c[1]+0.0722*c[2];}
    function contrast(a,b){var x=lum(a),y=lum(b);return (Math.max(x,y)+0.05)/(Math.min(x,y)+0.05);}
    function onColor(bg,dark){return contrast(bg,dark)>=contrast(bg,'#FFFFFF')?dark:'#FFFFFF';}
    function field(n){return form.querySelector('[name="'+n+'"]');}
    function checked(n){var el=form.querySelector('[name="'+n+'"]:checked');return el;}
    function val(n){var el=field(n);return el?hex(el.value):'';}

    function render(){
        var hs=val('header_start')||'#13235B',he=val('header_end')||hs,hl=val('highlight')||'#FFC83D',pri=val('primary')||'#5B3FD9';
        var solid=(checked('header_style')||{}).value==='solid',mid=solid?hs:mix(hs,he,.5);
        var s=pv.style;
        s.setProperty('--wfo-hero',solid?hs:'linear-gradient(135deg,'+hs+' 0%,'+mid+' 55%,'+he+' 100%)');
        s.setProperty('--wfo-hero-start',hs);s.setProperty('--wfo-hl',hl);s.setProperty('--wfo-hl-ink',onColor(hl,hs));
        s.setProperty('--wfo-pri',pri);s.setProperty('--wfo-pri-soft',mix(pri,'#FFFFFF',.88));
        var f=checked('font');if(f&&f.getAttribute('data-stack'))s.setProperty('--wfo-font',f.getAttribute('data-stack'));
        var c=checked('corners');if(c){try{var r=JSON.parse(c.getAttribute('data-radius'));s.setProperty('--wfo-r-card',r.card+'px');s.setProperty('--wfo-r-ctl',r.control+'px');s.setProperty('--wfo-r-sm',r.small+'px');}catch(e){}}
        var company=field('company_name'),out=pv.querySelector('[data-preview-out="company"]');if(company&&out)out.textContent=company.value;
        var checks=[['White text on the header (start)',contrast(hs,'#FFFFFF')]];
        if(!solid)checks.push(['White text on the header (end)',contrast(he,'#FFFFFF')]);
        checks.push(['Highlight button text',contrast(hl,onColor(hl,hs))],['Main colour on white',contrast(pri,'#FFFFFF')]);
        var box=document.getElementById('wfo-ap-contrast');
        if(box){box.textContent='';checks.forEach(function(x){var sp=document.createElement('span');var ok=x[1]>=MIN;sp.className=ok?'ok':'bad';sp.textContent=(ok?'✓ ':'✕ ')+x[0]+' '+x[1].toFixed(1)+' : 1'+(ok?'':' — will be refused');box.appendChild(sp);});}
    }

    form.addEventListener('input',function(e){
        var t=e.target;
        if(t.getAttribute('data-color-for')){var txt=field(t.getAttribute('data-color-for'));if(txt)txt.value=t.value.toUpperCase();markCustom();}
        else if(t.getAttribute('data-color')){var h=hex(t.value),pk=form.querySelector('[data-color-for="'+t.getAttribute('data-color')+'"]');if(h&&pk)pk.value=h.toLowerCase();markCustom();}
        render();
    });
    form.addEventListener('change',function(e){
        var t=e.target;
        if(t.name==='preset'&&t.getAttribute('data-preset')){
            try{var p=JSON.parse(t.getAttribute('data-preset'));
                ['header_start','header_end','highlight','primary'].forEach(function(n){var el=field(n),pk=form.querySelector('[data-color-for="'+n+'"]');if(el)el.value=p[n];if(pk)pk.value=p[n].toLowerCase();});
                var fr=form.querySelector('[name="font"][value="'+p.font+'"]');if(fr)fr.checked=true;
                var ap=document.getElementById('wfo-preset-applied');if(ap)ap.checked=true;
            }catch(err){}
        }
        render();
    });
    function markCustom(){var c=form.querySelector('[name="preset"][value="custom"]');if(c)c.checked=true;var ap=document.getElementById('wfo-preset-applied');if(ap)ap.checked=false;}
    render();
})();
