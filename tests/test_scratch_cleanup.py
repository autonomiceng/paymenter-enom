import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ScratchCleanupTests(unittest.TestCase):
    def test_cleanup_on_success_and_failure_preserves_symlink_target(self):
        php = shutil.which(os.environ.get('PAYMENTER_TEST_PHP', 'php'))
        if not php:
            self.skipTest('PHP is required')
        for success in [True, False]:
            with self.subTest(success=success), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                target = root/'dependency'; target.mkdir()
                (target/'keep').write_text('preserve')
                app = root/'app'; (app/'nested').mkdir(parents=True)
                (app/'nested/file').write_text('remove')
                (app/'linked').symlink_to(target, target_is_directory=True)
                (app/'broken').symlink_to(root/'missing')
                code = "require $argv[1]; $testRoot = $argv[2]; $successful = $argv[3] === 'yes'; paymenterTestShutdown($testRoot, $successful);"
                result = subprocess.run([php, '-r', code, str(ROOT/'tests/paymenter-test-app.php'), str(app), 'yes' if success else 'no'], capture_output=True)
                self.assertEqual(result.returncode, 0 if success else 1, result.stderr)
                self.assertFalse(app.exists())
                self.assertEqual((target/'keep').read_text(), 'preserve')
