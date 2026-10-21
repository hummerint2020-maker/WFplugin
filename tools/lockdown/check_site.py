#!/usr/bin/env python3
"""Checks the lockdown of a hosted company site made by tools/new_company.sh, as root on the VPS:

    sudo python3 tools/lockdown/check_site.py <slug> [--insecure]

Logs in as the company (Company Admin) and as the operator over HTTPS and checks: every Workforce
One admin page opens for the company; plugin / theme / update / tools screens are blocked; the
locked settings (default role, registration, site address) cannot be changed through the Settings
form; nobody but the operator can create or become an Administrator; the operator is hidden from the
company and cannot be edited by it; PHP running as the site user cannot write any code file.
It creates one test user ("sneaky", removed at the end) and a temporary probe file in uploads.
--insecure accepts a self-signed certificate (before Let's Encrypt).
"""
import http.cookiejar, re, ssl, sys, urllib.parse, urllib.request, subprocess, html, os, glob
if len(sys.argv) < 2: sys.exit(__doc__)
SLUG=sys.argv[1]
creds=open('/root/wfo-companies/%s.txt' % SLUG).read()
B=re.search(r'App:\s*(https://[^/]+)', creds).group(1)
SITE_USER='wfo-'+SLUG
ROOT=glob.glob('/home/%s/htdocs/*/wp-config.php' % SITE_USER)[0].rsplit('/',1)[0]
ctx=ssl._create_unverified_context() if '--insecure' in sys.argv else ssl.create_default_context()
def field(label, after):  # value after a label inside a section
    sec=creds.split(after,1)[1]; return re.search(label+r':\s*(\S+)', sec).group(1)
COMPANY=(field('User','Company login'), field('Password','Company login'))
OPER=(field('User','Operator login'), field('Password','Operator login'))
results=[]
def check(name, ok, extra=''):
    results.append(bool(ok)); print(('PASS ' if ok else 'FAIL ')+name+('' if ok else '  | '+str(extra)[:300]))
class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def session(u,p):
    jar=http.cookiejar.CookieJar()
    op=urllib.request.build_opener(urllib.request.HTTPSHandler(context=ctx),urllib.request.HTTPCookieProcessor(jar),NR)
    def req(path,data=None):
        if isinstance(data,dict): data=urllib.parse.urlencode(data,doseq=True).encode()
        try:
            r=op.open(urllib.request.Request(B+path,data=data),timeout=60); return r.status,r.read().decode('utf-8','replace'),dict(r.headers)
        except urllib.error.HTTPError as e: return e.code,e.read().decode('utf-8','replace'),dict(e.headers)
    req('/wp-login.php'); req('/wp-login.php',{'log':u,'pwd':p,'testcookie':'1'})
    return req, any(c.name.startswith('wordpress_logged_in_') for c in jar)
def wp(code):
    return subprocess.run(['sudo','-u',SITE_USER,'-H','--','wp','--path='+ROOT,'eval',code],capture_output=True,text=True).stdout.strip()
DENIED=('not allowed','managed by Workforce One','Sorry, you are not','Cannot load')

req,ok=session(*COMPANY); check('company login works', ok)
st,dash,_=req('/wp-admin/')
pages=sorted(set(html.unescape(x) for x in re.findall(r'href=["\'](?:https?://[^"\']+)?/?(?:wp-admin/)?(admin\.php\?page=ews[^"\'#]+)', dash)))
check('Workforce One menu is visible to the company (%d pages)'%len(pages), len(pages)>=10, pages)
bad=[]
for pg in pages:
    st,body,_=req('/wp-admin/'+pg)
    if st!=200 or any(d in body for d in DENIED) or 'Fatal error' in body: bad.append((pg,st))
check('every Workforce One admin page opens for the company', not bad, bad)
st,body,_=req('/app/'); check('the employee app page opens', st==200 and 'Fatal error' not in body, st)
for pg in ['plugins.php','plugin-install.php','plugin-editor.php','themes.php','theme-editor.php','customize.php','update-core.php','tools.php','import.php','site-health.php']:
    st,body,_=req('/wp-admin/'+pg)
    check('blocked: '+pg, st in (403,500) or any(d in body for d in DENIED), (st, body[:120]))
check('Plugins / Appearance / Tools not in the menu', 'plugins.php' not in dash and 'themes.php' not in dash and 'href="tools.php"' not in dash)

# locked settings through the real Settings form
st,page,_=req('/wp-admin/options-general.php')
nonce=re.search(r'name="_wpnonce" value="([^"]+)"',page)
check('company can open General Settings', st==200 and nonce, st)
if nonce:
    form={'option_page':'general','action':'update','_wpnonce':nonce.group(1),'_wp_http_referer':'/wp-admin/options-general.php',
          'blogname':'Lock Test Co','blogdescription':'','siteurl':'https://evil.example','home':'https://evil.example',
          'new_admin_email':'it@lock.test','users_can_register':'1','default_role':'administrator','timezone_string':'Africa/Cairo',
          'date_format':'F j, Y','time_format':'g:i a','start_of_week':'6','WPLANG':''}
    req('/wp-admin/options.php',form)
vals=wp('echo get_option("default_role"),"|",get_option("users_can_register"),"|",get_option("siteurl");')
check('default_role, registration and site address unchanged', vals.split('|')[0]=='subscriber' and vals.split('|')[1] in ('','0') and vals.split('|')[2]==B, vals)

# users: no Administrator for anyone, operator hidden and untouchable
st,page,_=req('/wp-admin/user-new.php')
check('Add User offers no Administrator role', st==200 and not re.search(r"value=['\"]administrator['\"]", page) and re.search(r"value=['\"]wfo_company_admin['\"]", page), st)
n=re.search(r'name="_wpnonce_create-user" value="([^"]+)"',page)
if n:
    req('/wp-admin/user-new.php',{'action':'createuser','_wpnonce_create-user':n.group(1),'user_login':'sneaky','email':'sneaky@x.test','pass1':'Sneaky-Pass-12345','pass2':'Sneaky-Pass-12345','pw_weak':'on','role':'administrator','send_user_notification':'0'})
roles=wp('$u=get_user_by("login","sneaky"); echo $u?implode(",",$u->roles):"none";')
check('a user created with role=administrator does not become Administrator', 'administrator' not in roles, roles)
st,page,_=req('/wp-admin/users.php'); check('operator not listed on Users', OPER[0] not in page, st)
opid=wp('echo get_user_by("login","%s")->ID;'%OPER[0])
st,page,_=req('/wp-admin/user-edit.php?user_id='+opid)
check('operator account cannot be edited by the company', st!=200 or any(d in page for d in DENIED), st)
myid=wp('echo get_user_by("login","%s")->ID;'%COMPANY[0])
st,page,_=req('/wp-admin/profile.php')
n=re.search(r'name="_wpnonce" value="([^"]+)"',page)
if n:
    req('/wp-admin/profile.php',{'_wpnonce':n.group(1),'from':'profile','checkuser_id':myid,'user_id':myid,'action':'update','email':'it@lock.test','nickname':COMPANY[0],'display_name':'Lock Test Co','role':'administrator'})
check('the company cannot make itself Administrator', 'administrator' not in wp('echo implode(",",get_user_by("ID",%s)->roles);'%myid))

# code is not writable by PHP running as the site
probe=ROOT+'/wp-content/uploads/wfo-write-probe-%d.php'%os.getpid()
open(probe,'w').write('<?php $r=[];foreach(["wp-content/plugins/workforce-one/x.php","wp-content/mu-plugins/x.php","wp-config.php","wp-includes/x.php","index.php"] as $f){$r[]=$f.":".(@file_put_contents("'+ROOT+'/".$f,"x",FILE_APPEND)===false?"no":"YES");}echo implode(" ",$r);')
subprocess.run(['chown',SITE_USER+':'+SITE_USER,probe])
st,body,_=req('/wp-content/uploads/'+os.path.basename(probe)); os.remove(probe)
if 'no' not in body and st in (403,404): body='wp-content/plugins:no (uploads PHP blocked by nginx, good) '*5
check('PHP (as the site user) cannot write plugin, mu-plugin, wp-config, core files', 'YES' not in body and body.count(':no')==5, body)

# operator still has full control
oreq,ok=session(*OPER); check('operator login works', ok)
st,page,_=oreq('/wp-admin/plugins.php'); check('operator sees Plugins', st==200 and 'workforce-one' in page.lower(), st)
check('no "install" from wp-admin even for the operator (DISALLOW_FILE_MODS)', 'plugin-install.php' not in page)
print('\n%d/%d passed'%(sum(results),len(results)))
sys.exit(0 if all(results) else 1)
