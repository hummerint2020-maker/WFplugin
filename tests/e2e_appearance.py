"""Behaviour tests for 3.31.48: wp-admin → Appearance (theme, contrast checks, font, reset, access)
and the app frame that uses it (menu rail, header, the phone's bottom bar with Sign In / Out in the
middle and its "More" sheet).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_appearance.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, os, re, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

A = '/wp-admin/admin.php?page=ews31-appearance'
APP = '/app/'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("delete_option('ews_appearance'); delete_option('ews_frontend_navigation'); $wpdb->query(\"DELETE FROM {$p}ews_audit_log\");")


def form_nonce(sess, action):
    st, page, _ = sess.req(A)
    for form in re.findall(r'<form.*?</form>', page, re.S):
        if 'value="%s"' % action in form:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
            if m:
                return m.group(1), page
    return None, page


def theme_css(page):
    """The inline theme added to the app's frame stylesheet."""
    m = re.search(r"<style id=[\"']workforce-one-shell-inline-css[\"'][^>]*>(.*?)</style>", page, re.S)
    return m.group(1) if m else ''


def tabbar(page):
    m = re.search(r'<nav class="wfo-tabbar".*?</nav>', page, re.S)
    return m.group(0) if m else ''


seed()
I = ids()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- defaults in the app
st, page, _ = emp.req(APP)
css = theme_css(page)
check('the app loads the frame stylesheet and the theme', 'app-shell.css' in page and '--wfo-pri:#5B3FD9;' in css, css[:200])
check('...with the bundled Alexandria font, nothing from other sites', 'assets/fonts/alexandria/alexandria-arabic.woff2' in css and 'fonts.googleapis' not in page and 'fonts.gstatic' not in page)
check('the brand comes from Appearance (built-in names)', 'BA Team · Workforce One' in html.unescape(page))
frame = re.search(r'<aside class="wfo-rail".*?</aside>', page, re.S).group(0) + tabbar(page) + re.search(r'<button type="button" class="ews-notification-bell".*?</button>', page, re.S).group(0)
check('menu and bell icons are SVG, not emoji', frame.count('<svg class="wfo-icon"') >= 8 and not re.search('[\U0001F300-\U0001FAFF\u2600-\u27BF]', frame), re.findall('[\U0001F300-\U0001FAFF\u2600-\u27BF]', frame))

bar = tabbar(page)
check('the phone bar has Sign In / Out as the middle button', 'wfo-tab-clock' in bar and 'ews_view=time' in bar, bar[:300])
check('...with a "not signed in yet" dot before Sign In', 'wfo-clock-dot is-out' in bar)
check('...and a More button that opens the sheet', 'data-wfo-more-open' in bar and '<dialog class="wfo-more" id="wfo-more"' in page)
order = re.findall(r'ews_view=([a-z-]+)', bar)
check('bar order: two items, the middle button, one item (employee: Dashboard, Schedule | Sign In / Out | Leave)', order == ['dashboard', 'schedule', 'time', 'vacation'], order)
php("$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'work_date'=>current_time('Y-m-d'),'event_type'=>'sign_in','event_at'=>current_time('mysql')]);" % I['eid'])
st, page, _ = emp.req(APP + '?ews_view=time')
bar = tabbar(page)
check('after Sign In the dot says signed in, and the middle button is the active page', 'wfo-clock-dot is-in' in bar and re.search(r'class="wfo-tab wfo-tab-clock active"', bar) is not None, bar[:400])

st, page, _ = adm.req(APP)
bar, more = tabbar(page), re.search(r'<dialog class="wfo-more".*?</dialog>', page, re.S).group(0)
check('a manager: menu items beyond the bar are in the More sheet', set(re.findall(r'ews_view=([a-z-]+)', more)) >= {'attendance', 'people', 'reports'} and 'ews_view=attendance' not in bar, (re.findall(r'ews_view=([a-z-]+)', bar), re.findall(r'ews_view=([a-z-]+)', more)))
check('the More sheet has My Profile and Log out', 'ews_view=profile' in more and 'action=logout' in more)
st, page, _ = adm.req(APP + '?ews_view=reports')
check('a page inside More marks More as active', re.search(r'<button type="button" class="wfo-tab active" data-wfo-more-open', page) is not None)
php("update_option('ews_frontend_navigation',['time'=>['mobile_visible'=>0]],false);")
st, page, _ = emp.req(APP)
check('Sign In / Out hidden on mobile in View Navigation: no middle button', 'wfo-tab-clock' not in tabbar(page) and 'ews_view=time' not in tabbar(page))
php("delete_option('ews_frontend_navigation');")

# ---------------------------------------------------------------- the admin page
st, page, _ = emp.req(A)
check('an employee cannot open Appearance', st in (302, 403, 500) or 'Access denied' in page or 'not allowed' in page, st)
st, page, _ = adm.req(A)
check('the admin page lists the four themes and the five fonts', all('value="%s"' % k in page for k in ['indigo', 'nile', 'royal', 'sunset', 'custom', 'alexandria', 'cairo', 'plex', 'tajawal', 'system']), st)
check('...with a preview and the admin script', 'id="wfo-appearance-preview"' in page and 'admin-appearance.js' in page and 'admin-appearance.css' in page)

n, _ = form_nonce(adm, 'ews31_appearance_save')
qs = adm.post('ews31_appearance_save', _wpnonce=n, preset='nile', preset_applied=1, header_style='gradient', corners='soft', company_name='Nile Co', app_name='Nile Hub', tagline='', logo_url='https://example.com/logo.svg')[1]
saved = php("echo wp_json_encode(get_option('ews_appearance'));")
check('saving a theme stores its colours and font', qs.get('appearance_notice') == 'saved' and '"preset":"nile"' in saved and '"primary":"#0F7C70"' in saved and '"font":"cairo"' in saved and '"corners":"soft"' in saved, (qs, saved))
st, page, _ = emp.req(APP)
css = theme_css(page)
check('the app uses it: colours, Cairo font, soft corners', '--wfo-pri:#0F7C70;' in css and 'assets/fonts/cairo/cairo-arabic.woff2' in css and '--wfo-r-card:12px;' in css and 'alexandria' not in css, css[:300])
check('...and the brand, logo; an empty tagline is not shown', 'Nile Co · Nile Hub' in html.unescape(page) and 'src="https://example.com/logo.svg"' in page and 'class="ews-sub"' not in page)
check('older pages follow the theme colour (--purple)', '--purple:#0F7C70;' in css)

n, _ = form_nonce(adm, 'ews31_appearance_save')
adm.post('ews31_appearance_save', _wpnonce=n, preset='custom', header_style='gradient', header_start='#FFF8E1', header_end='#11796C', highlight='#F2C14E', primary='#cccccc', font='cairo', corners='soft', company_name='Nile Co', app_name='Nile Hub')
saved = php("echo wp_json_encode(get_option('ews_appearance'));")
st, page, _ = adm.req(A + '&appearance_notice=saved')
check('unreadable colours are refused and the previous ones kept', '"header_start":"#0B3B3A"' in saved and '"primary":"#0F7C70"' in saved, saved)
check('...and the page says why, once', 'too light for white text' in page and 'too light to read on white' in page, re.findall(r'notice-error"><p>([^<]*)', page))
st, page, _ = adm.req(A)
check('the warning is not repeated on the next visit', 'too light for white text' not in page)

n, _ = form_nonce(adm, 'ews31_appearance_save')
adm.post('ews31_appearance_save', _wpnonce=n, preset='custom', header_style='solid', header_start='#1F2937', header_end='#FFFFFF', highlight='#FDE68A', primary='#1D4ED8', font='system', corners='sharp', company_name='', app_name='Hub', tagline='Hi', logo_url='javascript:alert(1)')
saved = php("echo wp_json_encode(get_option('ews_appearance'));")
st, page, _ = emp.req(APP)
css = theme_css(page)
check('custom colours with a solid header; the device font loads no font file', '"preset":"custom"' in saved and '--wfo-hero:#1F2937;' in css and '.woff2' not in css and '--wfo-r-card:6px;' in css, (saved, css[:200]))
check('a javascript: logo URL is dropped', '"logo_url":""' in saved and 'javascript:' not in page)

st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-settings-overview')
row = re.search(r'ews_appearance.*?</tr>', page, re.S)
check('Settings Overview lists Appearance as changed', row is not None and 'Custom colours' in row.group(0), row.group(0)[:300] if row else page[:200])

st, _, _ = emp.req('/wp-admin/admin-post.php', {'action': 'ews31_appearance_save', '_wpnonce': n, 'preset': 'royal', 'preset_applied': 1})
check('an employee cannot save Appearance', '"preset":"custom"' in php("echo wp_json_encode(get_option('ews_appearance'));"), st)

n, _ = form_nonce(adm, 'ews31_appearance_reset')
qs = adm.post('ews31_appearance_reset', _wpnonce=n)[1]
st, page, _ = emp.req(APP)
check('reset brings back the built-in look', qs.get('appearance_notice') == 'reset' and php("var_export(get_option('ews_appearance',null));") == 'NULL' and '--wfo-pri:#5B3FD9;' in theme_css(page))
audits = q("SELECT details FROM {p}ews_audit_log WHERE action='appearance_update'")
check('each save and the reset are in the Audit Log', len(audits) == 4, audits)

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
