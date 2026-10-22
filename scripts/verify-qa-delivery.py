"""Check the versioned QA delivery, archives, evidence, links and Git exclusions."""
from pathlib import Path
from zipfile import ZipFile
from html.parser import HTMLParser
from urllib.parse import unquote, urlsplit
import collections
import hashlib
import json
import re
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
DELIVERY = ROOT / 'pruebas'


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


class Links(HTMLParser):
    def __init__(self):
        super().__init__()
        self.targets = []

    def handle_starttag(self, tag, attrs):
        for key, value in attrs:
            if key in ('href', 'src', 'poster') and value:
                self.targets.append(value)


def main():
    contract = read(ROOT / 'tests/qa-flow-contract.json')
    signature = lambda data: [(flow['id'], flow['folder'], flow['requests'], flow['checks']) for flow in data['flows']]
    expected_flows = {flow['id'] for flow in contract['flows']}
    expected_totals = [contract['integration']['requests'] + sum(flow['requests'] for flow in contract['flows']),
                       contract['integration']['checks'] + sum(flow['checks'] for flow in contract['flows'])]
    manifest = read(DELIVERY / 'manifest.json')
    present = {p.relative_to(DELIVERY).as_posix(): p
               for p in DELIVERY.rglob('*') if p.is_file() and p.name != 'manifest.json'}
    require(set(present) == set(manifest['files']), 'The delivery file inventory changed.')
    for name, path in present.items():
        require(hashlib.sha256(path.read_bytes()).hexdigest() == manifest['files'][name],
                'Modified artifact: ' + name)
        require(path.stat().st_size < 45 * 1024 * 1024, 'Oversized Git artifact: ' + name)

    archive_path = DELIVERY / 'backend/coleccion-bruno.zip'
    source = DELIVERY / 'backend/coleccion-bruno'
    with ZipFile(archive_path) as archive:
        require(archive.testzip() is None, 'The Bruno ZIP is damaged.')
        expected = {'ASCLA-Pruebas/' + p.relative_to(source).as_posix(): p
                    for p in source.rglob('*') if p.is_file()}
        require(set(archive.namelist()) == set(expected), 'The ZIP differs from the importable folder.')
        for name, path in expected.items():
            require('..' not in Path(name).parts and not name.startswith('/'), 'Unsafe ZIP path.')
            require(archive.read(name) == path.read_bytes(), 'ZIP content mismatch: ' + name)
        requests = [n for n in expected if n.endswith('.bru')
                    and Path(n).name not in ('collection.bru', 'folder.bru')]
        require(len(requests) == expected_totals[0], 'The collection differs from the shared flow contract.')

    collection = (source / 'collection.bru').read_text(encoding='utf-8')
    for match in re.finditer(r'^\s+((?:clave|sesion|nonce)\w+):([^\n]*)$', collection, re.M):
        require(not match.group(2).strip(), 'A private value is persisted: ' + match.group(1))
    for request in source.rglob('*.bru'):
        require(not re.search(r'bru\.setCollectionVar\([\'"](?:clave|sesion|nonce)',
                              request.read_text(encoding='utf-8')),
                'Private runtime data may be written to the collection: ' + request.name)
    example = read(DELIVERY / 'acceso.example.json')
    require(all(not actor['password'] for actor in example['actors'].values()),
            'The access example contains a password.')

    trace = read(DELIVERY / 'trazabilidad.json')
    require(len(trace['cases']) == 103 and len({c['id'] for c in trace['cases']}) == 103,
            'The plan must have 103 unique CP identifiers.')
    require(dict(collections.Counter(c['status'] for c in trace['cases'])) == trace['summary'],
            'The traceability counts do not match the cases.')
    require(all(c['evidence'] for c in trace['cases'] if c['status'] != 'no_incluido'),
            'A mapped test case lacks evidence identifiers.')
    provenance = read(DELIVERY / 'procedencia.json')
    for item in provenance['files']:
        published = DELIVERY / item['published']
        require(published.is_file() and hashlib.sha256(published.read_bytes()).hexdigest()
                == item['published_sha256'], 'Published copy differs from its provenance.')
        if item.get('source_relative'):
            original = ROOT / item['source_relative']
            if original.is_file():
                require(hashlib.sha256(original.read_bytes()).hexdigest() == item['source_sha256'],
                        'The original source no longer matches its recorded hash.')

    source_backend = read(DELIVERY / 'backend/resultados' / provenance['backend']['run'] / 'summary.json')
    groups = [r for r in source_backend['results'] if r['name'] != 'runner']
    require({group['name'] for group in groups} == expected_flows | {'integracion'},
            'Bruno must execute integration and the same six flows as Playwright.')
    totals = [0, 0]
    for group in groups:
        report = read(DELIVERY / 'backend/resultados' / provenance['backend']['run']
                      / (group['name'] + '.json'))[0]
        require(report['summary'] == group['summary'], 'Bruno summary differs from native JSON.')
        definition = next(flow for flow in [contract['integration'], *contract['flows']]
                          if flow['id'] == group['name'])
        require([report['summary']['totalRequests'], report['summary']['totalTests']]
                == [definition['requests'], definition['checks']],
                'A Bruno flow differs from the shared definition: ' + group['name'])
        totals[0] += report['summary']['totalRequests']
        totals[1] += report['summary']['totalTests']
    require(totals == expected_totals, 'Unexpected backend scope; review the shared contract.')
    require(signature(source_backend['flow_contract']) == signature(contract), 'Bruno used a different flow contract.')

    require(provenance.get('retention') == 'latest-only' and 'historical_frontend' not in provenance,
            'Only the latest evidence may be published.')
    expected_backend = DELIVERY / 'backend/resultados' / provenance['backend']['run']
    backend_entries = list((DELIVERY / 'backend/resultados').iterdir())
    require(backend_entries == [expected_backend], 'Old backend evidence remains in pruebas/.')
    require(not (DELIVERY / 'backend/fallos').exists(), 'Old backend failures remain in pruebas/.')
    record = provenance['frontend']
    folder = DELIVERY / 'frontend' / record['category'] / record['id']
    frontend_entries = [p for category in ('aprobadas', 'fallidas')
                        if (DELIVERY / 'frontend' / category).is_dir()
                        for p in (DELIVERY / 'frontend' / category).iterdir()]
    require(frontend_entries == [folder], 'Old frontend evidence remains in pruebas/.')
    report = read(folder / 'frontend.json')
    require(len(report['results']) == len(expected_flows)
            and {case['name'] for case in report['results']} == expected_flows,
            'Playwright must execute exactly the same six business flows as Bruno.')
    require(all(case['status'] in ('passed', 'failed') for case in report['results']),
            'A frontend journey has no final result.')
    failed = any(case['status'] == 'failed' for case in report['results'])
    require(record['category'] == ('fallidas' if failed else 'aprobadas'),
            'The latest run category differs from its actual result.')
    meta = read(folder / 'summary.json')
    require(signature(meta['flow_contract']) == signature(contract), 'Playwright used a different flow contract.')
    require(meta['cleanup']['status'] == 'passed' and meta['cleanup']['carla_active'],
            'The dedicated accounts were not restored successfully.')
    shared = read(DELIVERY / 'flujos.json')
    require({flow['id'] for flow in shared['flows']} == expected_flows,
            'The comparison table does not contain the same six flows.')
    for flow in shared['flows']:
        require(flow['bruno'] == next(group['status'] for group in groups if group['name'] == flow['id'])
                and flow['playwright'] == next(case['status'] for case in report['results'] if case['name'] == flow['id']),
                'The comparison table differs from its original reports.')
    for case in report['results']:
        require(case['videos'] and case['screenshots'], 'A journey lacks video or screenshot evidence.')
        if case['status'] == 'failed':
            require(case.get('error'), 'A failed journey lacks its diagnostic.')
        for media in case['videos'] + case['screenshots']:
            require((folder / media).is_file() and (folder / media).stat().st_size > 1000,
                    'Missing or empty media: ' + media)

    security = provenance.get('security', {})
    security_folder = DELIVERY / 'seguridad'
    security_summary = read(security_folder / 'resumen.json')
    require(security.get('report') == 'seguridad/dashboard.html'
            and security['date'] == security_summary['date']
            and security['status'] == security_summary['status']
            and security.get('jenkins_evidence') is False,
            'Security evidence must preserve its date, result and local scope.')
    phases = {phase['phase']: phase for phase in security_summary['phases']}
    for kind in ('frontend', 'backend'):
        scan = read(security_folder / (kind + '-zap.json'))
        counts = {str(risk): sum(alert['risk'] == risk for alert in scan['alerts'])
                  for risk in range(4)}
        require(counts == scan['risk_counts'] == phases[kind]['risk_counts']
                and scan['status'] == phases[kind]['status'],
                'Published ZAP alerts differ from the recorded result: ' + kind)
        require(all(not urlsplit(location['path']).query
                    for alert in scan['alerts'] for location in alert['locations']),
                'A published ZAP location contains query values.')
    csp = read(security_folder / 'csp-validation.json')
    require(len(csp['validaciones']) == security_summary['csp_checks']
            and csp['estado'] == phases['CSP']['status'],
            'The CSP evidence differs from the recorded result.')
    if csp['estado'] == 'PASS':
        require(all(item['cumple'] for item in csp['validaciones']),
                'A failed CSP check is presented as approved.')
    security_provenance = read(security_folder / 'procedencia.json')
    for item in security_provenance['sources']:
        original = (ROOT / item['source_relative']).resolve()
        require(original.is_relative_to(ROOT.resolve()), 'Security source outside repository.')
        if original.is_file():
            require(hashlib.sha256(original.read_bytes()).hexdigest() == item['source_sha256'],
                    'The original security source changed: ' + item['source_relative'])
    for artifact in security_folder.glob('*'):
        if artifact.suffix not in ('.json', '.html'):
            continue
        text = artifact.read_text(encoding='utf-8')
        require(not re.search(r'nonce-[a-z0-9+/=]{8,}|(?:Cookie|Authorization):\s*\S|PRIVATE KEY', text, re.I),
                'Session/header material in a public security artifact: ' + artifact.name)
        if artifact.suffix == '.json':
            require(not re.search(r'"(?:attack|evidence|requestHeader|responseHeader|requestBody|responseBody)"\s*:', text),
                    'Raw HTTP evidence in a public security summary: ' + artifact.name)

    private = ROOT / 'test-results/playwright-usuarios.json'
    refresh_folder = DELIVERY / 'actualizacion'
    refresh = read(refresh_folder / 'resumen.json')
    recent = read(refresh_folder / 'latest-ui.json')
    remote_headers = read(refresh_folder / 'remote-csp-validation.json')
    remote_browser = read(refresh_folder / 'remote-browser-security.json')
    require(refresh['target'] == 'https://wordpress.ingsoftware.lat'
            and refresh['latest_ui']['tests'] == len(recent['results']) == 14
            and len({case['name'] for case in recent['results']}) == 14,
            'The recent regression scope is incomplete or duplicated.')
    require(refresh['latest_ui']['passed'] == sum(case['status'] == 'passed' for case in recent['results'])
            and recent['cleanup']['status'] == 'passed'
            and recent['cleanup']['removed'] == recent['cleanup']['owned_content'],
            'The recent regression result or cleanup differs from its source.')
    require(refresh['security']['headers']['checks'] == len(remote_headers['validaciones']) == 20
            and refresh['security']['headers']['passed'] == sum(check['cumple'] for check in remote_headers['validaciones'])
            and refresh['security']['headers']['status'] == remote_headers['estado'],
            'The remote header finding was changed or concealed.')
    require(refresh['security']['browser']['views'] == len(remote_browser['responses'])
            and refresh['security']['browser']['status'] == remote_browser['status'],
            'The remote browser checks differ from their source.')
    defense = read(security_folder / 'sustento.json')
    exposure = read(refresh_folder / 'server-exposure.json')
    local_csp = read(security_folder / 'csp-validation.json')
    local_sources = read(security_folder / 'source-manifest.json')
    source_copies = {p.relative_to(security_folder / 'sources').as_posix(): p
                     for p in (security_folder / 'sources').rglob('*') if p.is_file()}
    expected_sources = {name: digest for name, digest in local_sources['files'].items()
                        if not name.startswith('wp-content/plugins/')}
    require(local_sources['source_sha256'] == defense['local']['source_sha256']
            and set(source_copies) == set(expected_sources),
            'The local security source bundle differs from the executed manifest.')
    for name, copy in source_copies.items():
        require(hashlib.sha256(copy.read_bytes()).hexdigest() == expected_sources[name],
                'A published local security test differs from the executed source: ' + name)
    require(defense['local']['csp_checks'] == len(local_csp['validaciones']) == 20
            and defense['local']['csp_passed'] == sum(item['cumple'] for item in local_csp['validaciones'])
            and defense['published']['csp_passed'] == sum(item['cumple'] for item in remote_headers['validaciones'])
            and defense['published']['status'] == remote_headers['estado']
            and defense['published']['http_date'] == remote_headers['fecha'],
            'The security explanation misrepresents the two HTTP results.')
    require(defense['published']['exposure_status'] == exposure['status']
            and defense['published']['exposure_routes'] == len(exposure['results']) == 5
            and exposure['status'] == ('PASS' if all(item['version_hidden'] for item in exposure['results']) else 'FAIL')
            and all(item['version_hidden'] == (not bool(re.search(r'[a-z][a-z-]*/\d', item['server'] or '', re.I)))
                    for item in exposure['results']),
            'The hosting exposure finding differs from the public responses.')
    for scan in defense['local']['scans']:
        recorded = read(security_folder / (scan['kind'] + '-zap.json'))
        require(all(scan[key] == recorded[key] for key in
                    ('status', 'authenticated_before', 'authenticated_after', 'active_complete',
                     'active_strength', 'active_request_count', 'risk_counts'))
                and scan['completed_active_rules'] == len(recorded['completed_active_rules']),
                'The security explanation changes ZAP completion or findings.')
    for item in defense['references']:
        evidence = (DELIVERY / item['file']).resolve()
        require(evidence.is_relative_to(DELIVERY.resolve()) and evidence.is_file()
                and hashlib.sha256(evidence.read_bytes()).hexdigest() == item['sha256'],
                'The security explanation references changed evidence.')
    require(refresh['security']['active_zap_rerun']['status'] == security_summary['status']
            and refresh['security']['active_zap_rerun']['date'] == security_summary['date'],
            'The new local ZAP result is not identified separately.')
    for case in [*recent['results'], remote_browser]:
        require(case['videos'] and case['screenshots'], 'A recent check lacks visual evidence.')
        for media in case['videos'] + case['screenshots']:
            artifact = (refresh_folder / media).resolve()
            require(artifact.is_relative_to(refresh_folder.resolve()) and artifact.is_file()
                    and artifact.stat().st_size > 1000, 'Missing or invalid recent visual evidence.')

    private_files = [private, ROOT / 'test-results/playwright-usuarios-local.json',
                     ROOT / 'test-results/qa-refresh-20261022/private-provided-access.json']
    secrets = [a['password'].encode() for file in private_files if file.is_file() for a in read(file)['actors'].values()]
    personal_path = re.compile(r'(?i:[a-z]:[\\/]+Users[\\/]+)|/Users/[^/\s]+/|/home/[^/\s]+/')
    checked_links = 0
    for name, path in present.items():
        data = path.read_bytes()
        if path.suffix == '.xml':
            ET.fromstring(data)
        require(not any(secret and secret in data for secret in secrets), 'Credential detected in ' + name)
        if path.suffix in ('.json', '.xml', '.html', '.bru', '.md', '.txt', '.cjs'):
            text = data.decode('utf-8')
            require(not personal_path.search(text), 'Personal machine path in ' + name)
            require(not re.search(r'wordpress_logged_in_[a-f0-9]+=\S+', text), 'Session cookie in ' + name)
        if path.suffix == '.html':
            parser = Links()
            parser.feed(text)
            for href in parser.targets:
                url = urlsplit(href)
                if url.scheme or url.netloc or not url.path:
                    continue
                target = (path.parent / unquote(url.path)).resolve()
                require(target.is_relative_to(DELIVERY.resolve()) and target.exists(),
                        'Broken/outside HTML link: ' + name + ' -> ' + href)
                checked_links += 1

    names = [p.relative_to(ROOT).as_posix() for p in [*present.values(), DELIVERY / 'manifest.json']]
    ignored = subprocess.run(['git', 'check-ignore', '--no-index', '-z', '--stdin'], cwd=ROOT,
                             input=('\0'.join(names) + '\0').encode(), stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE)
    require(ignored.returncode in (0, 1), 'Could not check Git exclusions.')
    require(not ignored.stdout, 'Some delivery artifacts are ignored by Git.')
    attributes = subprocess.run(['git', 'check-attr', '-z', '--stdin', 'text'], cwd=ROOT,
                                input=('\0'.join(names) + '\0').encode(), stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, check=True).stdout.split(b'\0')[:-1]
    require(len(attributes) == 3 * len(names)
            and all(value == b'unset' for value in attributes[2::3]),
            'Git newline conversion could invalidate evidence hashes or ZIP contents.')
    print(f'QA delivery OK: {len(names)} files, {totals[0]} Bruno requests, {totals[1]} checks, '
          f'{len(expected_flows)} shared frontend/backend flows, ZAP/CSP evidence, 103 CP identifiers, latest execution only '
          f'and {checked_links} local HTML links verified.')


if __name__ == '__main__':
    main()
