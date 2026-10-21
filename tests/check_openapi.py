"""Checks docs/api/openapi.yaml against the code (no dependencies but PyYAML):

- every native v1 route in workforce-one/src/Api/Routes.php is in the spec, and every path + method in the spec is a route;
- the ErrorCode enum is the ErrorMap constants, with the same HTTP statuses;
- every operation has a 200 response, and every token-authenticated one a 401;
- every example (responses and request bodies) validates against its schema, with a small JSON Schema subset validator
  (type incl. lists, const, enum, pattern, format date / date-time, required, properties, additionalProperties, items, oneOf, $ref).

Other tests import validate_response() to check real API bodies against the same schemas.
Usage: python3 tests/check_openapi.py
"""
import datetime, os, re, sys

import yaml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SPEC = os.path.join(ROOT, 'docs', 'api', 'openapi.yaml')


def load():
    with open(SPEC, encoding='utf-8') as f:
        return yaml.safe_load(f)


def resolve(spec, node):
    while isinstance(node, dict) and '$ref' in node:
        ref = node['$ref']
        assert ref.startswith('#/'), ref
        cur = spec
        for part in ref[2:].split('/'):
            cur = cur[part]
        node = cur
    return node


_DATE = re.compile(r'^\d{4}-\d{2}-\d{2}$')
_DT = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$')


def errors(spec, schema, value, path='$'):
    """The list of problems of a value against a schema (empty = valid)."""
    schema = resolve(spec, schema)
    out = []
    if 'oneOf' in schema:
        tried = [errors(spec, s, value, path) for s in schema['oneOf']]
        ok = [t for t in tried if not t]
        if len(ok) != 1:
            out.append('%s: matches %d of oneOf (want exactly 1): %s' % (path, len(ok), '; '.join(t[0] for t in tried if t)[:200]))
        return out
    if 'const' in schema and (value != schema['const'] or type(value) is not type(schema['const'])):
        out.append('%s: must be %r' % (path, schema['const']))
    if 'enum' in schema and not any(value == e and type(value) is type(e) for e in schema['enum']):
        out.append('%s: %r not in enum' % (path, value))
    t = schema.get('type')
    if t is not None:
        types = t if isinstance(t, list) else [t]

        def is_type(name):
            if name == 'null': return value is None
            if name == 'boolean': return isinstance(value, bool)
            if name == 'integer': return isinstance(value, int) and not isinstance(value, bool)
            if name == 'number': return isinstance(value, (int, float)) and not isinstance(value, bool)
            if name == 'string': return isinstance(value, str)
            if name == 'array': return isinstance(value, list)
            if name == 'object': return isinstance(value, dict)
            return False
        if not any(is_type(n) for n in types):
            return out + ['%s: %r is not %s' % (path, value, '/'.join(types))]
    if isinstance(value, str):
        if 'pattern' in schema and not re.search(schema['pattern'], value):
            out.append('%s: %r does not match %s' % (path, value, schema['pattern']))
        fmt = schema.get('format')
        if fmt == 'date':
            try:
                if not _DATE.match(value): raise ValueError
                datetime.date.fromisoformat(value)
            except ValueError:
                out.append('%s: %r is not a date' % (path, value))
        elif fmt == 'date-time':
            try:
                if not _DT.match(value): raise ValueError
                datetime.datetime.fromisoformat(value.replace('Z', '+00:00'))
            except ValueError:
                out.append('%s: %r is not an ISO 8601 date-time with an offset' % (path, value))
    if isinstance(value, dict):
        props = schema.get('properties', {})
        for r in schema.get('required', []):
            if r not in value: out.append('%s: missing "%s"' % (path, r))
        for k, v in value.items():
            if k in props: out += errors(spec, props[k], v, path + '.' + k)
            else:
                ap = schema.get('additionalProperties', True)
                if ap is False: out.append('%s: unexpected "%s"' % (path, k))
                elif isinstance(ap, dict): out += errors(spec, ap, v, path + '.' + k)
    if isinstance(value, list) and 'items' in schema:
        for i, v in enumerate(value): out += errors(spec, schema['items'], v, '%s[%d]' % (path, i))
    return out


def operation(spec, path, method):
    return spec['paths'][path][method.lower()]


def validate_response(spec, path, method, status, body):
    """Problems of a real response body against the documented schema (path as in the spec, e.g. '/me/devices/{id}')."""
    resp = operation(spec, path, method)['responses'].get(str(status))
    if resp is None: return ['%s %s: status %s is not documented' % (method, path, status)]
    return errors(spec, resp['content']['application/json']['schema'], body)


def php_routes():
    src = open(os.path.join(ROOT, 'workforce-one', 'src', 'Api', 'Routes.php'), encoding='utf-8').read()
    out = set()
    for m in re.finditer(r"\['path' => '([^']+)', 'methods' => '([A-Z]+)'[^\]]*'kind' => 'native'\]", src):
        path = re.sub(r'\(\?P<(\w+)>[^)]*\)', r'{\1}', m.group(1))
        out.add((m.group(2).lower(), path))
    return out


def main():
    spec = load()
    problems = []
    code = php_routes()
    doc = {(m, p) for p, ops in spec['paths'].items() for m in ops}
    for m, p in sorted(code - doc): problems.append('route in the code but not in the spec: %s %s' % (m.upper(), p))
    for m, p in sorted(doc - code): problems.append('in the spec but not a route: %s %s' % (m.upper(), p))
    em = open(os.path.join(ROOT, 'workforce-one', 'src', 'Api', 'ErrorMap.php'), encoding='utf-8').read()
    consts = re.findall(r"public const ([A-Z_]+) = '\1';", em)
    statuses = {k: int(v) for k, v in re.findall(r"self::([A-Z_]+) => (\d{3})", em)}
    enum = spec['components']['schemas']['ErrorCode']
    if sorted(enum['enum']) != sorted(consts): problems.append('ErrorCode enum differs from ErrorMap: %s' % sorted(set(enum['enum']) ^ set(consts)))
    if enum.get('x-status') != {c: statuses.get(c) for c in consts}: problems.append('ErrorCode x-status differs from ErrorMap::STATUS')
    n_examples = 0
    for p, ops in spec['paths'].items():
        for m, o in ops.items():
            where = '%s %s' % (m.upper(), p)
            if '200' not in o['responses']: problems.append(where + ': no 200 response')
            if o.get('security') and '401' not in o['responses']: problems.append(where + ': token route without a 401 response')
            for st, r in o['responses'].items():
                c = r['content']['application/json']
                n_examples += 1
                for e in errors(spec, c['schema'], c['example']): problems.append('%s %s example: %s' % (where, st, e))
            rb = o.get('requestBody')
            if rb:
                c = rb['content']['application/json']
                n_examples += 1
                for e in errors(spec, c['schema'], c['example']): problems.append('%s request example: %s' % (where, e))
    for p in problems: print('FAIL ' + p)
    print('OK: %d routes, %d examples' % (len(code), n_examples) if not problems else '%d problem(s)' % len(problems))
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
