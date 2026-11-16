#!/usr/bin/env python3
"""Run genuine Composer from source in a sandbox where getcomposer.org, Packagist and
GitHub release assets are unreachable but api.github.com / codeload.github.com are.

It downloads composer/composer at a release tag plus the runtime packages named in Composer's OWN composer.lock
(exact commit SHAs), and writes a minimal PSR-4 bootstrap so `php composer-src/bin/composer` works.
Nothing is installed globally. Everything stays under the directory given as argv[1].
"""
import io, json, os, subprocess, sys, zipfile, hashlib, shutil
from concurrent.futures import ThreadPoolExecutor

root, tag = sys.argv[1], sys.argv[2]
src = os.path.join(root, 'composer-src')
vendor = os.path.join(src, 'vendor')

def gh(path, raw=False):
    cmd = ['gh', 'api', path] + (['-H', 'Accept: application/vnd.github.raw'] if raw else [])
    return subprocess.run(cmd, check=True, capture_output=True).stdout

def unpack(data, dest):
    """GitHub zipballs wrap everything in one top-level folder; strip it. Refuse path traversal."""
    z = zipfile.ZipFile(io.BytesIO(data))
    if os.path.isdir(dest): shutil.rmtree(dest)
    for info in z.infolist():
        rel = info.filename.split('/', 1)[1] if '/' in info.filename else ''
        if not rel or rel.endswith('/'): continue
        target = os.path.normpath(os.path.join(dest, rel))
        if not target.startswith(os.path.abspath(dest) + os.sep): raise SystemExit('unsafe path in archive: ' + info.filename)
        os.makedirs(os.path.dirname(target), exist_ok=True)
        with open(target, 'wb') as fh: fh.write(z.read(info))
        mode = info.external_attr >> 16
        if mode & 0o111: os.chmod(target, 0o755)

tag_sha = json.loads(gh(f'repos/composer/composer/git/ref/tags/{tag}'))['object']['sha']
data = gh(f'repos/composer/composer/zipball/{tag}')
unpack(data, src)
print(f'composer/composer {tag} (tag object {tag_sha[:12]}) source zip sha256={hashlib.sha256(data).hexdigest()[:16]}… -> {src}')

lock = json.load(open(os.path.join(src, 'composer.lock')))
pkgs = lock['packages']

def fetch(p):
    url = p['dist']['url']
    assert url.startswith('https://api.github.com/repos/'), url
    blob = gh(url.replace('https://api.github.com/', ''))
    unpack(blob, os.path.join(vendor, p['name']))
    return p['name'], p['version'], p['dist']['reference'][:12], len(blob)

with ThreadPoolExecutor(8) as ex:
    done = list(ex.map(fetch, pkgs))
for name, ver, ref, size in sorted(done): print(f'  {name:42s} {ver:10s} {ref}  {size:>8d} B')

psr4, psr0, files = {'Composer\\': [os.path.join(src, 'src/Composer') + '/']}, {}, []
for p in pkgs:
    base = os.path.join(vendor, p['name'])
    al = p.get('autoload', {})
    for ns, paths in al.get('psr-4', {}).items():
        for d in ([paths] if isinstance(paths, str) else paths):
            psr4.setdefault(ns, []).append(os.path.join(base, d).rstrip('/') + '/')
    for ns, paths in al.get('psr-0', {}).items():
        for d in ([paths] if isinstance(paths, str) else paths):
            psr0.setdefault(ns, []).append(os.path.join(base, d).rstrip('/') + '/')
    files += [os.path.join(base, f) for f in al.get('files', [])]

def php(v): return json.dumps(v, indent=1).replace('{', '[').replace('}', ']').replace('": ', '" => ')
os.makedirs(vendor, exist_ok=True)
open(os.path.join(vendor, 'autoload.php'), 'w').write(f"""<?php
// Minimal bootstrap written by tools/preview/sandbox/bootstrap_composer.py — only so Composer can run from source here.
require_once {json.dumps(os.path.join(src, 'src/Composer/Autoload/ClassLoader.php'))};
$loader = new \\Composer\\Autoload\\ClassLoader(__DIR__);
foreach ({php(psr4)} as $prefix => $paths) {{ $loader->setPsr4($prefix, $paths); }}
foreach ({php(psr0)} as $prefix => $paths) {{ $loader->set($prefix, $paths); }}
$loader->register(true);
foreach ({php(files)} as $file) {{ require_once $file; }}
return $loader;
""")
print(f'bootstrap written: {len(psr4)} PSR-4 prefixes, {len(psr0)} PSR-0, {len(files)} files; packages={len(pkgs)}')
