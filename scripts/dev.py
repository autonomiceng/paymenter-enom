#!/usr/bin/env python3
"""Run offline development tasks with repository-local paths."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT / 'dist/release'


def run(*args, **kwargs):
    return subprocess.run(args, cwd=ROOT, check=True, **kwargs)


def test():
    php = shutil.which(os.environ.get('PAYMENTER_TEST_PHP', 'php'))
    app = ROOT / '.cache/paymenter-1.5.9'
    if not php or not app.is_dir():
        raise RuntimeError('Install system PHP 8.3 or 8.4 and run mise run bootstrap first.')
    env = dict(os.environ, PAYMENTER_TEST_APP=str(app.resolve()), PAYMENTER_TEST_PHP=php)
    run(sys.executable, 'scripts/test.py', env=env)


def build():
    if RELEASE.is_symlink() or RELEASE.parent.is_symlink():
        raise RuntimeError('The release directory must not be a symlink.')
    if RELEASE.exists():
        previous = RELEASE.with_name(f'release-{time.time_ns()}')
        RELEASE.rename(previous)
        print(f'Previous artifacts preserved in {previous.relative_to(ROOT)}', flush=True)
    run(sys.executable, 'scripts/build.py', '--output-dir', str(RELEASE))


def public():
    shallow = run('git', 'rev-parse', '--is-shallow-repository', capture_output=True, text=True).stdout.strip()
    if shallow != 'false':
        raise RuntimeError('Full Git history is required. Fetch it before running check:public.')
    run('gitleaks', 'git', '--log-opts=--all', '--redact', '--no-banner', str(ROOT))
    manifest = json.loads((RELEASE / 'manifest.json').read_text())
    archive = RELEASE / manifest['archive']
    if archive.parent != RELEASE or hashlib.sha256(archive.read_bytes()).hexdigest() != manifest['archive_sha256']:
        raise RuntimeError('The release archive does not match its manifest. Run mise run build.')
    (ROOT / '.scratch').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='public-', dir=ROOT / '.scratch') as temporary:
        with zipfile.ZipFile(archive) as package:
            if len(package.namelist()) != len(manifest['members']) or set(package.namelist()) != set(manifest['members']):
                raise RuntimeError('The release members do not match the manifest.')
            for name in package.namelist():
                path = Path(name)
                if path.is_absolute() or '..' in path.parts or path.parts[0] != 'Enom':
                    raise RuntimeError('Unexpected release member path.')
                if hashlib.sha256(package.read(name)).hexdigest() != manifest['members'][name]:
                    raise RuntimeError('The release member does not match its manifest.')
            package.extractall(temporary)
        run('gitleaks', 'dir', '--redact', '--no-banner', temporary)
    print('Public checks passed: full Git history and release ZIP.', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('task', choices=['test', 'build', 'public'])
    globals()[parser.parse_args().task]()
