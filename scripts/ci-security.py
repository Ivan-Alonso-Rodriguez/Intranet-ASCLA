#!/usr/bin/env python3
"""Mandatory image gate. No severity parameters, ignore files, VEX or bypass mode."""
from collections import Counter
from datetime import datetime, timezone
from html import escape
from pathlib import Path
import hashlib
import json
import os
import re
import subprocess
import sys
import tarfile
import time

ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / 'test-results/ci-private'
OUT = ROOT / 'test-results/ci-artifacts/trivy'
ROLES = ('app', 'cli', 'db', 'mailpit')
SEVERITIES = ('UNKNOWN', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL')
ID = re.compile(r'sha256:[a-f0-9]{64}\Z')


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def write(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + '.tmp')
    temp.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temp.replace(path)


def run(args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)


def output(args):
    return subprocess.check_output(args, text=True).strip()


def source_hash():
    paths = list((ROOT / 'wp-content/plugins/ascla-core').rglob('*'))
    paths += list((ROOT / 'ci').rglob('*'))
    paths += [ROOT / p for p in ('.dockerignore', 'compose.yaml', 'config/uploads.ini', 'config/security.ini', 'config/security.conf',
              'ci/Dockerfile.app', 'ci/Dockerfile.cli', 'ci/Dockerfile.db', 'ci/images.json', 'ci/compose.runtime.yaml',
              'scripts/init-wordpress.sh', 'scripts/install-local.php', 'Jenkinsfile',
              'scripts/ci-security.py', 'scripts/ci-publish-security.py', 'scripts/security-tests.sh')]
    manifest = {}
    for path in sorted(paths):
        if path.is_symlink():
            raise ValueError('Source symlinks are not permitted')
        if path.is_file():
            manifest[path.relative_to(ROOT).as_posix()] = hashlib.sha256(path.read_bytes()).hexdigest()
    return hashlib.sha256(json.dumps(manifest, sort_keys=True).encode()).hexdigest()


def image_id(reference):
    value = output(['docker', 'image', 'inspect', reference, '--format', '{{.Id}}'])
    if not ID.fullmatch(value):
        raise ValueError('Invalid Docker image ID')
    return value


def current_lock():
    lock = read(PRIVATE / 'images.json')
    if not lock.get('complete') or set(lock['images']) != set(ROLES) or lock['source_sha256'] != source_hash():
        raise ValueError('Images or application sources changed after construction')
    for record in lock['images'].values():
        if not ID.fullmatch(record['id']) or image_id(record['id']) != record['id']:
            raise ValueError('A constructed image is missing or changed')
    return lock


def build():
    PRIVATE.mkdir(parents=True, exist_ok=True)
    (PRIVATE / 'gate.json').unlink(missing_ok=True)
    (PRIVATE / 'images.json').unlink(missing_ok=True)
    pinned = read(ROOT / 'ci/images.json')
    if any(not re.search(r'@sha256:[a-f0-9]{64}\Z', ref) for ref in pinned.values()):
        raise ValueError('All base and scanner images must be pinned by digest')
    # Jenkins BUILD_TAG is unique across jobs/builds, with no shell interpolation.
    label = re.sub('[^a-z0-9_.-]', '-', os.environ.get('BUILD_TAG', 'local-' + str(time.time_ns())).lower())[:110]
    before = source_hash()
    images = {}
    lock = {'run': label, 'commit': output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD']),
            'source_sha256': before, 'scanner': pinned['trivy'], 'images': images, 'complete': False}
    write(PRIVATE / 'images.json', lock)
    for role, base in (('app', 'wordpress'), ('cli', 'cli'), ('db', 'db'), ('mailpit', 'mail_runtime')):
        tag = 'ascla-ci-' + role + ':' + label
        extra = ['--build-arg', 'ENTRYPOINT_IMAGE=' + pinned['db_entrypoint']] if role == 'db' else []
        run(['docker', 'build', '--pull', '--label', 'org.ascla.ci=true', '--tag', tag,
             '--build-arg', 'BASE_IMAGE=' + pinned[base], '--build-arg', 'SECURITY_REFRESH=' + label,
             *extra, '-f', str(ROOT / ('ci/Dockerfile.' + role)), str(ROOT)])
        images[role] = {'reference': tag, 'id': image_id(tag), 'built': True}
        write(PRIVATE / 'images.json', lock)
    if before != source_hash():
        raise ValueError('Application changed while building images')
    lock['complete'] = True
    write(PRIVATE / 'images.json', lock)
    print('Constructed and locked all four runtime images.', flush=True)


def evaluate(report, expected_id):
    """LOW alone passes. Unknown severity or incomplete/mismatched scan fails closed."""
    if not isinstance(report, dict) or report.get('SchemaVersion') != 2:
        raise ValueError('Missing or unsupported Trivy schema')
    if not isinstance(report.get('Metadata'), dict) or report['Metadata'].get('ImageID') != expected_id:
        raise ValueError('Trivy report does not identify the image being deployed')
    results = report.get('Results')
    if not isinstance(results, list) or not any(r.get('Class') == 'os-pkgs' for r in results if isinstance(r, dict)):
        raise ValueError('Missing OS package analysis for a runtime image')
    counts = Counter({s: 0 for s in SEVERITIES})
    for result in results:
        if not isinstance(result, dict):
            raise ValueError('Invalid Trivy result')
        if result.get('VulnerabilityIgnoreSummaries') or result.get('ModifiedFindings'):
            raise ValueError('Suppressed or modified findings are not permitted')
        vulnerabilities = result.get('Vulnerabilities', [])
        if not isinstance(vulnerabilities, list):
            raise ValueError('Invalid vulnerability list')
        for finding in vulnerabilities:
            if not isinstance(finding, dict):
                raise ValueError('Invalid vulnerability record')
            severity = finding.get('Severity')
            if severity not in SEVERITIES or not finding.get('VulnerabilityID') or not finding.get('PkgName'):
                raise ValueError('Incomplete vulnerability or unknown severity label')
            # Status and absence of a fix never exempt a finding from this policy.
            counts[severity] += 1
    blocked = sum(counts[s] for s in SEVERITIES if s != 'LOW')
    return {'status': 'BLOCKED' if blocked else 'PASS', 'counts': dict(counts), 'blocking': blocked}


def archive_config_id(archive):
    # Docker's containerd store uses a manifest/index digest for .Id, while
    # Trivy reports the OCI config digest. Bind both through the saved archive.
    with tarfile.open(archive) as tar:
        manifest = json.load(tar.extractfile('manifest.json'))
        if len(manifest) != 1:
            raise ValueError('Expected exactly one image in the archive')
        config = tar.extractfile(manifest[0]['Config']).read(8 * 1024 * 1024 + 1)
        if len(config) > 8 * 1024 * 1024:
            raise ValueError('Unexpected oversized OCI image config')
        return 'sha256:' + hashlib.sha256(config).hexdigest()


STYLE = 'body{font:15px/1.65 system-ui;background:#f3f7fb;color:#18334d;margin:0}main{max-width:1200px;margin:auto;padding:36px 24px}h1{font-size:30px}section{background:white;border:1px solid #dce5ed;padding:24px;border-radius:14px;margin:20px 0}table{width:100%;border-collapse:collapse}td,th{padding:12px;text-align:left;border-bottom:1px solid #dce5ed}a{color:#106b9c}code{overflow-wrap:anywhere}.scroll{overflow:auto}.BLOCKED,.ERROR{color:#a62532}.PASS{color:#156e53}.counts{display:flex;gap:12px;flex-wrap:wrap}.counts span{padding:12px 18px;background:#edf4fa;border-radius:9px}small{color:#536579}'


def render(summary):
    rows = ''
    totals = Counter({s: 0 for s in SEVERITIES})
    for record in summary['images']:
        totals.update(record.get('counts', {}))
        links = f'<a href="{record["role"]}.json">JSON</a>' if (OUT / (record['role'] + '.json')).exists() else ''
        if (OUT / (record['role'] + '.html')).exists():
            links += f' · <a href="{record["role"]}.html">HTML detallado</a>'
        rows += '<tr><td><b>' + escape(record['role']) + '</b><br><small>' + escape(record['reference']) + '</small></td><td class="' + record['status'] + '">' + record['status'] + '</td><td>' + escape(str(record.get('counts', record.get('error', '')))) + '</td><td>' + links + '</td></tr>'
    cards = ''.join('<span><b>' + str(totals[s]) + '</b> ' + s + '</span>' for s in reversed(SEVERITIES))
    body = '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trivy · ASCLA</title><style>' + STYLE + '</style><main><small>ASCLA · Control obligatorio de imágenes Docker</small><h1>Trivy: <span class="' + summary['status'] + '">' + summary['status'] + '</span></h1><p>Solo LOW está permitido. MEDIUM, HIGH y CRITICAL bloquean el despliegue, tengan o no corrección. UNKNOWN y los errores técnicos también lo bloquean.</p><div class="counts">' + cards + '</div><section><h2>Esta ejecución</h2><p>UTC: ' + escape(summary['date']) + '<br>Commit: <code>' + escape(summary.get('commit', '')) + '</code><br>Fuentes: <code>' + escape(summary.get('source_sha256', '')) + '</code><br>Escáner: <code>' + escape(summary.get('scanner', '')) + '</code></p><p>' + escape(summary.get('error', 'Se analiza cada imagen por su ID inmutable. El JSON completo y el HTML detallado proceden del mismo análisis, sin recortar los hallazgos.')) + '</p></section><section><h2>Imágenes y resultados</h2><div class="scroll"><table><tr><th>Imagen</th><th>Resultado</th><th>Severidades</th><th>Artifacts</th></tr>' + rows + '</table></div></section><p><a href="summary.json">Resumen JSON</a> · Conservar solo la última ejecución. Un reporte no equivale a autorizar el despliegue.</p></main></html>'
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / 'index.html').write_text(body, encoding='utf-8')
    write(OUT / 'summary.json', summary)


def scan():
    OUT.mkdir(parents=True, exist_ok=True)
    (PRIVATE / 'gate.json').unlink(missing_ok=True)
    # Clear only this tool's known outputs; never ingest a previous build's report.
    for name in ['index.html', 'summary.json'] + [r + suffix for r in ROLES for suffix in ('.json', '.html', '.log')]:
        (OUT / name).unlink(missing_ok=True)
    summary = {'date': datetime.now(timezone.utc).isoformat(), 'status': 'ERROR', 'images': []}
    try:
        lock = current_lock()
        summary.update({key: lock[key] for key in ('commit', 'source_sha256', 'scanner', 'run')})
        run(['docker', 'pull', lock['scanner']])
        for role, image in lock['images'].items():
            record = {'role': role, **image, 'status': 'ERROR'}
            archive = PRIVATE / (role + '.tar')
            try:
                run(['docker', 'image', 'save', '-o', str(archive), image['id']])
                record['config_id'] = archive_config_id(archive)
                # Scanner gets this image archive only, never the host Docker socket,
                # repository config, credentials or TRIVY_* host environment.
                base = ['docker', 'run', '--rm', '-v', str(archive) + ':/scan/image.tar:ro',
                        '-v', str(OUT) + ':/reports', '-v', 'ascla_trivy_db:/root/.cache/trivy',
                        lock['scanner']]
                with (OUT / (role + '.log')).open('w', encoding='utf-8') as log:
                    run(base + ['--config', '', 'image', '--input', '/scan/image.tar', '--scanners', 'vuln',
                        '--ignorefile', '/dev/null', '--ignore-unfixed=false', '--severity', ','.join(SEVERITIES),
                        '--exit-code', '0', '--timeout', '15m', '--format', 'json', '--output', '/reports/' + role + '.json'], stdout=log, stderr=subprocess.STDOUT)
                    report = read(OUT / (role + '.json'))
                    record.update(evaluate(report, record['config_id']))
                    # Render on Linux storage, then copy once. Direct template writes
                    # to a Windows bind mount cause thousands of tiny synchronous IOs.
                    convert = 'trivy --config "" convert --format template --template @/contrib/html.tpl --output /tmp/report.html /reports/' + role + '.json && cp /tmp/report.html /reports/' + role + '.html'
                    run(['docker', 'run', '--rm', '-v', str(OUT) + ':/reports', '--entrypoint', 'sh',
                         lock['scanner'], '-c', convert], stdout=log, stderr=subprocess.STDOUT, timeout=180)
                record['report_sha256'] = hashlib.sha256((OUT / (role + '.json')).read_bytes()).hexdigest()
            except (OSError, ValueError, KeyError, tarfile.TarError, subprocess.SubprocessError) as exc:
                record.update(status='ERROR', error=str(exc))
            finally:
                archive.unlink(missing_ok=True)
            summary['images'].append(record)
            print(role + ': ' + record['status'] + ' ' + str(record.get('counts', {})), flush=True)
        current_lock()
        summary['status'] = 'ERROR' if any(r['status'] == 'ERROR' for r in summary['images']) else 'BLOCKED' if any(r['status'] == 'BLOCKED' for r in summary['images']) else 'PASS'
        if summary['status'] == 'PASS':
            write(PRIVATE / 'gate.json', summary)
    except (OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
        summary.update(status='ERROR', error=str(exc))
    render(summary)
    return 0 if summary['status'] == 'PASS' else 1


def verify_gate():
    lock = current_lock()
    gate = read(PRIVATE / 'gate.json')
    if gate.get('status') != 'PASS' or gate.get('run') != lock['run'] or gate.get('source_sha256') != lock['source_sha256']:
        raise ValueError('Deployment denied: no successful gate for this construction')
    if {r['role'] for r in gate['images']} != set(ROLES):
        raise ValueError('Deployment denied: incomplete gate')
    for record in gate['images']:
        if record['id'] != lock['images'][record['role']]['id'] or record['status'] != 'PASS':
            raise ValueError('Deployment denied: image changed')
        report = OUT / (record['role'] + '.json')
        if hashlib.sha256(report.read_bytes()).hexdigest() != record['report_sha256'] or evaluate(read(report), record['config_id'])['status'] != 'PASS':
            raise ValueError('Deployment denied: report changed or policy failed')
    return lock


def runtime_env(lock):
    return {**os.environ, **{'ASCLA_CI_' + r.upper(): i['id'] for r, i in lock['images'].items()}}


def deploy():
    lock = verify_gate()  # Must happen BEFORE compose up, or use of deployment credentials.
    env_file = os.environ['ENV_FILE']
    project = os.environ['PROYECTO']
    env = runtime_env(lock)
    env['COMPOSE_PROJECT_NAME'] = project
    dc = ['docker', 'compose', '--project-directory', str(ROOT), '-f', str(ROOT / 'ci/compose.runtime.yaml'), '--env-file', env_file, '-p', project]
    run(dc + ['config', '--quiet'], env=env)
    if subprocess.run(['docker', 'network', 'inspect', 'proxy_net'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        run(['docker', 'network', 'create', 'proxy_net'])
    run(dc + ['stop', 'cron'], env=env)
    run(dc + ['up', '-d', '--no-build', '--pull', 'never', '--renew-anon-volumes', '--remove-orphans', 'db', 'mailpit', 'wordpress'], env=env)
    ready = False
    for _ in range(24):
        result = subprocess.run(dc + ['run', '--rm', 'cli', 'sh', '/opt/ascla-scripts/init-wordpress.sh'], env=env)
        if result.returncode == 0:
            ready = True
            break
        time.sleep(5)
    if not ready:
        raise ValueError('WordPress initialization failed')
    run(dc + ['run', '--rm', 'cli', 'sh', '-c', 'if [ "${ASCLA_SEED_DATA:-false}" = true ]; then wp ascla seed; fi'], env=env)
    run(dc + ['up', '-d', '--no-build', '--pull', 'never', '--force-recreate', 'cron'], env=env)
    for service, role in (('wordpress', 'app'), ('cron', 'cli'), ('db', 'db'), ('mailpit', 'mailpit')):
        container = subprocess.check_output(dc + ['ps', '-q', service], env=env, text=True).strip()
        if not container or output(['docker', 'inspect', '--format', '{{.Image}}', container]) != lock['images'][role]['id']:
            raise ValueError('Deployed container does not match the scanned image: ' + service)
    run(dc + ['run', '--rm', 'cli', 'wp', 'plugin', 'is-active', 'ascla-core'], env=env)
    run(dc + ['exec', '-T', 'wordpress', 'php', '-r', '$s=@fsockopen("127.0.0.1",80,$e,$m,3); if($s){fclose($s);exit(0);} exit(1);'], env=env)
    print('Deployment verified: only the four scanned image IDs are running.')


def clean():
    # Remove only our build tags, not shared base images, running containers or data.
    lock_file = PRIVATE / 'images.json'
    if lock_file.exists():
        for role, record in read(lock_file).get('images', {}).items():
            if role in ROLES and record.get('built') and re.fullmatch(r'ascla-ci-(app|cli|db|mailpit):[a-z0-9_.-]+', record.get('reference', '')):
                containers = output(['docker', 'ps', '-aq', '--filter', 'ancestor=' + record['id']])
                if not containers:
                    run(['docker', 'image', 'rm', record['reference']])
    for role in ROLES:
        (PRIVATE / (role + '.tar')).unlink(missing_ok=True)


def main():
    command = sys.argv[1] if len(sys.argv) == 2 else ''
    if command == 'build':
        build()
    elif command == 'scan':
        return scan()
    elif command == 'verify':
        verify_gate()
    elif command == 'env':
        for role, record in current_lock()['images'].items():
            print('ASCLA_CI_' + role.upper() + '=' + record['id'])
    elif command == 'deploy':
        deploy()
    elif command == 'clean':
        clean()
    else:
        raise ValueError('Expected build, scan, verify, env, deploy or clean')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        print('CI SECURITY ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
