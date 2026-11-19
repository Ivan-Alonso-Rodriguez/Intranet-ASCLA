"""Check UI lookup coverage and interpolation tokens without changing user content."""
import ast
import json
import re
from pathlib import Path
from translations import read_po

root = Path(__file__).resolve().parents[1]
plugin = root / 'wp-content/plugins/ascla-core'
catalog = json.loads((plugin / 'languages/en.json').read_text(encoding='utf-8'))
english = read_po(plugin / 'languages/ascla-core-en_US.po')
keys = set(catalog) | set(english)
missing = set()
assets = sorted((plugin / 'assets').glob('*.js'))
for file in assets:
    source = file.read_text(encoding='utf-8')
    for match in re.finditer(r'''\b(?:T|L|text)\(\s*((?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'))\s*\)''', source):
        value = ast.literal_eval(match[1])
        if re.search(r'[A-Za-zÁÉÍÓÚáéíóúÑñ]', value) and value not in keys:
            missing.add((file.name, value))
tokens = re.compile(r'\{\w+\}|%(?:\d+\$)?[ds]')
invalid = [(source, translated) for labels in (english,catalog) for source, translated in labels.items()
           if source and sorted(tokens.findall(source)) != sorted(tokens.findall(translated))]
if missing or invalid:
    raise SystemExit(json.dumps({'missing': sorted(missing), 'interpolation_errors': invalid}, ensure_ascii=False, indent=2))
print(f'Translations OK: {len(assets)} JavaScript modules, {len(catalog)} dictionary labels, {len(english)-1} gettext messages; interpolation tokens preserved.')
