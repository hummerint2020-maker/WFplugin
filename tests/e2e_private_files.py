"""Private files (3.31.91, second review items 2 and 17): the direct-link check and its warning, new
files kept in WFO_PRIVATE_DIR (older ones moved there and still served), photos never stored by the
browser, and the browser cache cleared when logging out.

This test server (php -S) ignores .htaccess, as Nginx does, so a private file in uploads opens by its
direct link here: exactly the case the warning is for.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_private_files.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import base64, html, json, os, re, shutil, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, S, check, d, ids, php, q, results, wp, HERE  # noqa: E402

PRIV = os.path.join(S, 'wfo-private-test')
MU = "WPMU_PLUGIN_DIR.'/ews-e2e-private-dir.php'"
KEY = 'a' * 28 + 'test.png'
PNG = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                    '1f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082')


def use_private_dir(on):
    if on:
        php("$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents(%s,\"<?php add_filter('wfo_private_dir',function(){return '%s';});\");" % (MU, PRIV))
    else:
        php("@unlink(%s);" % MU)


def check_now():
    out = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'private_files_check'); $r->setAccessible(true); echo wp_json_encode($r->invoke($m));")
    return json.loads(out.splitlines()[-1])


def notice(sess):
    return sess.req('/wp-admin/admin.php?page=ews31-features')[1]


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
use_private_dir(False)
php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections"); $wpdb->query("DELETE FROM {$p}ews_audit_log"); delete_option('ews_private_files_check');
    $u=wp_upload_dir(); $d=$u['basedir'].'/workforce-one-corrections'; foreach((array)glob($d.'/*test.png') as $f) @unlink($f);""")
shutil.rmtree(PRIV, ignore_errors=True)
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- the direct-link check
r = check_now()
check('in uploads, on a server that ignores .htaccess: the check finds files open by direct link', r['status'] == 'exposed' and r['where'] == 'uploads', r)
pg = notice(adm)
check('...an administrator sees the warning on Workforce One pages, with the Nginx rule and WFO_PRIVATE_DIR', 'data-private-files-exposed' in pg and 'deny all;' in pg and 'WFO_PRIVATE_DIR' in pg)
check('...not on other wp-admin pages', 'data-private-files-exposed' not in adm.req('/wp-admin/index.php')[1])
check('...and the check is not repeated on every page (once a week)', php("echo get_option('ews_private_files_check')['at'];").strip() == str(r['at']))
m = re.search(r'href="([^"]*action=ews_private_files_check[^"]*)"', pg)
st, body, h = adm.req(html.unescape(m.group(1)).replace(B, '')) if m else (0, '', {})
# This server answers one request at a time, so the site cannot ask itself for the file during a
# request: the check gets no answer here. An earlier warning must stay.
check('"Check again" runs it again; with no answer the earlier warning stays', re.search(r'wfo_files_checked=(exposed|unknown)', h.get('Location', '')) is not None
      and 'data-private-files-exposed' in notice(adm), h.get('Location'))
st, body, h = emp.req('/wp-admin/admin-post.php?action=ews_private_files_check')
check('...and an employee cannot run it', st in (403, 500) or 'wfo_files_checked' not in h.get('Location', ''), st)

# ---------------------------------------------------------------- WFO_PRIVATE_DIR
# A correction photo saved in uploads before the folder was set.
php("""$u=wp_upload_dir(); $d=$u['basedir'].'/workforce-one-corrections'; wp_mkdir_p($d); file_put_contents($d.'/%s',base64_decode('%s'));
    $wpdb->insert($p.'ews_attendance_corrections',['employee_id'=>%d,'work_date'=>'%s','type'=>'out','status'=>'pending','source'=>'employee','requested_by'=>(int)get_user_by('login','emp1')->ID,'requested_at'=>current_time('mysql'),'photo_key'=>'%s','photo_name'=>'proof.png']);
""" % (KEY, base64.b64encode(PNG).decode(), ids()['eid'], d(-1), KEY))
CID = int(q("SELECT id FROM {p}ews_attendance_corrections")[0]['id'])
use_private_dir(True)
r = check_now()
moved = os.path.isfile(os.path.join(PRIV, 'workforce-one-corrections', KEY))
check('with WFO_PRIVATE_DIR set: the files saved before are moved there', moved and not php("$u=wp_upload_dir(); echo is_file($u['basedir'].'/workforce-one-corrections/%s')?'y':'n';" % KEY).strip().endswith('y'))
check('...the move is in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='private_files_moved'")) == 1)
check('...a folder outside the site needs no check, and the warning is gone', r['status'] == 'outside' and r['where'] == 'custom' and 'data-private-files-exposed' not in notice(adm), r)
check('...with its protective files', os.path.isfile(os.path.join(PRIV, 'workforce-one-corrections', '.htaccess')) and os.path.isfile(os.path.join(PRIV, 'workforce-one-corrections', 'index.php')))

# The photo is still served (from the new folder), never stored by the browser.
# The employee's own nonce, made by WordPress for their session.
jar = [x for x in [hh for hh in emp.op.handlers if hasattr(hh, 'cookiejar')][0].cookiejar if x.name.startswith('wordpress_logged_in_')][0]
nonce = php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user((int)get_user_by('login','emp1')->ID); echo wp_create_nonce('ews_correction_photo_%d');" % (json.dumps(urllib.parse.unquote(jar.value)), CID)).strip()
st, body, h = emp.req('/wp-admin/admin-post.php?action=ews_correction_photo&id=%d&_wpnonce=%s' % (CID, nonce))
check('the photo saved before is still shown to its employee, from the new folder', st == 200 and h.get('Content-Type', '').startswith('image/png'), (st, h.get('Content-Type')))
cc = h.get('Cache-Control', '')
check('...and the browser is told not to keep it (no-store), so it is gone after logout or losing access', 'no-store' in cc and 'max-age=86400' not in cc, cc)

# ---------------------------------------------------------------- logout clears the browser cache
st, pg, _ = emp.req('/wp-login.php?action=logout')
m = re.search(r'href="([^"]*action=logout[^"]*_wpnonce=[^"]*)"', pg)
st, body, h = emp.req(html.unescape(m.group(1)).replace(B, '')) if m else (0, '', {})
check('logging out tells the browser to drop its cached pages and photos (Clear-Site-Data: cache)', h.get('Clear-Site-Data', '') == '"cache"', (st, dict(h)))
check('...and only the cache: the installed app and its push stay (no "storage")', 'storage' not in h.get('Clear-Site-Data', ''))

use_private_dir(False)
shutil.rmtree(PRIV, ignore_errors=True)
php("""$wpdb->query("DELETE FROM {$p}ews_attendance_corrections"); delete_option('ews_private_files_check');""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
