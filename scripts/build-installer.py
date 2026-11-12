"""Package the verified release for WordPress hosts with a 2 MiB upload limit.

The complete release remains unchanged. Only editable PO translation sources are
omitted; their compiled MO catalogs and the runtime JSON catalog are retained.
"""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from copy import copy
import hashlib
import json

ROOT = Path(__file__).resolve().parents[1]
COMPLETE = ROOT / 'dist/ascla-core.zip'
INSTALLER = ROOT / 'dist/ascla-core-instalable.zip'
EVIDENCE = ROOT / 'docs/evidence/release-1.11.0'
LIMIT = 2 * 1024 * 1024


def build():
    manifest = json.loads((EVIDENCE / 'package.json').read_text(encoding='utf-8'))
    assert hashlib.sha256(COMPLETE.read_bytes()).hexdigest() == manifest['sha256']
    omitted = {
        'ascla-core/languages/ascla-core-es_ES.po',
        'ascla-core/languages/ascla-core-en_US.po',
    }
    with ZipFile(COMPLETE) as complete:
        assert complete.testzip() is None
        assert omitted.issubset(complete.namelist())
        with ZipFile(INSTALLER, 'w', ZIP_DEFLATED, compresslevel=9) as installer:
            for info in complete.infolist():
                if info.filename in omitted:
                    continue
                installer.writestr(copy(info), complete.read(info.filename),
                                   compress_type=ZIP_DEFLATED, compresslevel=9)
        with ZipFile(INSTALLER) as installer:
            assert installer.testzip() is None
            assert set(installer.namelist()) == set(complete.namelist()) - omitted
            for name in installer.namelist():
                assert installer.read(name) == complete.read(name), name
                assert hashlib.sha256(installer.read(name)).hexdigest() == manifest['production_hashes'][name], name
            for name in ('ascla-core/languages/en.json',
                         'ascla-core/languages/ascla-core-es_ES.mo',
                         'ascla-core/languages/ascla-core-en_US.mo'):
                assert name in installer.namelist()
            assert INSTALLER.stat().st_size < LIMIT
            digest = hashlib.sha256(INSTALLER.read_bytes()).hexdigest()
            INSTALLER.with_suffix('.sha256').write_text(digest + '  ' + INSTALLER.name + '\n', encoding='utf-8')
            report = {
                'version': manifest['version'],
                'artifact': 'dist/' + INSTALLER.name,
                'sha256': digest,
                'bytes': INSTALLER.stat().st_size,
                'files': len(installer.namelist()),
                'upload_limit_bytes': LIMIT,
                'below_2MiB': True,
                'crc_valid': True,
                'all_retained_files_identical_to_complete_release': True,
                'complete_release_sha256': manifest['sha256'],
                'complete_release_unchanged': True,
                'omitted_translation_sources': sorted(omitted),
                'runtime_translation_catalogs_retained': True,
                'scope': 'Packaging-only variant; existing PHP, JavaScript, PDF.js, CSS and compiled translations remain byte-identical to the verified complete release. No server settings were changed and no new functional test run is claimed.',
            }
            (EVIDENCE / 'upload-limit1110.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
            print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    build()
