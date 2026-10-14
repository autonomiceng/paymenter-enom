import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('enom_build', ROOT/'scripts/build.py')
builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)

class PackageTests(unittest.TestCase):
    def test_actual_release_is_reproducible_and_contains_only_runtime_and_license(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-test-') as temp:
            root = Path(temp)
            first = builder.build(ROOT/'Enom', root/'first')
            second = builder.build(ROOT/'Enom', root/'second')
            self.assertEqual(first, second)
            self.assertEqual((root/'first'/first['archive']).read_bytes(), (root/'second'/second['archive']).read_bytes())
            with zipfile.ZipFile(root/'first'/first['archive']) as package:
                self.assertIn('Enom/LICENSE', package.namelist())
                self.assertIn('Enom/Enom.php', package.namelist())
                self.assertTrue(all(name.startswith('Enom/') and '/tests/' not in name and '/docs/' not in name for name in package.namelist()))
                self.assertEqual(set(package.namelist()), set(first['members']))
    def test_missing_license_or_development_member_fails(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-boundary-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            with self.assertRaises(ValueError): builder.runtime_members(source)
            (source/'LICENSE').write_text('MIT License\nSynthetic testing license.\n')
            (source/'tests').mkdir(); (source/'tests/secret.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
    def test_symlink_and_existing_artifact_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-links-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_text('MIT License\nSynthetic testing license.\n')
            (source/'linked.php').symlink_to(source/'Enom.php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
            (source/'linked.php').unlink()
            output = Path(temp)/'output'; builder.build(source, output)
            with self.assertRaises(FileExistsError): builder.build(source, output)
    def test_output_inside_runtime_is_refused_before_creating_a_directory(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-output-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_text('MIT License\nSynthetic testing license.\n')
            with self.assertRaises(ValueError): builder.build(source, source/'dist')
            self.assertFalse((source/'dist').exists())
    def test_private_filename_in_runtime_directory_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-private-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_text('MIT License\nSynthetic testing license.\n')
            (source/'Services').mkdir(); (source/'Services/sample.private.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
