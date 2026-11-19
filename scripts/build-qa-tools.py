"""Build the optional, separate WordPress QA helper; never part of ascla-core.zip."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import hashlib

root = Path(__file__).resolve().parents[1]
target = root / 'dist/ascla-qa-tools.zip'
target.parent.mkdir(exist_ok=True)
files = {
    'ascla-qa-tools/ascla-qa-tools.php': root / 'tests/qa-tools/ascla-qa-tools.php',
    'ascla-qa-tools/lib/acceptance-fixture.php': root / 'tests/acceptance-fixture.php',
    'ascla-qa-tools/lib/profile-fixture.php': root / 'tests/profile-fixture.php',
}
with ZipFile(target, 'w', ZIP_DEFLATED) as archive:
    for name, source in files.items():
        archive.writestr(name, source.read_bytes())
with ZipFile(target) as archive:
    assert archive.testzip() is None
    assert set(archive.namelist()) == set(files)
    for name, source in files.items():
        assert archive.read(name) == source.read_bytes()
print(f'{target}: {target.stat().st_size} bytes, SHA256 {hashlib.sha256(target.read_bytes()).hexdigest()}')
