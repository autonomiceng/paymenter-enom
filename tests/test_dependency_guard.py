import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

class DevelopmentDependencyTests(unittest.TestCase):
    def invoke(self, path):
        env = {**os.environ, 'PAYMENTER_TEST_APP': str(path)}
        return subprocess.run([str(ROOT/'scripts/php-test'), '-r',
            "require 'tests/paymenter-test-app.php'; echo paymenterTestApp();"], cwd=ROOT, env=env, capture_output=True)
    def fixture(self, root):
        for directory in ['vendor', 'bootstrap', 'themes/default/views', 'config']:
            (root/directory).mkdir(parents=True, exist_ok=True)
        (root/'vendor/autoload.php').write_text('<?php')
        (root/'bootstrap/app.php').write_text('<?php')
        (root/'composer.json').write_text(json.dumps({'name': 'paymenter/paymenter'}))
        (root/'config/app.php').write_text("<?php return ['version' => '1.5.9'];")
    def test_pristine_path_accepted_without_bootstrap(self):
        with tempfile.TemporaryDirectory(prefix='enom-pristine-guard-') as temp:
            root = Path(temp); self.fixture(root)
            result = self.invoke(root)
            self.assertEqual(result.returncode, 0)
            self.assertEqual(result.stdout.decode(), str(root))
    def test_environment_cached_configuration_and_symlink_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-pristine-guard-') as temp:
            root = Path(temp); self.fixture(root)
            (root/'.env').write_text('SYNTHETIC_TEST_ONLY=true')
            self.assertNotEqual(self.invoke(root).returncode, 0)
            (root/'.env').unlink()
            (root/'bootstrap/cache').mkdir(); (root/'bootstrap/cache/config.php').write_text('<?php')
            self.assertNotEqual(self.invoke(root).returncode, 0)
            (root/'bootstrap/cache/config.php').unlink()
            alias = root/'alias'; alias.symlink_to(root, target_is_directory=True)
            self.assertNotEqual(self.invoke(alias).returncode, 0)
    def test_different_paymenter_version_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-pristine-version-') as temp:
            root = Path(temp); self.fixture(root)
            (root/'config/app.php').write_text("<?php return ['version' => '1.6.0'];")
            self.assertNotEqual(self.invoke(root).returncode, 0)
    def test_commented_literal_with_dynamic_real_version_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-pristine-comment-version-') as temp:
            root = Path(temp); self.fixture(root)
            for comment in ["// 'version' => '1.5.9'", "# 'version' => '1.5.9'",
                            "/* 'version' => '1.5.9' */", "/** 'version' => '1.5.9' */"]:
                with self.subTest(comment=comment):
                    (root/'config/app.php').write_text("<?php\n" + comment + "\nreturn ['version' => getenv('APP_VERSION')];")
                    self.assertNotEqual(self.invoke(root).returncode, 0)
    def test_comments_do_not_count_toward_one_literal_version(self):
        with tempfile.TemporaryDirectory(prefix='enom-pristine-literal-version-') as temp:
            root = Path(temp); self.fixture(root)
            (root/'config/app.php').write_text("<?php /* 'version' => '1.6.0' */ return ['version' /* current */ => '1.5.9'];")
            self.assertEqual(self.invoke(root).returncode, 0)
            (root/'config/app.php').write_text("<?php return ['version' => '1.5.9', 'version' => '1.5.9'];")
            self.assertNotEqual(self.invoke(root).returncode, 0)
    def test_php_transport_functions_are_disabled(self):
        result = subprocess.run([str(ROOT/'scripts/php-test'), '-r',
            "foreach (['curl_exec','fsockopen','stream_socket_client','socket_connect'] as $name) { if (function_exists($name)) { exit(1); } } if (ini_get('allow_url_fopen') !== '0') { exit(2); }"],
            cwd=ROOT, env=os.environ, capture_output=True)
        self.assertEqual(result.returncode, 0)
