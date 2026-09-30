(function(){
    function initEwsSubmitGuard(){
        document.querySelectorAll("form").forEach(function(form){
            if(form.dataset.ewsSubmitGuard==="1") return;
            form.dataset.ewsSubmitGuard="1";
            form.addEventListener("submit",function(e){
                if(form.dataset.ewsConfirmPending==="1" && form.dataset.ewsConfirmApproved!=="1"){
                    return;
                }
                if(form.dataset.ewsSubmitting==="1"){
                    e.preventDefault();
                    return false;
                }
                form.dataset.ewsSubmitting="1";
                var submitters=form.querySelectorAll("button[type=submit],input[type=submit]");
                submitters.forEach(function(btn){
                    if(btn.dataset.ewsOriginalHtml===undefined) btn.dataset.ewsOriginalHtml=btn.innerHTML||btn.value||"";
                    btn.disabled=true;
                    btn.classList.add("ews-submit-loading");
                    if(btn.tagName==="BUTTON"){
                        var spinner=btn.querySelector(".ews-btn-spinner");
                        if(!spinner){
                            spinner=document.createElement("span");
                            spinner.className="ews-btn-spinner";
                            spinner.setAttribute("aria-hidden","true");
                            btn.insertBefore(spinner,btn.firstChild);
                        }
                    }
                    btn.setAttribute("aria-busy","true");
                });
            },{capture:true});
        });
    }
    function ewsConfirmationMeta(form,submitter){
        var action=(form.querySelector("input[name=\"action\"]")||{}).value||"";
        var decision=submitter?(submitter.name==="decision"?submitter.value:""):"";
        var cfg=window.ewsConfirmationConfig||{};
        if(cfg.global===false)return null;
        function enabled(key){return cfg.actions&&Object.prototype.hasOwnProperty.call(cfg.actions,key)?!!cfg.actions[key]:true;}
        if(action==="ews_swap_cancel" && enabled("swap_cancel")) return ["Cancel Shift Swap","Are you sure you want to cancel this shift swap request?","Cancel Swap"];
        if(action==="ews_swap_respond" && decision==="reject" && enabled("swap_reject")) return ["Reject Shift Swap","Are you sure you want to reject this shift swap request?","Reject"];
        if(action==="ews_leave_cancel" && enabled("leave_cancel")) return ["Cancel Leave Request","Are you sure you want to cancel this leave request?","Cancel Leave"];
        if(action==="ews_leave_cancel_respond" && decision==="reject" && enabled("leave_cancel_reject")) return ["Reject Cancellation","Are you sure you want to reject this leave cancellation request?","Reject"];
        if(action==="ews_overtime_request_respond" && decision==="reject" && enabled("overtime_reject")) return ["Reject Overtime Request","Are you sure you want to reject this overtime request?","Reject"];
        if(action==="ews_early_leave_respond" && decision==="reject" && enabled("early_leave_reject")) return ["Reject Early Leave Request","Are you sure you want to reject this early leave request?","Reject"];
        return null;
    }
    function ewsShowConfirm(form,submitter,meta){
        var old=document.getElementById("ews-action-confirm-modal");if(old)old.remove();
        var m=document.createElement("div");m.id="ews-action-confirm-modal";m.className="ews-ux-modal";m.setAttribute("data-type","warning");m.setAttribute("role","dialog");m.setAttribute("aria-modal","true");
        m.innerHTML=`<div class="ews-ux-modal-card"><button type="button" class="ews-ux-close" aria-label="Close">×</button><div class="ews-ux-icon">!</div><h3></h3><p></p><div class="ews-ux-actions"><button type="button" class="ews-ux-cancel">Cancel</button><button type="button" class="ews-ux-confirm"></button></div></div>`;
        m.querySelector("h3").textContent=meta[0];m.querySelector("p").textContent=meta[1];m.querySelector(".ews-ux-confirm").textContent=meta[2];
        document.body.appendChild(m);
        var close=function(){
            m.remove();
            form.dataset.ewsConfirmPending="";
            form.dataset.ewsConfirmApproved="";
            form.dataset.ewsSubmitting="";
            form.querySelectorAll("button[type=submit],input[type=submit]").forEach(function(btn){
                btn.disabled=false;
                btn.classList.remove("ews-submit-loading");
                btn.removeAttribute("aria-busy");
            });
        };
        m.querySelector(".ews-ux-close").onclick=close;m.querySelector(".ews-ux-cancel").onclick=close;
        m.addEventListener("click",function(e){if(e.target===m)close()});
        m.querySelector(".ews-ux-confirm").onclick=function(){
            m.remove();
            form.dataset.ewsConfirmApproved="1";
            form.dataset.ewsConfirmPending="";
            form.dataset.ewsSubmitting="";
            // The original submit button was disabled by the global loading guard
            // while the confirmation dialog was open. Re-enable it before
            // requestSubmit(); otherwise some browsers treat it as a disabled
            // submitter and the form submission may not occur.
            if(submitter){
                submitter.disabled=false;
                submitter.classList.remove("ews-submit-loading");
                submitter.removeAttribute("aria-busy");
            }
            form.requestSubmit(submitter||undefined);
        };
        document.addEventListener("keydown",function handler(e){if(e.key==="Escape"&&document.getElementById("ews-action-confirm-modal")){close();document.removeEventListener("keydown",handler)}},{once:false});
        m.querySelector(".ews-ux-confirm").focus();
    }
    function initEwsConfirmations(){
        document.querySelectorAll("form").forEach(function(form){
            if(form.dataset.ewsConfirmGuard==="1")return;
            form.dataset.ewsConfirmGuard="1";
            form.addEventListener("submit",function(e){
                if(form.dataset.ewsConfirmApproved==="1"){
                    form.dataset.ewsConfirmApproved="";
                    return;
                }
                var meta=ewsConfirmationMeta(form,e.submitter);
                if(!meta)return;
                e.preventDefault();
                form.dataset.ewsConfirmPending="1";
                ewsShowConfirm(form,e.submitter,meta);
            });
        });
    }
    if(document.readyState==="loading"){
        document.addEventListener("DOMContentLoaded",initEwsConfirmations);
    }else{
        initEwsConfirmations();
    }
    if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",initEwsSubmitGuard);
    else initEwsSubmitGuard();
    window.addEventListener("pageshow",function(){document.querySelectorAll("form[data-ews-submit-guard]").forEach(function(form){form.dataset.ewsSubmitting="";form.dataset.ewsConfirmPending="";form.dataset.ewsConfirmApproved="";form.querySelectorAll("button[type=submit],input[type=submit]").forEach(function(btn){btn.disabled=false;btn.classList.remove("ews-submit-loading");btn.removeAttribute("aria-busy")})});initEwsSubmitGuard();initEwsConfirmations();});
})();

(function(){
function ewsCloseGlobalModal(m){if(!m)return;m.remove();try{var u=new URL(window.location.href);["leave_error","leave_sent","leave_done","leave_cancel_sent","early_error","time_success","time_error","break_success","break_error","overtime_sent","overtime_error","saved","imported","grid_saved","time_reset","time_saved"].forEach(function(k){u.searchParams.delete(k)});window.history.replaceState({},document.title,u.toString())}catch(e){}}
document.addEventListener("click",function(e){var b=e.target.closest(".ews-ux-ok,.ews-ux-close,.ews-ux-cancel");if(b){var m=b.closest(".ews-ux-modal");if(m)ewsCloseGlobalModal(m)}if(e.target.classList&&e.target.classList.contains("ews-ux-modal"))ewsCloseGlobalModal(e.target)});
document.addEventListener("keydown",function(e){if(e.key==="Escape"){var m=document.querySelector(".ews-ux-modal");if(m)ewsCloseGlobalModal(m)}});
function ewsConsumeGlobalFlash(){var keys=["leave_error","leave_sent","leave_done","leave_cancel_sent","early_error","time_success","time_error","break_success","break_error","overtime_sent","overtime_error","swap_error","swap_sent","swap_done","vacation_sent","vacation_error","vacation_done","vacation_rejected","saved","imported","grid_saved","time_reset","time_saved","profile_updated","profile_error"];try{var u=new URL(window.location.href),flash=false;keys.forEach(function(k){if(u.searchParams.has(k))flash=true;});if(!flash)return;keys.forEach(function(k){u.searchParams.delete(k)});window.history.replaceState({},document.title,u.toString());var m=document.querySelector(".ews-ux-modal");if(m){var b=m.querySelector(".ews-ux-ok,.ews-ux-confirm");if(b)b.focus();}}catch(e){var m=document.querySelector(".ews-ux-modal");if(m){var b=m.querySelector(".ews-ux-ok,.ews-ux-confirm");if(b)b.focus();}}}
document.addEventListener("DOMContentLoaded",ewsConsumeGlobalFlash);
window.addEventListener("pageshow",function(){ewsConsumeGlobalFlash();});
})();


(function(){
    function initEwsNotificationBell(){
        document.querySelectorAll('.ews-notification-bell-wrap').forEach(function(wrap){
            var btn=wrap.querySelector('.ews-notification-bell');
            var menu=wrap.querySelector('.ews-notification-dropdown');
            if(!btn||!menu||wrap.dataset.ewsBellReady==='1') return;
            wrap.dataset.ewsBellReady='1';
            function close(){menu.hidden=true;btn.setAttribute('aria-expanded','false');}
            btn.addEventListener('click',function(e){
                e.preventDefault();e.stopPropagation();
                var open=menu.hidden;
                document.querySelectorAll('.ews-notification-dropdown').forEach(function(x){x.hidden=true;});
                document.querySelectorAll('.ews-notification-bell').forEach(function(x){x.setAttribute('aria-expanded','false');});
                menu.hidden=!open;btn.setAttribute('aria-expanded',open?'true':'false');
            });
            menu.addEventListener('click',function(e){e.stopPropagation();});
            wrap._ewsBellClose=close;
        });
    }
    function closeEwsNotificationBells(){document.querySelectorAll('.ews-notification-bell-wrap').forEach(function(wrap){if(typeof wrap._ewsBellClose==='function')wrap._ewsBellClose();});}
    document.addEventListener('click',function(e){if(e.target.closest('.ews-notification-bell-wrap'))return;closeEwsNotificationBells();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape')closeEwsNotificationBells();});
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initEwsNotificationBell);else initEwsNotificationBell();
    window.addEventListener('pageshow',initEwsNotificationBell);
})();


(function(){
  function initFaceModule(module){
    if(!module || module.dataset.ewsFaceReady==='1') return;
    var apiBase=module.getAttribute('data-api-base')||'', serverEnrolled=module.getAttribute('data-server-enrolled')==='1';
    var wpNonce=module.getAttribute('data-wp-nonce')||'', modal=module.querySelector('#ews-face-modal'), video=module.querySelector('#ews-face-video');
    var status=module.querySelector('#ews-face-status'), state=module.querySelector('#ews-face-state');
    var stream=null,ready=false,busy=false,pendingForm=null;
    var faceRequired=module.getAttribute('data-face-required')==='1', faceCfg=JSON.parse(module.getAttribute('data-face-config')||'{}'),SAMPLE_COUNT=5,THRESHOLD=.60;

              
              var apiBase=module.getAttribute('data-api-base')||'', serverEnrolled=module.getAttribute('data-server-enrolled')==='1';
              var wpNonce=module.getAttribute('data-wp-nonce')||'', modal=module.querySelector('#ews-face-modal'),video=module.querySelector('#ews-face-video');
              var status=module.querySelector('#ews-face-status'),state=module.querySelector('#ews-face-state');
              var stream=null,ready=false,busy=false,pendingForm=null;
              var faceRequired=module.getAttribute('data-face-required')==='1', faceCfg=JSON.parse(module.getAttribute('data-face-config')||'{}'),SAMPLE_COUNT=5,THRESHOLD=.60;

              function setStatus(s){if(status)status.textContent=s;}
              function profile(){return serverEnrolled?{template:true,samples:SAMPLE_COUNT}:null;}
              function refreshState(){
                var p=profile();
                if(state)state.textContent=p&&p.template?'Enrolled ('+p.samples+' samples)':'Not enrolled';
                var open=module.querySelector('#ews-face-open'),enrollBtn=module.querySelector('#ews-face-enroll'),resetBtn=module.querySelector('#ews-face-reset');
                if(open)open.textContent='📷 '+(p&&p.template?'Verify Face':'Set Up / Verify Face');
                if(enrollBtn)enrollBtn.style.display=p&&p.template?'none':'';
                if(resetBtn)resetBtn.style.display=p&&p.template?'':'none';
              }
              function dist(a,b){var s=0;for(var i=0;i<a.length;i++){var d=a[i]-b[i];s+=d*d;}return Math.sqrt(s);}
              function avg(ds){var o=new Array(ds[0].length).fill(0);ds.forEach(function(d){for(var i=0;i<d.length;i++)o[i]+=d[i];});for(var j=0;j<o.length;j++)o[j]/=ds.length;return o;}

              async function loadModels(){
                if(window.faceapi)return;
                await new Promise(function(resolve,reject){var s=document.createElement('script');
                  s.src='https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
                  s.onload=resolve;s.onerror=function(){reject(new Error('Could not load face-api.js.'));};document.head.appendChild(s);});
                var base='https://justadudewhohacks.github.io/face-api.js/models';
                await Promise.all([faceapi.nets.tinyFaceDetector.loadFromUri(base),faceapi.nets.faceLandmark68Net.loadFromUri(base),faceapi.nets.faceRecognitionNet.loadFromUri(base)]);
              }
              async function startCamera(){
                if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)throw new Error('Camera is not supported by this browser.');
                if(stream)return;
                stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
                video.srcObject=stream;await video.play();
              }
              function stopCamera(){if(stream){stream.getTracks().forEach(function(x){x.stop();});stream=null;}if(video)video.srcObject=null;}

              async function detect(){
                var r=await faceapi.detectAllFaces(video,new faceapi.TinyFaceDetectorOptions({inputSize:parseInt(faceCfg.detector_input_size,10)||320,scoreThreshold:parseFloat(faceCfg.detector_score_threshold)||.35}))
                  .withFaceLandmarks().withFaceDescriptors();
                if(r.length===0)throw new Error('No face detected. Center your face and try again.');
                if(r.length>1)throw new Error('More than one face detected. Only one person may be in the camera.');
                return r[0];
              }
              function eyeEAR(p,ids){
                var a=Math.hypot(p[ids[0]].x-p[ids[1]].x,p[ids[0]].y-p[ids[1]].y);
                var b=Math.hypot(p[ids[2]].x-p[ids[3]].x,p[ids[2]].y-p[ids[3]].y);
                var c=Math.hypot(p[ids[4]].x-p[ids[5]].x,p[ids[4]].y-p[ids[5]].y);
                return (b+c)/(2*a||1);
              }
              function metrics(r){
                var p=r.landmarks.positions,l=eyeEAR(p,[36,39,37,41,38,40]),rr=eyeEAR(p,[42,45,43,47,44,46]);
                return {avg:(l+rr)/2,noseX:p[30].x,noseY:p[30].y};
              }

              async function calibratedLiveness(){
                setStatus('Liveness calibration: keep your eyes open and look at the camera…');
                var vals=[],nose0=null;
                for(var i=0;i<(parseInt(faceCfg.baseline_samples,10)||15);i++){
                  var r=await detect(),m=metrics(r);vals.push(m.avg);
                  if(!nose0)nose0={x:m.noseX,y:m.noseY};
                  await new Promise(function(x){setTimeout(x,(parseInt(faceCfg.sample_interval_ms,10)||100));});
                }
                vals.sort(function(a,b){return a-b;});
                var baseline=vals[Math.floor(vals.length*.7)],threshold=baseline*(parseFloat(faceCfg.eye_drop_threshold)||.90);
                setStatus('Liveness: blink once slowly…');
                var blink=false,move=false,closedSince=null,closedMin=1,started=Date.now();
                while(Date.now()-started<(parseInt(faceCfg.challenge_timeout_sec,10)||12)*1000){
                  var r=await detect(),m=metrics(r),e=m.avg;
                  if(Math.abs(m.noseX-nose0.x)>Math.max(parseFloat(faceCfg.head_move_px)||8,video.videoWidth*(parseFloat(faceCfg.head_move_ratio)||.018)))move=true;
                  if(e<threshold){
                    if(closedSince===null)closedSince=Date.now();
                    closedMin=Math.min(closedMin,e);
                  }else if(closedSince!==null){
                    var dur=Date.now()-closedSince;
                    if(dur>=(parseInt(faceCfg.blink_min_ms,10)||60)&&closedMin<baseline*(parseFloat(faceCfg.blink_min_drop)||.94))blink=true;
                    closedSince=null;closedMin=1;
                  }
                  var pct=Math.max(0,Math.min(99,Math.round((1-e/baseline)*100)));
                  setStatus('Liveness: '+(blink?'✓ blink':'blink once')+' · '+(move?'✓ movement':'gently move head')+' · eye change '+pct+'%');
                  if(blink&&move){setStatus('✓ Liveness passed.');return true;}
                  await new Promise(function(x){setTimeout(x,(parseInt(faceCfg.sample_interval_ms,10)||100));});
                }
                if(!blink)throw new Error('Blink was not detected. Make one clear slow blink while looking at the camera.');
                throw new Error('Blink detected. Gently move your head left or right and try again.');
              }

              async function enroll(){
                if(serverEnrolled){setStatus('A face is already enrolled. Verify it or clear the existing enrollment first.');return;}
                if(busy||!ready)return;busy=true;setStatus('Enrollment: sample 1 of '+SAMPLE_COUNT);
                try{
                  var ds=[];
                  for(var i=0;i<SAMPLE_COUNT;i++){var r=await detect();ds.push(Array.from(r.descriptor));
                    if(i<SAMPLE_COUNT-1){setStatus('Enrollment: sample '+(i+1)+' captured. Slightly change your angle…');await new Promise(function(x){setTimeout(x,(parseInt(faceCfg.enrollment_interval_ms,10)||650));});}}
                  var save=await fetch(apiBase+'face/enroll',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':wpNonce},body:JSON.stringify({template:avg(ds)})});
                  var sj=await save.json();if(!save.ok||!sj.ok)throw new Error(sj.message||'Could not save face enrollment.');
                  serverEnrolled=true;refreshState();setStatus('✓ Face enrollment saved to Workforce One.');
                }catch(e){setStatus(e.message||'Enrollment failed.');}finally{busy=false;}
              }

              async function verify(){
                if(busy||!ready)return;
                var p=profile();if(!p||!p.template){setStatus('No face is enrolled. Press Enroll Face first.');return;}
                busy=true;
                try{
                  await calibratedLiveness();
                  var r=await detect();
                  var vr=await fetch(apiBase+'face/verify',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':wpNonce},body:JSON.stringify({template:Array.from(r.descriptor)})});
                  var vj=await vr.json();if(!vr.ok)throw new Error(vj.message||'Face verification failed.');
                  var d=parseFloat(vj.distance||99);
                  if(vj.ok){
                    setStatus('✓ Face verified.');
                    if(pendingForm){
                      var hidden=pendingForm.querySelector('input[name="face_verified"]');
                      if(hidden)hidden.value='1';
                      var f=pendingForm;pendingForm=null;
                      setTimeout(function(){if(f&&f.submit)f.submit();},250);
                    }
                  }else{pendingForm=null;setStatus('✕ Face did not match — distance '+d.toFixed(3)+'.');}
                }catch(e){setStatus(e.message||'Verification failed.');}
                finally{busy=false;}
              }

              async function openModal(){
                modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');setStatus('Loading face model…');
                try{await loadModels();ready=true;await startCamera();setStatus(profile()?'Ready. Verify your enrolled face.':'Ready. Enroll your face first.');}
                catch(e){setStatus(e.message||'Unable to initialize camera.');}
              }
              function closeModal(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');stopCamera();busy=false;pendingForm=null;}
              async function requestReset(){
                if(busy)return;
                if(!serverEnrolled){setStatus('No face enrollment to reset.');return;}
                if(!confirm('Request a face reset? Your current enrollment will remain active until an administrator approves the reset.'))return;
                busy=true;setStatus('Submitting face reset request…');
                try{
                  var rr=await fetch(apiBase+'face/reset-request',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':wpNonce},body:'{}'});
                  var jj=await rr.json();if(!rr.ok||!jj.ok)throw new Error(jj.message||'Could not submit reset request.');
                  setStatus('✓ Reset request submitted. Your current face enrollment is still active.');
                }catch(e){setStatus(e.message||'Could not submit reset request.');}
                finally{busy=false;}
              }

              refreshState();
              module.querySelector('#ews-face-open').addEventListener('click',openModal);
              module.querySelector('#ews-face-close').addEventListener('click',closeModal);
              module.querySelector('#ews-face-enroll').addEventListener('click',enroll);
              module.querySelector('#ews-face-verify').addEventListener('click',verify);
              module.querySelector('#ews-face-reset').addEventListener('click',requestReset);

              document.querySelectorAll('.ews-face-signin-form').forEach(function(form){
                form.addEventListener('submit',function(e){
                  if(!faceRequired)return;
                  var hidden=form.querySelector('input[name="face_verified"]');
                  if(hidden&&hidden.value==='1')return;
                  e.preventDefault();
                  pendingForm=form;
                  openModal();
                });
              });
              modal.addEventListener('click',function(e){if(e.target===modal)closeModal();});
              refreshState();
            
    module.dataset.ewsFaceReady='1';
  }
  function initAll(){document.querySelectorAll('.ews-face-module').forEach(initFaceModule);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initAll);else initAll();
  new MutationObserver(initAll).observe(document.body,{childList:true,subtree:true});
})();


/* Workforce One: iOS-safe CSV export for Reports.
 * Keep the original anchor navigation on desktop/Android. On iOS/iPadOS,
 * intercept the link and use the native Share Sheet so the CSV document
 * viewer does not replace the PWA/app page.
 */
(function(){
    function isIosDevice(){
        var ua=navigator.userAgent||'';
        return /iPad|iPhone|iPod/.test(ua) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1);
    }
    function initEwsReportCsvExport(){
        if(!isIosDevice()) return;
        document.querySelectorAll('[data-ews-csv-export="1"]').forEach(function(link){
            if(link.dataset.ewsCsvReady==='1') return;
            link.dataset.ewsCsvReady='1';
            link.addEventListener('click', async function(e){
                if(!(navigator.share && navigator.canShare)) return;
                e.preventDefault();
                if(link.dataset.ewsCsvBusy==='1') return;
                link.dataset.ewsCsvBusy='1';
                var original=link.innerHTML;
                link.setAttribute('aria-busy','true');
                link.classList.add('is-loading');
                link.innerHTML='↻ &nbsp;Preparing CSV…';
                try{
                    var response=await fetch(link.href,{method:'GET',credentials:'same-origin',cache:'no-store'});
                    if(!response.ok) throw new Error('Could not generate the CSV report.');
                    var blob=await response.blob();
                    var disposition=response.headers.get('Content-Disposition')||'';
                    var match=disposition.match(/filename\*?=(?:UTF-8''|\")?([^\";]+)/i);
                    var filename=match?decodeURIComponent(match[1].replace(/^\"|\"$/g,'')):'workforce-one-report.csv';
                    var file;
                    try{ file=new File([blob],filename,{type:'text/csv'}); }catch(_){ file=null; }
                    if(file && navigator.canShare({files:[file]})){
                        try{
                            await navigator.share({files:[file]});
                            return;
                        }catch(err){
                            if(err && err.name==='AbortError') return;
                            /* If iOS cannot share the file, restore the original
                             * link behavior rather than trapping the user. */
                            window.location.href=link.href;
                            return;
                        }
                    }
                    /* Older iOS versions without file sharing: preserve the
                     * original browser behavior. */
                    window.location.href=link.href;
                }catch(err){
                    alert(err&&err.message?err.message:'Could not export the CSV report.');
                }finally{
                    link.dataset.ewsCsvBusy='';
                    link.removeAttribute('aria-busy');
                    link.classList.remove('is-loading');
                    link.innerHTML=original;
                }
            });
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initEwsReportCsvExport);
    else initEwsReportCsvExport();
})();

/* Workforce One: iOS-safe Excel export. */
(function(){
    function isIosDevice(){
        var ua=navigator.userAgent||'';
        return /iPad|iPhone|iPod/.test(ua) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1);
    }
    function initEwsReportXlsxExport(){
        if(!isIosDevice()) return;
        document.querySelectorAll('[data-ews-xlsx-export="1"]').forEach(function(link){
            if(link.dataset.ewsXlsxReady==='1') return;
            link.dataset.ewsXlsxReady='1';
            link.addEventListener('click', async function(e){
                if(!(navigator.share && navigator.canShare)) return;
                e.preventDefault();
                if(link.dataset.ewsXlsxBusy==='1') return;
                link.dataset.ewsXlsxBusy='1';
                var original=link.innerHTML;
                link.setAttribute('aria-busy','true');
                link.classList.add('is-loading');
                link.innerHTML='↻ &nbsp;Preparing Excel…';
                try{
                    var response=await fetch(link.href,{method:'GET',credentials:'same-origin',cache:'no-store'});
                    if(!response.ok) throw new Error('Could not generate the Excel report.');
                    var blob=await response.blob();
                    var disposition=response.headers.get('Content-Disposition')||'';
                    var match=disposition.match(/filename\*?=(?:UTF-8''|\")?([^\";]+)/i);
                    var filename=match?decodeURIComponent(match[1].replace(/^\"|\"$/g,'')):'workforce-one-report.xlsx';
                    var file;
                    try{ file=new File([blob],filename,{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}); }catch(_){ file=null; }
                    if(file && navigator.canShare({files:[file]})){
                        try{
                            await navigator.share({files:[file]});
                            return;
                        }catch(err){
                            if(err && err.name==='AbortError') return;
                            window.location.href=link.href;
                            return;
                        }
                    }
                    window.location.href=link.href;
                }catch(err){
                    alert(err&&err.message?err.message:'Could not export the Excel report.');
                }finally{
                    link.dataset.ewsXlsxBusy='';
                    link.removeAttribute('aria-busy');
                    link.classList.remove('is-loading');
                    link.innerHTML=original;
                }
            });
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initEwsReportXlsxExport);
    else initEwsReportXlsxExport();
})();
