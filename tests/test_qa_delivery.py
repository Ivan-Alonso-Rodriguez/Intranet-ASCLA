"""Verify retained originals and honest provenance for replaced private aliases."""
from pathlib import Path
import hashlib
import importlib.util
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    'qa_delivery', Path(__file__).resolve().parents[1] / 'scripts/verify-qa-delivery.py')
delivery = importlib.util.module_from_spec(spec)
spec.loader.exec_module(delivery)


class OriginalProvenanceTest(unittest.TestCase):
    def setUp(self):
        self.previous_root = delivery.ROOT
        workspace = self.previous_root / 'test-results'
        workspace.mkdir(exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(prefix='qa-provenance-', dir=workspace)
        self.assertTrue(Path(self.temp.name).resolve().is_relative_to(workspace.resolve()))
        delivery.ROOT = Path(self.temp.name)
        self.source = delivery.ROOT / 'test-results/security-latest/report.json'
        self.source.parent.mkdir(parents=True)
        self.source.write_bytes(b'original report')
        self.item = {
            'source_relative': 'test-results/security-latest/report.json',
            'source_sha256': hashlib.sha256(self.source.read_bytes()).hexdigest(),
        }

    def tearDown(self):
        delivery.ROOT = self.previous_root
        self.temp.cleanup()

    def test_retained_original_is_verified_and_tampering_rejected(self):
        self.assertTrue(delivery.verify_original(self.item))
        self.source.write_bytes(b'modified report')
        with self.assertRaisesRegex(RuntimeError, 'recorded hash'):
            delivery.verify_original(self.item)

    def test_replaced_alias_requires_explicit_unavailability_and_keeps_old_hash(self):
        old_hash = self.item['source_sha256']
        self.item['source_location_at_export'] = self.item.pop('source_relative')
        self.source.write_bytes(b'a later run at the same alias')
        with self.assertRaisesRegex(RuntimeError, 'Unavailable native source'):
            delivery.verify_original(self.item)
        self.item['native_original_available'] = False
        self.assertFalse(delivery.verify_original(self.item))
        self.assertEqual(self.item['source_sha256'], old_hash)

    def test_missing_private_original_is_not_claimed_as_verified(self):
        self.source.unlink()
        self.assertFalse(delivery.verify_original(self.item))

    def test_provenance_cannot_escape_the_repository(self):
        self.item['source_relative'] = '../outside.json'
        with self.assertRaisesRegex(RuntimeError, 'outside repository'):
            delivery.verify_original(self.item)
        self.item.pop('source_relative')
        self.item.update(native_original_available=False,
                         source_location_at_export='test-results/../../outside.json')
        with self.assertRaisesRegex(RuntimeError, 'outside repository'):
            delivery.verify_original(self.item)


if __name__ == '__main__':
    unittest.main()
