#!/usr/bin/env python3
"""Build a reproducible native Paymenter ZIP from Enom/ only. Never uploads or bootstraps an app."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
ALLOWED_SUFFIXES = {'.php', '.json', '.css', '.js', '.svg'}
RUNTIME_DIRECTORIES = {'Admin', 'Api', 'Http', 'Jobs', 'Models', 'Policies', 'Services', 'database', 'resources', 'routes'}

def runtime_members(source):
    if source.is_symlink() or not source.is_dir():
        raise ValueError('A real Enom source directory is required')
    members = {}
    for path in sorted(source.rglob('*')):
        relative = path.relative_to(source).as_posix()
        if path.is_symlink():
            raise ValueError('Symlinks cannot enter a release')
        if path.is_dir():
            continue
        if not path.is_file() or not re.fullmatch(r'[A-Za-z0-9_./-]+', relative):
            raise ValueError('Unexpected runtime member')
        parts = path.relative_to(source).parts
        if relative not in {'LICENSE', 'Enom.php'} and (len(parts) < 2 or parts[0] not in RUNTIME_DIRECTORIES):
            raise ValueError('Member is outside the extension runtime layout')
        if any(part.startswith('.') or part.lower() in {'tests', 'docs', 'private', 'node_modules', 'vendor'} for part in path.relative_to(source).parts):
            raise ValueError('Development or private files cannot enter a release')
        if 'private' in path.name.lower().split('.'):
            raise ValueError('Private filenames cannot enter a release')
        if relative != 'LICENSE' and path.suffix not in ALLOWED_SUFFIXES:
            raise ValueError('Unexpected runtime file type: ' + relative)
        if path.stat().st_size > 1048576:
            raise ValueError('Runtime member is unexpectedly large')
        members['Enom/' + relative] = path.read_bytes()
    if 'Enom/Enom.php' not in members or 'Enom/LICENSE' not in members:
        raise ValueError('The extension entry point and MIT license are required')
    if not members['Enom/LICENSE'].startswith(b'MIT License\n'):
        raise ValueError('The release must carry the MIT license')
    versions = re.findall(rb"\bversion\s*:\s*['\"]([0-9]+\.[0-9]+\.[0-9]+)['\"]", members['Enom/Enom.php'])
    if len(versions) != 1:
        raise ValueError('One literal ExtensionMeta version is required')
    return versions[0].decode(), members

def build(source, output):
    source = source.absolute()
    output = output.absolute()
    if output.is_symlink() or output.resolve() != output or output.is_relative_to(source):
        raise ValueError('Use a canonical output directory outside Enom/')
    version, members = runtime_members(source)
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ('paymenter-enom-' + version + '.zip')
    manifest_path = output / 'manifest.json'
    if archive.exists() or manifest_path.exists():
        raise FileExistsError('Preserve existing artifacts; choose a fresh output directory')
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for name, data in members.items():
            entry = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(entry, data, compresslevel=9)
    if archive.stat().st_size > 10_000_000:
        raise ValueError('Native upload size limit exceeded; preserve the partial artifact')
    # Verify every actual member and source byte after writing.
    with zipfile.ZipFile(archive) as package:
        if package.namelist() != list(members) or any(package.read(name) != data for name, data in members.items()):
            raise RuntimeError('Archive readback failed')
    if runtime_members(source) != (version, members):
        raise RuntimeError('Runtime changed during packaging; preserve the artifact')
    manifest = {'schema_version': 1, 'version': version, 'archive': archive.name,
                'archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'archive_bytes': archive.stat().st_size,
                'file_count': len(members), 'license': 'MIT', 'members': {name: hashlib.sha256(data).hexdigest() for name, data in members.items()}}
    with manifest_path.open('x') as stream:
        json.dump(manifest, stream, sort_keys=True, indent=2); stream.write('\n')
    return manifest

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output-dir', type=Path, default=ROOT/'dist')
    args = parser.parse_args()
    manifest = build(ROOT/'Enom', args.output_dir.absolute())
    print(json.dumps({'complete': True, 'manifest': str(args.output_dir.absolute()/'manifest.json'),
                      'version': manifest['version'], 'files': manifest['file_count'], 'zip_sha256': manifest['archive_sha256']}))

if __name__ == '__main__':
    main()
