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
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'tests').mkdir(); (source/'tests/secret.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
    def test_license_must_match_repository_bytes(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-license-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            for license_bytes in [b'MIT License\n', (ROOT/'LICENSE').read_bytes() + b'Extra text\n']:
                with self.subTest(license_bytes=license_bytes):
                    (source/'LICENSE').write_bytes(license_bytes)
                    with self.assertRaisesRegex(ValueError, 'license must match'):
                        builder.runtime_members(source)
    def test_symlink_and_existing_artifact_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-links-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'linked.php').symlink_to(source/'Enom.php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
            (source/'linked.php').unlink()
            output = Path(temp)/'output'; builder.build(source, output)
            with self.assertRaises(FileExistsError): builder.build(source, output)
    def test_output_inside_runtime_is_refused_before_creating_a_directory(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-output-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            with self.assertRaises(ValueError): builder.build(source, source/'dist')
            self.assertFalse((source/'dist').exists())
    def test_symlinked_source_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-source-link-') as temp:
            root = Path(temp)
            source = root/'Enom'; source.mkdir()
            alias = root/'alias'; alias.symlink_to(source, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, 'real Enom source'):
                builder.build(alias, root/'output')
            self.assertFalse((root/'output').exists())
    def test_output_inside_source_through_another_path_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-source-alias-') as temp:
            root = Path(temp)
            source = root/'real'/'Enom'; source.mkdir(parents=True)
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            alias = root/'alias'; alias.symlink_to(source.parent, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, 'canonical output directory outside Enom/'):
                builder.build(alias/'Enom', source/'dist')
            self.assertFalse((source/'dist').exists())
    def test_private_filename_in_runtime_directory_refused(self):
        with tempfile.TemporaryDirectory(prefix='enom-package-private-') as temp:
            source = Path(temp)/'Enom'; source.mkdir()
            (source/'Enom.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'Services').mkdir(); (source/'Services/sample.private.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
