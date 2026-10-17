#!/usr/bin/env python3
"""Install locked dependencies and prepare a disposable Paymenter development app."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / '.cache/paymenter-1.5.9'
UPSTREAM = 'https://github.com/Paymenter/Paymenter.git'


def run(*args, cwd=ROOT, capture=False):
    return subprocess.run(args, cwd=cwd, check=True, text=True,
                          stdout=subprocess.PIPE if capture else None).stdout


def fingerprint(directory, names):
    digest = hashlib.sha256()
    for name in names:
        digest.update((directory / name).read_bytes())
    return digest.hexdigest()


def npm_install(directory):
    stamp = directory / 'node_modules/.enom-bootstrap'
    expected = fingerprint(directory, ['package.json', 'package-lock.json']) + run('node', '--version', capture=True)
    if stamp.is_file() and stamp.read_text() == expected:
        result = subprocess.run(['npm', 'ls', '--all', '--offline', '--parseable'], cwd=directory,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if result.returncode == 0:
            print(f'npm dependencies ready: {directory.relative_to(ROOT)}', flush=True)
            return
    run('npm', 'ci', '--ignore-scripts', cwd=directory)
    stamp.write_text(expected)


def main():
    os.chdir(ROOT)
    php = shutil.which(os.environ.get('PAYMENTER_TEST_PHP', 'php'))
    if not php:
        raise RuntimeError('Install system PHP 8.3 or 8.4 and Composer before bootstrap.')
    version = run(php, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;', capture=True)
    if version not in {'8.3', '8.4'}:
        raise RuntimeError('System PHP 8.3 or 8.4 is required.')
    npm_install(ROOT)
    if not APP.exists():
        APP.parent.mkdir(exist_ok=True)
        run('git', 'clone', '--depth', '1', '--branch', 'v1.5.9', UPSTREAM, str(APP))
    if APP.is_symlink() or not (APP / '.git').is_dir():
        raise RuntimeError('The development app must be a real upstream Git checkout.')
    if run('git', 'config', '--get', 'remote.origin.url', cwd=APP, capture=True).strip() != UPSTREAM:
        raise RuntimeError('The development app must use the official Paymenter upstream.')
    if run('git', 'rev-parse', 'HEAD', cwd=APP, capture=True) != run(
            'git', 'rev-parse', 'v1.5.9^{commit}', cwd=APP, capture=True):
        raise RuntimeError('The development app must remain on tag v1.5.9.')
    for name in ['.env', 'bootstrap/cache/config.php']:
        if (APP / name).exists() or (APP / name).is_symlink():
            raise RuntimeError(f'Remove {APP / name} from this disposable development app.')
    config = APP / 'config/app.php'
    original = run('git', 'show', 'HEAD:config/app.php', cwd=APP, capture=True)
    prepared, count = re.subn(r"(['\"]version['\"]\s*=>\s*['\"])development(['\"])",
                              r'\g<1>1.5.9\2', original)
    if count != 1 or config.read_text() not in {original, prepared}:
        raise RuntimeError('Unexpected Paymenter version configuration.')
    run('git', 'diff', '--exit-code', 'HEAD', '--', '.', ':(exclude)config/app.php', cwd=APP, capture=True)
    if config.read_text() != prepared:
        config.write_text(prepared)
    stamp = APP / 'vendor/.enom-bootstrap'
    expected = fingerprint(APP, ['composer.json', 'composer.lock']) + version
    installed = APP / 'vendor/composer/installed.json'
    ready = stamp.is_file() and stamp.read_text() == expected and installed.is_file()
    if ready:
        packages = json.loads(installed.read_text())['packages']
        ready = (APP / 'vendor/autoload.php').is_file() and all(
            (installed.parent / package['install-path']).is_dir() for package in packages)
    if not ready:
        run('composer', 'install', '--no-scripts', '--no-plugins', '--no-interaction', '--prefer-dist', '--no-progress', cwd=APP)
        stamp.write_text(expected)
    else:
        print('Composer dependencies ready.', flush=True)
    run('composer', '--no-scripts', '--no-plugins', 'check-platform-reqs', cwd=APP, capture=True)
    npm_install(APP)
    theme_stamp = APP / 'node_modules/.enom-theme'
    theme_hash = fingerprint(APP, ['package-lock.json', 'vite.js', 'themes/default/vite.config.js'])
    manifest = APP / 'public/default/manifest.json'
    assets_ready = False
    if theme_stamp.is_file() and theme_stamp.read_text() == theme_hash and manifest.is_file():
        assets = json.loads(manifest.read_text())
        assets_ready = bool(assets) and all((manifest.parent / path).is_file()
                                           for entry in assets.values()
                                           for path in [entry['file'], *entry.get('css', []), *entry.get('assets', [])])
    if not assets_ready:
        run('npm', 'run', 'build', cwd=APP)
        if not manifest.is_file():
            raise RuntimeError('Paymenter default theme manifest is missing.')
        theme_stamp.write_text(theme_hash)
    else:
        print('Default theme assets ready.', flush=True)
    env = dict(os.environ, PAYMENTER_TEST_APP=str(APP.resolve()))
    subprocess.run([php, '-r', "require 'tests/paymenter-test-app.php'; paymenterTestApp();"],
                   cwd=ROOT, env=env, check=True)
    print('Bootstrap complete: pristine Paymenter 1.5.9 ready.', flush=True)


if __name__ == '__main__':
    main()
