"""Enforce the extracted inbox boundary, alongside the legacy trait dependency ratchet.

This intentionally covers the inbox slice only; it does not claim all Notifications is extracted.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / 'workforce-one/src/Notifications'
errors = []

def source(path):
    text = path.read_text(encoding='utf-8')
    return re.sub(r'/\*.*?\*/|//[^\n]*', '', text, flags=re.S)

for name in ('Inbox.php', 'InboxStore.php'):
    text = source(MODULE / name)
    # Pure use cases may only call their contract, own methods and PHP built-ins.
    allowed = {'defined', 'exit', 'array_key_exists', 'max', 'min', 'abs', 'unset'}
    calls = set(re.findall(r'(?<![\w>:\\])([a-zA-Z_]\w*)\s*\(', text))
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
if re.search(r'ews_notifications|\$this->notifications\b', legacy):
    errors.append('legacy Notifications trait owns inbox SQL again')
if errors:
    raise SystemExit('\n'.join(errors))
print('PASS extracted Notifications inbox boundary (pure service, SQL adapter, legacy bridge)')
