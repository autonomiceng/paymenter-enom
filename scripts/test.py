#!/usr/bin/env python3
"""Run portable offline suites. Native checks require a caller-provided pristine development release."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PHP_TEST = ROOT/'scripts/php-test'
SUITES = {
    'provider': ['provider-contracts.php', 'registrar-money.php', 'contact-read-regressions.php', 'ui-input-contracts.php',
                 'catalogue-contracts.php', 'registrar-client.php', 'pricing-rules.php', 'transfer-rdap.php'],
    'native': ['billing-integration.php', 'domain-billing.php', 'domain-administration.php', 'managed-renewal-execution.php',
               'renewal-admission.php', 'renewal-lifecycle-proof.php', 'observation-integration.php', 'reconciliation-rejection.php',
               'reconciliation-registration.php', 'capability-readbacks.php', 'provider-disposition.php', 'native-date-missing-schema.php',
               'request-gates.php', 'staff-funding-gates.php', 'catalogue-integration.php', 'runtime-guard.php', 'management-actions.php',
               'pricing-complimentary.php', 'registrar-terms.php', 'registry-details.php', 'glue-records.php', 'refunds.php', 'reminders.php'],
    'ui': ['client-controller.php', 'client-views.php', 'admin-staff.php', 'public-search.php', 'client-script.cjs'],
}

def hashes(root):
    return {str(path.relative_to(root)): hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(root.rglob('*')) if path.is_file()}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', choices=['lint', 'package', *SUITES], action='append')
    args = parser.parse_args()
    selected = args.suite or ['lint', 'package', 'provider', 'native', 'ui']
    php = shutil.which(os.environ.get('PAYMENTER_TEST_PHP', 'php'))
    if not php:
        raise RuntimeError('PHP is required')
    env = {'PATH': os.environ.get('PATH', '/usr/bin:/bin'), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8',
           'PAYMENTER_TEST_PHP': php, 'PHP_BIN': str(PHP_TEST)}
    if os.environ.get('PAYMENTER_TEST_APP'):
        env['PAYMENTER_TEST_APP'] = os.environ['PAYMENTER_TEST_APP']
    commands = []
    if any(suite in selected for suite in ['native', 'ui']):
        # This fails before any dependency bootstrap if a pristine development app was not explicitly selected.
        commands.append(('dependency', [str(PHP_TEST), '-r', "require 'tests/paymenter-test-app.php'; paymenterTestApp(); echo 'Pristine development dependency accepted.\\n';"]))
    if 'lint' in selected:
        commands.extend(('lint', [str(PHP_TEST), '-l', str(path.relative_to(ROOT))]) for area in ['Enom', 'tests'] for path in sorted((ROOT/area).rglob('*.php')))
    if 'package' in selected:
        commands.append(('package', [sys.executable, '-m', 'unittest', 'discover', '-s', 'tests', '-p', 'test_*.py']))
    for suite in SUITES:
        if suite not in selected:
            continue
        for name in SUITES[suite]:
            interpreter = 'node' if name.endswith('.cjs') else str(PHP_TEST)
            commands.append((suite, [interpreter, 'tests/Enom/' + name]))
    os.umask(0o077)
    output = Path(tempfile.mkdtemp(prefix='enom-tests-'))
    runtime_before = hashes(ROOT/'Enom')
    results = []
    for index, (suite, argv) in enumerate(commands):
        timed_out = False
        try:
            result = subprocess.run(argv, cwd=ROOT, env=env, capture_output=True, timeout=180)
            stdout, stderr, exit_code = result.stdout, result.stderr, result.returncode
        except subprocess.TimeoutExpired as error:
            stdout, stderr, exit_code = error.stdout or b'', error.stderr or b'', None
            timed_out = True
        (output/('%03d.stdout.txt' % index)).write_bytes(stdout)
        (output/('%03d.stderr.txt' % index)).write_bytes(stderr)
        unchanged = hashes(ROOT/'Enom') == runtime_before
        results.append({'suite': suite, 'argv': argv, 'exit': exit_code, 'timed_out': timed_out, 'runtime_unchanged': unchanged})
        if suite != 'lint' or exit_code or timed_out:
            print(stdout.decode(errors='replace').strip(), flush=True)
            if stderr:
                print(stderr.decode(errors='replace').strip(), file=sys.stderr, flush=True)
        if timed_out:
            print('Command timed out after 180 seconds.', file=sys.stderr, flush=True)
        if timed_out or exit_code or not unchanged:
            break
    complete = len(results) == len(commands) and all(row['exit'] == 0 and row['runtime_unchanged'] for row in results)
    receipt = {'complete': complete, 'selected_suites': selected, 'results': results, 'runtime_sha256': runtime_before,
               'omitted_suites': [name for name in ['lint', 'package', *SUITES] if name not in selected],
               'pristine_dependency_requested': env.get('PAYMENTER_TEST_APP'), 'real_provider_calls': 0,
               'scope': 'Local synthetic tests only; no uploads, live providers or database sockets.'}
    (output/'receipt.json').write_text(json.dumps(receipt, indent=2)+'\n')
    print(json.dumps({'complete': complete, 'commands_run': len(results), 'commands_planned': len(commands), 'receipt': str(output/'receipt.json')}))
    return 0 if complete else 1

if __name__ == '__main__':
    raise SystemExit(main())
