#!/usr/bin/env python3
"""Validate a private Laravel tarball without extracting or executing it."""
import json
import posixpath
import sys
import tarfile

REQUIRED = {'artisan', 'public/index.php', 'public/build/manifest.json', 'vendor/autoload.php', 'bootstrap/app.php'}


def normalized(name):
    if name.startswith('/') or '..' in name.split('/'):
        raise ValueError('Unsafe archive path')
    return posixpath.normpath(name)


def check_members(members):
    found = set()
    for member in members:
        name = normalized(member.name)
        if name in found:
            raise ValueError('Duplicate archive path: ' + name)
        found.add(name)
        if name == '.env' or name.startswith('.env.') or '.git' in name.split('/'):
            raise ValueError('Private environment or Git metadata in release')
        if name in {'public/hot', 'bootstrap/cache/config.php'}:
            raise ValueError('Machine-specific runtime configuration in release')
        if name.startswith('database/') and ('.sqlite' in name or name.endswith('.db')):
            raise ValueError('Runtime database in release')
        if name.startswith(('storage/logs/', 'storage/framework/sessions/')) and member.isfile() and posixpath.basename(name) != '.gitignore':
            raise ValueError('Runtime log/session in release')
        if member.issym() or member.islnk():
            target = member.linkname
            base = posixpath.dirname(name) if member.issym() else ''
            resolved = posixpath.normpath(posixpath.join(base, target))
            if target.startswith('/') or resolved == '..' or resolved.startswith('../'):
                raise ValueError('Archive link escapes application root')
        elif not (member.isfile() or member.isdir()):
            raise ValueError('Unsupported archive member type')
    files = {normalized(m.name) for m in members if m.isfile()}
    missing = REQUIRED - files
    if missing:
        raise ValueError('Missing release files: ' + ', '.join(sorted(missing)))


def verify(path):
    with tarfile.open(path, 'r:gz') as archive:
        check_members(archive.getmembers())
        manifest = next(m for m in archive.getmembers() if normalized(m.name) == 'public/build/manifest.json')
        if manifest.size > 2_000_000:
            raise ValueError('Unexpected manifest size')
        entries = json.load(archive.extractfile(manifest))
        for entry in ['resources/css/admin.css', 'resources/js/admin.js', 'resources/js/auth.js']:
            if entry not in entries:
                raise ValueError('Missing native admin build entry: ' + entry)
            asset = normalized('public/build/' + entries[entry]['file'])
            if asset not in {normalized(m.name) for m in archive.getmembers() if m.isfile()}:
                raise ValueError('Manifest references missing admin asset')
    print('PASS: private Laravel archive structure and admin assets verified; not hosted acceptance.')


def self_test():
    def files(names):
        return [tarfile.TarInfo(n) for n in names]
    check_members(files(REQUIRED))
    for bad in ['../escape', '/absolute', '.env', '.env.production', '.git/config', 'public/hot', 'bootstrap/cache/config.php', 'database/database.sqlite', 'storage/logs/laravel.log', 'storage/framework/sessions/session']:
        try:
            check_members(files([*REQUIRED, bad]))
        except ValueError:
            continue
        raise AssertionError('Accepted unsafe member: ' + bad)
    link = tarfile.TarInfo('vendor/escape')
    link.type = tarfile.SYMTYPE
    link.linkname = '../../outside'
    try:
        check_members([*files(REQUIRED), link])
    except ValueError:
        pass
    else:
        raise AssertionError('Accepted escaping symlink')
    print('PASS: release validator required-file baseline and 11 unsafe-member fixtures.')


if __name__ == '__main__':
    if len(sys.argv) == 2 and sys.argv[1] == '--self-test':
        self_test()
    elif len(sys.argv) == 2:
        verify(sys.argv[1])
    else:
        sys.exit('Usage: verify-release.py ARCHIVE | --self-test')
