import json,re,time,urllib.request,urllib.parse,http.cookiejar,hashlib,sys
S=sys.argv[1]; ids=json.load(open(S+'/ids.json')); B='http://127.0.0.1:8080'
cj=http.cookiejar.CookieJar()
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj),NoRedir)
def req(url,data=None,headers={},method=None):
    if isinstance(data,dict): data=urllib.parse.urlencode(data).encode()
    r=urllib.request.Request(url if url.startswith('http') else B+url,data=data,headers=headers,method=method)
    try: resp=op.open(r); return resp.status,resp.read().decode(),dict(resp.headers)
    except urllib.error.HTTPError as e: return e.code,e.read().decode(),dict(e.headers)
res=[]
def check(n,c,extra=''): res.append(c); print(('PASS ' if c else 'FAIL ')+n+(' | '+extra if extra and not c else ''))
sec=ids['secret']; kid=ids['kid']
st,body,_=req(f'/?ews_kiosk={kid}&kiosk_key={sec}'); check('kiosk page opens (was always 403)',st==200 and 'qrcode(' in body and 'api.qrserver' not in body,str(st)+body[:200])
st,_,_=req(f'/?ews_kiosk={kid}&kiosk_key={hashlib.sha256(sec.encode()).hexdigest()}'); check('kiosk with hash as key -> 403',st==403)
st,_,_=req(f'/?ews_kiosk={kid}&kiosk_key=bad'); check('kiosk bad key -> 403',st==403)
st,payload,_=req(f'/?ews_kiosk={kid}&kiosk_key={sec}&kiosk_payload=1'); check('payload route',st==200 and payload.startswith(f'wfo1|{kid}|'),payload[:100])
# login
req('/wp-login.php'); st,_,h=req('/wp-login.php',{'log':'emp1','pwd':'emp1pass','wp-submit':'Log In','redirect_to':B+'/app/','testcookie':'1'}); check('login',st==302,str(st))
st,page,_=req('/app/?ews_view=time'); check('time view renders',st==200 and 'Sign In' in page,str(st))
m=re.search(r'id="ews-face-module" data-vendor-base="([^"]+)" data-api-base="([^"]+)" data-wp-nonce="([^"]+)"',page); check('face module attributes intact (quote bug)',bool(m))
vendor,api,rest_nonce=m.groups() if m else ('','','')
st,_,_=req(vendor.replace(B,'')+'models/face_recognition_model-weights_manifest.json'); check('bundled model served',st==200)
def form_nonce(action_value):
    f=re.search(r'<form[^>]*>(?:(?!</form>).)*?name="action" value="'+action_value+r'"(?:(?!</form>).)*</form>',page,re.S)
    if not f:return None
    n=re.search(r'name="_wpnonce" value="([^"]+)"',f.group(0)); return n.group(1) if n else None
n_sign=None
for f in re.findall(r'<form.*?</form>',page,re.S):
    if 'value="ews31_time_event"' in f and 'value="sign_in"' in f: n_sign=re.search(r'name="_wpnonce" value="([^"]+)"',f).group(1)
n_qr=form_nonce('ews_presence_qr_signin'); check('found sign-in + QR nonces',bool(n_sign and n_qr))
now_ms=str(int(time.time()*1000))
inside={'latitude':'30.0445','longitude':'31.2358','accuracy':'20','location_timestamp':now_ms}
# 1. face bypass
st,_,h=req('/wp-admin/admin-post.php',dict(action='ews31_time_event',event_type='sign_in',_wpnonce=n_sign,face_verified='1',**inside))
loc=urllib.parse.unquote(h.get('Location','')); check('face_verified=1 without token is rejected',st==302 and 'Face verification is required' in loc,loc)
# REST face
H={'Content-Type':'application/json','X-WP-Nonce':rest_nonce}
tpl=[0.01*i for i in range(128)]
st,b,_=req(api+'face/enroll',json.dumps({'template':tpl}).encode(),H); check('enroll first time',st==200,b)
st,b,_=req(api+'face/enroll',json.dumps({'template':[0]*128}).encode(),H); check('re-enroll blocked (409)',st==409,b)
st,b,_=req(api+'face/delete',json.dumps({'employee_id':ids['eid']}).encode(),H); check('employee cannot delete own face (403)',st==403,b)
st,b,_=req(api+'face/verify',json.dumps({'template':[x+5 for x in tpl]}).encode(),H); check('non-matching face gets no token',st==200 and 'face_token' not in json.loads(b),b)
def token():
    st,b,_=req(api+'face/verify',json.dumps({'template':tpl}).encode(),H); return json.loads(b).get('face_token')
t=token(); check('matching face gets token',bool(t))
# 2. QR from far away (Alexandria)
far={'latitude':'31.2001','longitude':'29.9187','accuracy':'20','location_timestamp':now_ms}
st,payload,_=req(f'/?ews_kiosk={kid}&kiosk_key={sec}&kiosk_payload=1')
st,_,h=req('/wp-admin/admin-post.php',dict(action='ews_presence_qr_signin',_wpnonce=n_qr,qr_payload=payload,face_token=t,**far))
loc=urllib.parse.unquote(h.get('Location','')); check('forwarded QR used far away is rejected',st==302 and 'You must be at Cairo HQ' in loc,loc)
# 3. old QR
p=payload.split('|'); 
st,_,h=req('/wp-admin/admin-post.php',dict(action='ews_presence_qr_signin',_wpnonce=n_qr,qr_payload='|'.join([p[0],p[1],str(int(p[2])-3),p[3]]),face_token=token(),**inside))
loc=urllib.parse.unquote(h.get('Location','')); check('old/altered QR rejected',st==302 and ('expired' in loc or 'Invalid' in loc),loc)
# 4. reused token
t=token(); req('/wp-admin/admin-post.php',dict(action='ews_presence_qr_signin',_wpnonce=n_qr,qr_payload='bad',face_token=t,**inside))
st,_,h=req('/wp-admin/admin-post.php',dict(action='ews31_time_event',event_type='sign_in',_wpnonce=n_sign,face_token='x'*40,**inside))
loc=urllib.parse.unquote(h.get('Location','')); check('fake token rejected',st==302 and 'Face verification is required' in loc,loc)
# 5. valid QR sign-in (was always failing nonce)
st,payload,_=req(f'/?ews_kiosk={kid}&kiosk_key={sec}&kiosk_payload=1')
st,body,h=req('/wp-admin/admin-post.php',dict(action='ews_presence_qr_signin',_wpnonce=n_qr,qr_payload=payload,face_token=token(),**inside))
loc=urllib.parse.unquote(h.get('Location','')); check('valid QR sign-in succeeds',st==302 and 'time_success' in loc,f'{st} {loc} {body[:300]}')
print(sum(res),'/',len(res))
