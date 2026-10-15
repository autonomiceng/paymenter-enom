import contextlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('enom_test_runner', ROOT/'scripts/test.py')
runner = importlib.util.module_from_spec(spec); spec.loader.exec_module(runner)


class TestRunnerTests(unittest.TestCase):
    def test_timeout_saves_receipt_partial_output_and_runtime_check_then_stops(self):
        for changed, stdout, stderr in [(False, b'partial stdout\n', b'partial stderr\n'),
                                         (True, b'partial stdout\n', b'partial stderr\n'),
                                         (False, None, None)]:
            with self.subTest(changed=changed, stdout=stdout), tempfile.TemporaryDirectory(prefix='enom-runner-timeout-') as temp:
                root = Path(temp)
                (root/'Enom').mkdir()
                runtime = root/'Enom/Enom.php'; runtime.write_bytes(b'<?php')
                before = runner.hashes(root/'Enom')

                def timeout(argv, **kwargs):
                    self.assertEqual(kwargs['timeout'], 180)
                    if changed:
                        runtime.write_bytes(b'<?php // changed by synthetic command')
                    raise subprocess.TimeoutExpired(argv, 180, output=stdout, stderr=stderr)

                with mock.patch.object(runner, 'ROOT', root), \
                     mock.patch.object(runner.sys, 'argv', ['test.py', '--suite', 'provider']), \
                     mock.patch.object(runner.shutil, 'which', return_value='/synthetic/php'), \
                     mock.patch.object(runner.subprocess, 'run', side_effect=timeout) as run, \
                     mock.patch.object(runner.os, 'umask'), \
                     contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    self.assertEqual(runner.main(), 1)
                run.assert_called_once()
                receipts = list((root/'.scratch').glob('tests-*/receipt.json'))
                self.assertEqual(len(receipts), 1)
                receipt = json.loads(receipts[0].read_text())
                self.assertFalse(receipt['complete'])
                self.assertEqual(receipt['runtime_sha256'], before)
                self.assertEqual(len(receipt['results']), 1)
                result = receipt['results'][0]
                self.assertIsNone(result['exit'])
                self.assertTrue(result['timed_out'])
                self.assertEqual(result['runtime_unchanged'], not changed)
                self.assertEqual((receipts[0].parent/'000.stdout.txt').read_bytes(), stdout or b'')
                self.assertEqual((receipts[0].parent/'000.stderr.txt').read_bytes(), stderr or b'')
