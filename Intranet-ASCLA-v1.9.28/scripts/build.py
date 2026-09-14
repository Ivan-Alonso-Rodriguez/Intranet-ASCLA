"""Build an allowlisted, reproducible WordPress plugin archive (stdlib only)."""
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import hashlib

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'wp-content/plugins/ascla-core'
DEST = ROOT / 'dist/ascla-core.zip'
ALLOWED = {'.php', '.css', '.js', '.png', '.svg', '.webp', '.txt'}

def build():
    DEST.parent.mkdir(exist_ok=True)
    files = sorted(p for p in SOURCE.rglob('*') if p.is_file() and not p.is_symlink())
    with ZipFile(DEST, 'w', ZIP_DEFLATED, compresslevel=9) as archive:
        for path in files:
            relative = path.relative_to(SOURCE)
            if (path.suffix not in ALLOWED and relative.as_posix() != 'languages/en.json') or any(part.startswith('.') for part in relative.parts):
                continue
            if any(part in {'tests', 'node_modules', 'vendor', 'logs', 'tmp'} for part in relative.parts):
                continue
            info = ZipInfo('ascla-core/' + relative.as_posix(), (2026, 9, 5, 0, 0, 0))
            info.compress_type = ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, path.read_bytes())
    digest = hashlib.sha256(DEST.read_bytes()).hexdigest()
    (DEST.parent / 'ascla-core.sha256').write_text(digest + '  ascla-core.zip\n')
    print(f'{DEST}: {DEST.stat().st_size:,} bytes | SHA256 {digest}')

if __name__ == '__main__':
    build()
