"""Build the deploy zip: backupinfo<version>.zip with a top folder `backupinfo/`.

Allowlist, not denylist: only the plugin's runtime files go in, so reference material
(python/, Source/, PDFs), local tooling (graphify-out/, .claude/, .superpowers/) and stray
files can never ship. Then verify the zip: top folder, required files, shared-file SHAs.

Run from anywhere:  python -I tools/build_zip.py
"""
import hashlib
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED = os.path.normpath(os.path.join(ROOT, '..', '..', 'shared', 'pgnsuite'))
# User manual (suite rule 2026-11-09): written by Copilot at the suite root, copied here before
# each build; docs/manual.md is the one docs file that ships. Missing source: build without it.
MANUAL_SRC = os.path.normpath(os.path.join(ROOT, '..', '..', 'docs', 'manual', 'backupinfo.md'))
MANUAL = 'docs/manual.md'
TOP = 'backupinfo'

FILES = ['setup.php', 'hook.php', 'composer.json']
DIRS = ['front', 'install', 'lib', 'src', 'templates', 'locales']
REQUIRED = ['setup.php', 'hook.php', 'install/install.php', 'install/uninstall.php']
# zip path -> canonical copy in shared/pgnsuite (hub §4: byte-identical)
SHARED_FILES = {
    'lib/pgnsuite/AssetTab.php': 'AssetTab.php',
    'lib/pgnsuite/ConfigTab.php': 'ConfigTab.php',
    'lib/pgnsuite/Menu.php': 'Menu.php',
    'front/pgnsuite.settings.php': os.path.join('front', 'pgnsuite.settings.php'),
}


def version():
    with open(os.path.join(ROOT, 'setup.php'), encoding='utf-8') as fh:
        m = re.search(r"define\('PLUGIN_BACKUPINFO_VERSION',\s*'([^']+)'\)", fh.read())
    if not m:
        sys.exit('version constant not found in setup.php')
    return m.group(1)


def copy_manual():
    dst = os.path.join(ROOT, *MANUAL.split('/'))
    if not os.path.isfile(MANUAL_SRC):
        print('manual: %s not found, building without it' % MANUAL_SRC)
        if os.path.exists(dst):
            os.remove(dst)  # never ship a stale copy
        return
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    with open(MANUAL_SRC, 'rb') as src, open(dst, 'wb') as out:
        out.write(src.read())
    print('manual: copied to ' + MANUAL)


def entries():
    for name in FILES:
        yield name
    if os.path.isfile(os.path.join(ROOT, *MANUAL.split('/'))):
        yield MANUAL
    for d in DIRS:
        base = os.path.join(ROOT, d)
        for root, dirs, files in os.walk(base):
            dirs[:] = sorted(x for x in dirs if not x.startswith('.'))
            for f in sorted(files):
                if not f.startswith('.'):
                    yield os.path.relpath(os.path.join(root, f), ROOT).replace(os.sep, '/')


def sha(data):
    return hashlib.sha256(data).hexdigest()


def main():
    out = os.path.join(ROOT, 'backupinfov%s.zip' % version())
    if os.path.exists(out):
        # A zip for this version may already be deployed: never replace it silently.
        # Bump the version (rule 8: a fix is a new -rcN), or delete the old zip by hand.
        sys.exit('%s already exists; bump the version in setup.php or remove it first' % os.path.basename(out))
    copy_manual()
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for rel in entries():
            z.write(os.path.join(ROOT, rel), TOP + '/' + rel)

    problems = []
    with zipfile.ZipFile(out) as z:
        names = z.namelist()
        if any(not n.startswith(TOP + '/') for n in names):
            problems.append('entry outside the top folder')
        # Second guard after the allowlist: local-only tooling never ships (.graphifyignore,
        # graphify-out/, CLAUDE*.md, .claude/, any dotfile), wherever it sits.
        local = [n for n in names
                 if any(p.startswith('.') for p in n.split('/'))
                 or re.search(r'graphify|(^|/)claude[^/]*\.md$', n, re.I)]
        if local:
            problems.append('local-only files: ' + ', '.join(local))
        for rel in REQUIRED:
            if TOP + '/' + rel not in names:
                problems.append('missing ' + rel)
        for rel, canon in SHARED_FILES.items():
            got = sha(z.read(TOP + '/' + rel)) if TOP + '/' + rel in names else None
            with open(os.path.join(SHARED, canon), 'rb') as fh:
                want = sha(fh.read())
            print('%-32s %s %s' % (rel, (got or 'MISSING')[:16], 'ok' if got == want else 'MISMATCH ' + want[:16]))
            if got != want:
                problems.append('shared file differs: ' + rel)

    print('%s: %d files' % (os.path.basename(out), len(names)))
    if problems:
        os.remove(out)
        sys.exit('BUILD FAILED, zip removed: ' + '; '.join(problems))


if __name__ == '__main__':
    main()
