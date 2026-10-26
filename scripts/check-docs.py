"""Validate the maintained project/QA delivery Markdown and Git-publishable targets.

Run --write-matrix after editing requirements/ascla-analysis.json.
Uses only Python's standard library and read-only Git commands.
"""
from pathlib import Path
from urllib.parse import unquote, urlsplit
import argparse
import collections
import json
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
DOCUMENTS = (
    'README.md', 'MANUAL_QA_UAT.md', 'COMPLIANCE.md', 'VERSION_HISTORY.md',
    'requirements/ascla-analysis.md',
    'pruebas/README.md', 'pruebas/backend/README.md', 'pruebas/frontend/README.md',
)
RETIRED = (
    'BUGFIXES_QA.md', 'ACCESSIBILITY.md', 'ADMINISTRACION.md', 'ALIADOS.md',
    'APRENDIZAJE_INTERESES.md', 'CACHE_TRANSACTIONS.md', 'DELIVERY_OPERATIONS.md',
    'FORM_VALIDATION.md', 'QA_HTTP_E2E.md', 'SERVER_SECRETS.md',
)
FENCES = re.compile(r'^```[^\n]*\n.*?^```[^\n]*(?:\n|$)', re.M | re.S)
LINK = re.compile(r'!?\[[^\]\n]*\]\((<[^>]+>|[^)\s]+)(?:\s+"[^"]*")?\)')
# Inspect prose, links and fenced examples. Loopback and project URLs are allowed.
PERSONAL_REFERENCE = re.compile(
    r'(?<![\w])(?:[a-z]:[\\/]|/mnt/[a-z]/|/(?:Users|home)/|file:/+|'
    r'(?:DESKTOP|LAPTOP)-[a-z0-9-]+|\\\\[a-z0-9_.-]+[\\/]|'
    r'(?:10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|'
    r'172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})(?![\w.]))',
    re.I,
)


def personal_reference_lines(text):
    return sorted({text.count('\n', 0, match.start()) + 1
                   for match in PERSONAL_REFERENCE.finditer(text)})


def cell(value):
    return str(value).replace('|', '\\|').replace('\n', ' ')


def evidence_link(value):
    path = value.split('#', 1)[0]
    if path and (ROOT / path).is_file():
        return f'[{value}](../{value})'
    return f'`{value}`'


def render_matrix(matrix):
    rows = [r for group in matrix['requirements'].values() for r in group]
    counts = collections.Counter(r['status'] for r in rows)
    output = [
        '# Matriz de requisitos ASCLA', '',
        'Fuente: DOCUMENTO DE ANALISIS Y DISEÑO ASCLA .pdf, 86 páginas. '
        f"Revisión: {matrix['review_date']}. Plugin {matrix['plugin_version']}, esquema 15.", '',
        'Esta vista se genera desde el [JSON de requisitos](ascla-analysis.json). '
        'Conserva 302 textos originales y 240 prioridades RF/RNF. '
        'Código revisado identifica implementación y evidencia; no acredita aceptación integral. '
        'Las excepciones del usuario no son cumplimiento literal del PDF.', '',
        'El [estado vigente y sus pendientes](../COMPLIANCE.md#estado), '
        'los [procedimientos](../MANUAL_QA_UAT.md) y el '
        '[historial](../VERSION_HISTORY.md) se mantienen en sus documentos principales. '
        'Los resúmenes JSON/JUnit enlazados se permiten en Git; los reportes completos '
        'de test-results son artefactos locales.', '',
        'Inventario: 62 RN, 213 RF y 27 RNF. RF-214 no está definido; RF-93 se normaliza a RF-093.', '',
        '| Estado | Registros |', '|---|---:|',
    ]
    output += [f'| {name} | {count} |' for name, count in sorted(counts.items())]
    for group, title in [('RN', 'Reglas de negocio'), ('RF', 'Requisitos funcionales'), ('RNF', 'Requisitos no funcionales')]:
        output += ['', f'## {title}', '', '| ID | Página | Prioridad PDF | Requisito original | Estado | Evidencia y alcance |', '|---|---:|---|---|---|---|']
        for row in matrix['requirements'][group]:
            evidence = '; '.join(evidence_link(p) for p in row['evidence'].split('; ') if p)
            values = [row['id'], row['page'], row.get('pdf_priority', '—'), row['requirement'], row['status'], evidence + '. ' + row['note']]
            output.append('| ' + ' | '.join(map(cell, values)) + ' |')
    return '\n'.join(output) + '\n'


def anchors(text):
    text = FENCES.sub('', text)
    result = set(re.findall(r'<a\s+id=[\'"]([^\'"]+)[\'"]', text))
    repeats = collections.Counter()
    for heading in re.findall(r'^#{1,6}\s+(.+?)\s*#*\s*$', text, re.M):
        heading = re.sub(r'\[([^\]]+)\]\([^)]+\)', r'\1', heading)
        slug = re.sub(r'[^\w\s-]', '', heading.lower(), flags=re.UNICODE)
        slug = re.sub(r'\s+', '-', slug.strip())
        count = repeats[slug]
        repeats[slug] += 1
        result.add(slug + (f'-{count}' if count else ''))
    return result


def ignored(paths):
    names = sorted(set(paths))
    run = subprocess.run(['git', 'check-ignore', '--no-index', '-z', '--stdin'],
                         cwd=ROOT, input=('\0'.join(names) + '\0').encode('utf-8'),
                         stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if run.returncode not in (0, 1):
        raise RuntimeError(run.stderr.decode('utf-8', errors='replace'))
    return set(filter(None, run.stdout.decode('utf-8').split('\0')))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--write-matrix', action='store_true')
    args = parser.parse_args()
    matrix = json.loads((ROOT / 'requirements/ascla-analysis.json').read_text(encoding='utf-8'))
    rows = [r for group in matrix['requirements'].values() for r in group]
    expected = render_matrix(matrix)
    if args.write_matrix:
        (ROOT / 'requirements/ascla-analysis.md').write_text(expected, encoding='utf-8', newline='\n')
    errors = []
    found = {p.relative_to(ROOT).as_posix() for p in [*ROOT.glob('*.md'), *(ROOT / 'docs').rglob('*.md'), *(ROOT / 'requirements').rglob('*.md'), *(ROOT / 'pruebas').rglob('*.md')]}
    if found != set(DOCUMENTS):
        errors.append('Markdown inventory differs: ' + repr(sorted(found.symmetric_difference(DOCUMENTS))))
    if len(rows) != 302 or len({r['id'] for r in rows}) != 302 or sum('pdf_priority' in r for r in rows) != 240:
        errors.append('Requirement IDs/count/priorities differ from the preserved inventory.')
    if (ROOT / 'requirements/ascla-analysis.md').read_text(encoding='utf-8') != expected:
        errors.append('Matrix differs from JSON; run --write-matrix.')
    texts = {name: (ROOT / name).read_text(encoding='utf-8') for name in DOCUMENTS if (ROOT / name).is_file()}
    publishable = set(DOCUMENTS) | {'requirements/ascla-analysis.json', 'scripts/check-docs.py'}
    counts = collections.Counter(r['status'] for r in rows)
    compliance = texts.get('COMPLIANCE.md', '')
    for status, count in counts.items():
        if f'| {status} | {count} |' not in compliance:
            errors.append(f'COMPLIANCE.md: outdated count for {status}')
    scope = len(rows) - counts['Fuera de alcance']
    reviewed = counts['Código revisado']
    adjusted = reviewed + counts['Ajustado por decisión del usuario']
    for numerator, denominator in [(reviewed, len(rows)), (adjusted, scope)]:
        percent = f'{100*numerator/denominator:.1f}'.replace('.', ',')
        if f'{numerator}/{denominator} (**{percent} %**)' not in compliance:
            errors.append('COMPLIANCE.md: outdated progress fraction/percentage')
    for path in (ROOT / 'docs/evidence').rglob('*'):
        if path.is_file():
            publishable.add(path.relative_to(ROOT).as_posix())
    link_count = 0
    for name, text in texts.items():
        for line in personal_reference_lines(text):
            errors.append(f'{name}:{line}: personal/local-machine reference; use repository-relative paths or project URLs.')
        for retired in RETIRED:
            if retired in text:
                errors.append(f'{name}: obsolete documentation reference {retired}')
        for match in LINK.finditer(FENCES.sub('', text)):
            target = match.group(1).strip('<>')
            url = urlsplit(target)
            if url.scheme in ('https', 'http', 'mailto'):
                continue
            if url.scheme or target.startswith('/'):
                errors.append(f'{name}: nonportable local link {target}')
                continue
            path = ((ROOT / name).parent / unquote(url.path)).resolve() if url.path else ROOT / name
            if not path.is_relative_to(ROOT.resolve()) or not path.exists():
                errors.append(f'{name}: missing target {target}')
                continue
            relative = path.relative_to(ROOT.resolve()).as_posix()
            publishable.add(relative)
            link_count += 1
            if url.fragment and path.suffix == '.md' and unquote(url.fragment) not in anchors(path.read_text(encoding='utf-8')):
                errors.append(f'{name}: missing anchor {target}')
    ignored_targets = ignored(publishable)
    errors += [f'Linked/versioned documentation target is ignored by Git: {name}' for name in sorted(ignored_targets)]
    private = {'.env', '.env.audit', 'test-results/access.private.json', 'test-results/playwright-usuarios.json', 'dist/ascla-core.zip', 'coverage/junit.xml', 'secrets/private.json', 'wp-content/uploads/private.pdf', 'ascla-qa-access.json'}
    errors += [f'Private/generated path no longer ignored: {name}' for name in sorted(private - ignored(private))]
    if ignored(['.env.example']):
        errors.append('.env.example must remain publishable.')
    if errors:
        print('\n'.join(errors), file=sys.stderr)
        return 1
    print(f'Documentation OK: {len(DOCUMENTS)} Markdown files, {len(rows)} requirements, {link_count} local links; personal references, Git exclusions and matrix verified.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
