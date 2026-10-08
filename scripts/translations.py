"""Sync bilingual source strings to PO files and compile standard GNU MO catalogs.

Run this script after adding strings to languages/en.json. Existing PO translations
are retained. The package builder only compiles PO files, so translator edits survive.
"""
from pathlib import Path
import ast
import gettext
import io
import json
import re
import struct

SOURCE = Path(__file__).resolve().parents[1] / 'wp-content/plugins/ascla-core'
LANGUAGES = SOURCE / 'languages'


def read_po(path):
    messages, entry, field = {}, {}, None
    for line in path.read_text(encoding='utf-8').splitlines() + ['']:
        if not line.strip():
            if 'msgid' in entry and 'msgstr' in entry:
                messages[entry['msgid']] = entry['msgstr']
            entry, field = {}, None
        elif line.startswith(('msgid ', 'msgstr ')):
            field, value = line.split(' ', 1)
            entry[field] = ast.literal_eval(value)
        elif line.startswith('"') and field:
            entry[field] += ast.literal_eval(line)
        elif not line.startswith('#'):
            raise ValueError(f'Unsupported PO entry in {path.name}: {line[:40]}')
    return messages


def compile_catalogs():
    for locale in ('es_ES', 'en_US'):
        path = LANGUAGES / f'ascla-core-{locale}.po'
        messages = read_po(path)
        entries = sorted((key.encode('utf-8'), value.encode('utf-8')) for key, value in messages.items())
        count = len(entries)
        offset = 28 + count * 16
        ids, values, id_table, value_table = b'', b'', [], []
        for key, value in entries:
            id_table.append((len(key), offset + len(ids)))
            ids += key + b'\0'
            values += value + b'\0'
        position = offset + len(ids)
        for _, value in entries:
            value_table.append((len(value), position))
            position += len(value) + 1
        binary = struct.pack('<7I', 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
        binary += b''.join(struct.pack('<2I', *row) for row in id_table + value_table) + ids + values
        # Parse our output with an independent, standard GNU gettext reader.
        catalog = gettext.GNUTranslations(io.BytesIO(binary))
        assert all(catalog.gettext(key) == value for key, value in messages.items() if key)
        path.with_suffix('.mo').write_bytes(binary)
        print(f'{locale}: {count - 1} gettext messages compiled')


def sync_catalogs():
    source = json.loads((LANGUAGES / 'en.json').read_text(encoding='utf-8'))
    for path in SOURCE.rglob('*.php'):
        # Additional bilingual labels used by login forms.
        for es, en in re.findall(r"Language::text\('([^'\\]*)','([^'\\]*)'\)", path.read_text(encoding='utf-8')):
            source.setdefault(es, en)
    version = re.search(r'Version:\s*([0-9.]+)', (SOURCE / 'ascla-core.php').read_text(encoding='utf-8')).group(1)
    for locale in ('es_ES', 'en_US'):
        path = LANGUAGES / f'ascla-core-{locale}.po'
        previous = read_po(path) if path.exists() else {}
        header = f'Project-Id-Version: ASCLA Core {version}\nLanguage: {locale}\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\nPlural-Forms: nplurals=2; plural=(n != 1);\n'
        rows = [('', header)] + [(key, previous.get(key, value if locale == 'en_US' else key)) for key, value in sorted(source.items())]
        path.write_text('# ASCLA Core UI catalog. Source language: es_ES.\n\n' + '\n\n'.join('msgid ' + json.dumps(key, ensure_ascii=False) + '\nmsgstr ' + json.dumps(value, ensure_ascii=False) for key, value in rows) + '\n', encoding='utf-8')


if __name__ == '__main__':
    sync_catalogs()
    compile_catalogs()
