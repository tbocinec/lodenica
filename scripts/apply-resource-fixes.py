#!/usr/bin/env python3
"""
Apply a reviewed set of name/model corrections to existing resources through
the public API (so they land in the audit log, validated like any admin edit).

Safety, in this order:
  1. Always writes a full JSON backup of every resource first.
  2. DRY-RUN by default: prints the before -> after table and touches nothing.
  3. A row is applied only if the live name+model still equal the `before*`
     values in the mapping (someone edited it meanwhile -> skipped, reported).
  4. Only `name` and `model` are sent — nothing else can change.
  5. After applying, every resource is re-read and compared to the backup:
     any difference outside name/model/updatedAt/displayName is reported.
  6. Nothing is ever deleted.

The mapping is club-specific data, so it lives OUTSIDE the repo:
  { "<identifier>": {"beforeName":…, "beforeModel":…, "name":…, "model":…}, … }

  scripts/apply-resource-fixes.py --base https://test-rezervacie.example \
      --mapping ~/lodenica-backups/resource-cleanup-mapping.json            # dry run
  LODENICA_ADMIN_EMAIL=… LODENICA_ADMIN_PASSWORD=… \
  scripts/apply-resource-fixes.py --base … --mapping … --apply
"""
import argparse, datetime, json, os, ssl, sys, urllib.request

IGNORED_DIFF = {'name', 'model', 'updatedAt', 'displayName', 'photoUrl'}


def call(base, method, path, body=None, token=None, insecure=False):
    req = urllib.request.Request(base.rstrip('/') + path, method=method)
    req.add_header('Accept', 'application/json')
    if body is not None:
        req.data = json.dumps(body).encode()
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', f'Bearer {token}')
    ctx = ssl._create_unverified_context() if insecure else None
    with urllib.request.urlopen(req, context=ctx, timeout=30) as r:
        raw = r.read()
        return json.loads(raw) if raw else None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--base', required=True, help='site root, e.g. https://rezervacie.example')
    ap.add_argument('--mapping', required=True)
    ap.add_argument('--apply', action='store_true', help='really write (default: dry run)')
    ap.add_argument('--backup-dir', default=os.path.expanduser('~/lodenica-backups'))
    ap.add_argument('--insecure', action='store_true', help='skip TLS verification (broken test cert)')
    a = ap.parse_args()
    mapping = json.load(open(os.path.expanduser(a.mapping)))

    items = call(a.base, 'GET', '/api/v1/resources?pageSize=500', insecure=a.insecure)['items']
    by_ident = {r['identifier']: r for r in items}
    os.makedirs(a.backup_dir, exist_ok=True)
    stamp = datetime.datetime.now().strftime('%Y%m%d-%H%M%S')
    host = a.base.split('//')[-1].split('/')[0]
    backup = os.path.join(a.backup_dir, f'resources-{host}-{stamp}.json')
    json.dump(items, open(backup, 'w'), ensure_ascii=False, indent=1)
    print(f'Backup of {len(items)} resources: {backup}')

    plan, skipped = [], []
    for ident, m in mapping.items():
        r = by_ident.get(ident)
        if r is None:
            skipped.append((ident, 'not present on this site')); continue
        if (r['name'], r['model']) == (m['name'], m['model']):
            skipped.append((ident, 'already done')); continue
        if (r['name'], r['model']) != (m['beforeName'], m['beforeModel']):
            skipped.append((ident, f"changed meanwhile: now name={r['name']!r} model={r['model']!r}")); continue
        plan.append((r, m))

    print(f'\n{"ident":9} {"before (name | model)":46} -> after (name | model)')
    for r, m in plan:
        print(f'{r["identifier"]:9} {r["name"]+" | "+str(r["model"]):46} -> {m["name"]} | {m["model"]}')
    for ident, why in skipped:
        print(f'{ident:9} SKIP: {why}')
    print(f'\n{len(plan)} to change, {len(skipped)} skipped, {len(items) - len(plan)} untouched.')

    if not a.apply:
        print('DRY RUN — nothing was written. Re-run with --apply.')
        return 0

    email, pw = os.environ.get('LODENICA_ADMIN_EMAIL'), os.environ.get('LODENICA_ADMIN_PASSWORD')
    if not (email and pw):
        sys.exit('Set LODENICA_ADMIN_EMAIL and LODENICA_ADMIN_PASSWORD for --apply.')
    login = call(a.base, 'POST', '/api/v1/auth/login', {'email': email, 'password': pw}, insecure=a.insecure)
    token = login.get('token')
    if not token:
        sys.exit('Login failed: no token in the response.')

    for r, m in plan:
        call(a.base, 'PATCH', f'/api/v1/resources/{r["id"]}', {'name': m['name'], 'model': m['model']}, token, a.insecure)
        print(f'  updated {r["identifier"]}')

    after = {r['id']: r for r in call(a.base, 'GET', '/api/v1/resources?pageSize=500', insecure=a.insecure)['items']}
    problems = 0
    for old in items:
        new = after.get(old['id'])
        if new is None:
            print(f'!! {old["identifier"]} disappeared'); problems += 1; continue
        for k in old.keys() | new.keys():
            if k not in IGNORED_DIFF and old.get(k) != new.get(k):
                print(f'!! {old["identifier"]}: {k} changed {old.get(k)!r} -> {new.get(k)!r}'); problems += 1
    if len(after) != len(items):
        print(f'!! resource count {len(items)} -> {len(after)}'); problems += 1
    print('VERIFIED: nothing but name/model changed.' if not problems else f'{problems} PROBLEM(S) — see above; backup: {backup}')
    return 1 if problems else 0


if __name__ == '__main__':
    sys.exit(main())
