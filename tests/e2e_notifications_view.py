"""Behaviour tests for the employee app's Notifications page (look A, 3.31.66): grouped by day,
All / Unread, Mark read, Mark all read, opening a notification, the device alerts strip.

Usage: python3 tests/e2e_notifications_view.py <state dir>   (after tests/e2e_setup.php)
"""
import os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results  # noqa: E402

I = ids()
UID = I['uid']
PAGE = '/app/?ews_view=notifications'
php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
other = php("$u=get_user_by('login','admin'); echo (int)$u->ID;")
# Today (two unread, one read), yesterday (read), earlier (read), and one for another user.
php("""
$t=current_time('timestamp');
$add=function($uid,$title,$msg,$type,$entity,$ts,$read)use($wpdb,$p){$wpdb->insert($p.'ews_notifications',['user_id'=>$uid,'title'=>$title,'message'=>$msg,'type'=>$type,'entity'=>$entity,'entity_id'=>null,'is_read'=>$read,'created_at'=>date('Y-m-d H:i:s',$ts)]);};
$add(%(u)d,'Swap request','Layla asks to swap Monday.','info','swap',$t-300,0);
$add(%(u)d,'Leave approved','Your annual leave was <strong>approved</strong>.','success','vacation',$t-3600,0);
$add(%(u)d,'Signed in late','You signed in at 09:24.','warning','break',$t-60,1);
$add(%(u)d,'Next week is ready','Your schedule is published.','info','schedule',strtotime(date('Y-m-d 16:20:00',$t-86400)),1);
$add(%(u)d,'New poll','Team outing: choose a date.','info','poll',$t-6*86400,1);
$add(%(o)s,'Not yours','Admin only.','info','employee',$t-100,0);
""" % {'u': UID, 'o': other})

emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req(PAGE)
titles = re.findall(r'<span class="wfo-notif-title">([^<]+)', page)
groups = re.findall(r'<section class="wfo-notif-group" aria-label="([^"]+)"', page)
check('the page opens with the new look and its own styles and script', st == 200 and 'wfo-notif' in page and 'app-notifications.css' in page and 'notifications.js' in page, st)
check('grouped Today, Yesterday, Earlier, newest first', groups == ['Today', 'Yesterday', 'Earlier'] and titles == ['Signed in late', 'Swap request', 'Leave approved', 'Next week is ready', 'New poll'], (groups, titles))
check('only my notifications', 'Not yours' not in page)
check('unread ones are marked and counted', page.count('wfo-notif-item unread is-unread') == 2 and re.search(r'class="wfo-notif-count">2<', page) is not None)
check('each kind has its icon colour', 'wfo-notif-icon is-away' in page and 'wfo-notif-icon is-leave' in page and 'wfo-notif-icon is-wfh' in page and 'wfo-notif-icon is-pri' in page and 'wfo-notif-icon is-teal' in page)
check('messages keep their simple formatting', 'was <strong>approved</strong>' in page)
check('times read as "ago" today and "Yesterday · time" after', re.search(r'class="wfo-notif-when">[^<]*ago<', page) is not None and 'Yesterday · ' in page)
check('the alerts strip is there for the script (hidden until it knows)', 'id="ews-notification-push-settings"' in page and 'data-push-nonce="' in page and 'hidden>' in page)
check('no emoji and no explanation blurb', '🔔' not in page and 'Stay updated with your latest alerts' not in page)

st, page, _ = emp.req(PAGE + '&notification_tab=unread')
titles = re.findall(r'<span class="wfo-notif-title">([^<]+)', page)
check('Unread shows only the unread ones', titles == ['Swap request', 'Leave approved'], titles)

# Mark one read with the dot button.
nid = int(q("SELECT id FROM {p}ews_notifications WHERE title='Swap request'")[0]['id'])
m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="ews_notification_read">\s*<input type="hidden" name="notification_id" value="%d">' % nid, page)
emp.post('ews_notification_read', _wpnonce=m.group(1) if m else '', notification_id=nid)
check('the dot marks one notification read', q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % nid)[0]['is_read'] in ('1', 1), m is not None)

# Opening a notification marks it read and goes on.
st, page, _ = emp.req(PAGE)
link = re.search(r'<a class="wfo-notif-link" href="([^"]+)">\s*<span class="wfo-notif-icon is-leave">', page)
path = link.group(1).replace('&#038;', '&').replace('&amp;', '&').split('127.0.0.1:8080')[-1] if link else ''
st, _, h = emp.req(path)
lid = int(q("SELECT id FROM {p}ews_notifications WHERE title='Leave approved'")[0]['id'])
check('opening a notification marks it read and moves on', st in (301, 302, 303) and q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % lid)[0]['is_read'] in ('1', 1), (st, h.get('Location')))

php("$wpdb->query($wpdb->prepare(\"UPDATE {$p}ews_notifications SET is_read=0 WHERE user_id=%%d\",%d));" % UID)
st, page, _ = emp.req(PAGE)
m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="ews_notification_read_all">', page)
emp.post('ews_notification_read_all', _wpnonce=m.group(1) if m else '')
left = q("SELECT COUNT(*) AS n FROM {p}ews_notifications WHERE user_id=%d AND is_read=0" % UID)[0]['n']
check('Mark all read clears mine (not other people\'s)', str(left) == '0' and str(q("SELECT is_read FROM {p}ews_notifications WHERE title='Not yours'")[0]['is_read']) == '0', left)
st, page, _ = emp.req(PAGE + '&notification_tab=unread')
check('...then Unread says you are all caught up, with no Mark all read', ("You&#039;re all caught up." in page or "You're all caught up." in page) and 'ews_notification_read_all' not in page)

php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
st, page, _ = emp.req(PAGE)
check('no notifications: a friendly empty state', 'No notifications yet.' in page and 'wfo-notif-group' not in page)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
