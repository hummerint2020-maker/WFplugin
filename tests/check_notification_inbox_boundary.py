"""Enforce the extracted inbox boundary, alongside the legacy trait dependency ratchet.

This intentionally covers the inbox slice only; it does not claim all Notifications is extracted.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'workforce-one/src/Notifications'
errors = []

# Function calls: a leading backslash (\\get_option) is the same call; a namespaced name (Foo\\bar) is kept and reported.
CALL = re.compile(r'(?<![\w>:\\])\\?((?:[A-Za-z_]\w*\\)*[A-Za-z_]\w*)\s*\(')
# The inbox table in the legacy trait; ews_notifications_cleanup (the cron hook) is not it.
LEGACY_SQL = re.compile(r'ews_notifications(?!\w)|\$this->notifications\b')

def calls_in(text):
    return set(CALL.findall(text))

# Self-test, so a loosened pattern cannot pass silently.
assert calls_in('\\get_option("x");') == {'get_option'}
assert calls_in('Foo\\bar();') == {'Foo\\bar'}
assert calls_in('$this->x(); self::y(); Store::z();') == set()
assert LEGACY_SQL.search('{$wpdb->prefix}ews_notifications WHERE')
assert not LEGACY_SQL.search("wp_schedule_event(time(),'daily','ews_notifications_cleanup')")

def source(path):
    text = path.read_text(encoding='utf-8')
    return re.sub(r'/\*.*?\*/|//[^\n]*', '', text, flags=re.S)

for name in ('Inbox.php', 'InboxStore.php'):
    text = source(MODULE / name)
    # Pure use cases may only call their contract, own methods and PHP built-ins.
    allowed = {'defined', 'exit', 'array_key_exists', 'max', 'min', 'abs', 'unset'}
    calls = calls_in(text)
    declarations = set(re.findall(r'function\s+(\w+)\s*\(', text))
    unknown = calls - declarations - allowed - {'if', 'return'}
    if unknown:
        errors.append(f'{name}: unexpected external calls: {sorted(unknown)}')
    if re.search(r'\b(global|wpdb|EWS_Manager_V31_1)\b|\$_(?:GET|POST|SERVER|COOKIE)|\b(?:require|include)(?:_once)?\b', text):
        errors.append(f'{name}: framework or transport dependency')
    if re.search(r'^\s*use\s+', text, re.M):
        errors.append(f'{name}: dependency outside Notifications')

for name in ('WordPressInboxStore.php', 'InboxSchema.php'):
    text = source(MODULE / name)
    if re.search(r'\bEWS_\w+|\$this->plugin\b|^\s*use\s+', text, re.M):
        errors.append(f'{name}: dependency on shared plugin or another module')

legacy = source(ROOT / 'workforce-one/includes/trait-notifications.php')
if LEGACY_SQL.search(legacy):
    errors.append('legacy Notifications trait owns inbox SQL again')
if errors:
    raise SystemExit('\n'.join(errors))
print('PASS extracted Notifications inbox boundary (pure service, SQL adapter, legacy bridge)')
