#!/usr/bin/env python3
"""Verify the real local build, exported artifacts and SUCCESS -> FAILURE retention."""
from pathlib import Path
from html import escape
import hashlib
import importlib.util
import io
import json
import shutil
import subprocess
import tarfile
import tempfile
import time
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('jenkins_local', ROOT / 'scripts/jenkins-local.py')
local = importlib.util.module_from_spec(spec)
spec.loader.exec_module(local)
OUT = ROOT / 'test-results/ci-jenkins-latest'
OUT.mkdir(parents=True, exist_ok=True)


def get(path):
    return json.loads(local.request(path))


def verify_build():
    build = get('/job/ascla-local/lastBuild/api/json?tree=number,result,building,artifacts[fileName,relativePath],timestamp,duration')
    if build['building']:
        raise RuntimeError('Wait for the build to finish')
    # This directory is an export of one build, not an accumulating history.
    if OUT.resolve().parent != (ROOT / 'test-results').resolve() or OUT.name != 'ci-jenkins-latest':
        raise ValueError('Unexpected public artifact directory')
    shutil.rmtree(OUT)
    OUT.mkdir()
    prefix = '/job/ascla-local/' + str(build['number'])
    console = local.request(prefix + '/consoleText').decode()
    assert 'Stage "Desplegar y verificar" skipped' in console
    assert '+ python3 scripts/ci-security.py deploy' not in console
    assert '[Pipeline] archiveArtifacts' in console and '[Pipeline] publishHTML' in console
    files = []
    for artifact in build['artifacts']:
        name = artifact['relativePath']
        assert name.startswith('test-results/ci-artifacts/trivy/'), name
        relative = name.removeprefix('test-results/ci-artifacts/')
        path = OUT / relative
        assert path.resolve().is_relative_to(OUT.resolve())
        path.parent.mkdir(parents=True, exist_ok=True)
        data = local.request(prefix + '/artifact/' + name)
        path.write_bytes(data)
        files.append({'path': relative, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    summary = json.loads((OUT / 'trivy/summary.json').read_text())
    assert summary['status'] in ('PASS','BLOCKED'), summary
    assert build['result'] == ('SUCCESS' if summary['status']=='PASS' else 'FAILURE'), build
    assert {r['role'] for r in summary['images']} == {'app', 'cli', 'db', 'mailpit'}
    assert all(r['status'] in ('PASS', 'BLOCKED') and r.get('report_sha256') for r in summary['images'])
    assert len([r for r in files if r['path'].endswith('.html')]) == 5
    assert len([r for r in files if r['path'].endswith('.json')]) == 5
    for record in summary['images']:
        data = (OUT / ('trivy/' + record['role'] + '.json')).read_bytes()
        assert hashlib.sha256(data).hexdigest() == record['report_sha256']
    # Compare the committed bytes actually checked out by Jenkins, including
    # Git's line-ending normalization, rather than a dirty Windows working tree.
    ci_spec = importlib.util.spec_from_file_location('verified_ci_sources', ROOT / 'scripts/ci-security.py')
    ci = importlib.util.module_from_spec(ci_spec)
    ci_spec.loader.exec_module(ci)
    snapshot = subprocess.check_output(['git', '-C', str(local.LAB / 'source'), 'archive', summary['commit']])
    with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory:
        target = Path(directory)
        with tarfile.open(fileobj=io.BytesIO(snapshot)) as archive:
            for member in archive.getmembers():
                assert (target / member.name).resolve().is_relative_to(target.resolve())
                assert member.isfile() or member.isdir()
            archive.extractall(target, filter='data')
        ci.ROOT = target
        tested_hash = ci.source_hash()
    assert tested_hash == summary['source_sha256']
    source_result = {'snapshot_commit': summary['commit'], 'checkout_source_sha256': tested_hash,
                     'report_source_sha256': summary['source_sha256'], 'matches_tested_git_sources': True}
    (OUT / 'source-verification.json').write_text(json.dumps(source_result, indent=2) + '\n')
    builds = get('/job/ascla-local/api/json?tree=builds[number]')['builds']
    assert [b['number'] for b in builds] == [build['number']], builds
    (OUT / 'console.txt').write_text(console, encoding='utf-8')
    result = {'build': build, 'pipeline_deployment_skipped': True, 'all_four_images_analyzed': True,
              'image_policy_result':summary['status'],'html_and_json_archived': True, 'only_latest_main_build_retained': True, 'artifacts': files}
    (OUT / 'build-verification.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    (OUT / 'index.html').write_text('<!doctype html><html lang="es"><meta charset="utf-8"><title>Jenkins y Trivy · ASCLA</title><h1>Jenkins build ' + str(build['number']) + ': ' + escape(build['result']) + '</h1><p>Trivy: ' + escape(summary['status']) + '. Cuatro imágenes analizadas; despliegue omitido en el snapshot privado main.</p><p><a href="trivy/index.html">Informes HTML y JSON de Trivy</a> · <a href="build-verification.json">Verificación del build</a> · <a href="source-verification.json">Fuentes comprobadas</a> · <a href="retention-verification.json">Retención</a> · <a href="console.txt">Consola real</a></p>', encoding='utf-8')
    return result


def verify_retention():
    # This is a storage-policy fixture job only, with no scan/deploy functionality.
    job = 'ascla-retention-fixture'
    script = """pipeline {
      agent any
      options { buildDiscarder(logRotator(numToKeepStr: '1', artifactNumToKeepStr: '1', removeLastBuild: true)) }
      stages { stage('Storage policy fixture') { steps {
        writeFile file: 'fixture.html', text: '<h1>Storage policy fixture</h1>'
        script { if (env.BUILD_NUMBER.toInteger() > 1) { error('Expected fixture failure') } }
      } } }
      post { always { archiveArtifacts artifacts: 'fixture.html'; deleteDir() } }
    }"""
    config = '<flow-definition><description>Local artifact retention verification only</description><definition class="org.jenkinsci.plugins.workflow.cps.CpsFlowDefinition"><script>' + escape(script) + '</script><sandbox>true</sandbox></definition></flow-definition>'
    try:
        local.request('/job/' + job + '/doDelete', b'')
    except urllib.error.HTTPError as exc:
        if exc.code != 404:
            raise
    local.request('/createItem?name=' + job, config.encode(), 'application/xml')
    results = []
    for expected in (1, 2):
        local.request('/job/' + job + '/build', b'')
        deadline = time.monotonic() + 120
        while time.monotonic() < deadline:
            try:
                data = get('/job/' + job + '/lastBuild/api/json?tree=number,result,building,artifacts[fileName]')
                if data['number'] == expected and not data['building']:
                    results.append(data)
                    break
            except urllib.error.HTTPError as exc:
                if exc.code != 404:
                    raise
            time.sleep(2)
        else:
            raise TimeoutError('Retention fixture did not finish')
    assert [r['result'] for r in results] == ['SUCCESS', 'FAILURE'], results
    data = get('/job/' + job + '/api/json?tree=builds[number],lastSuccessfulBuild[number],lastStableBuild[number]')
    assert [b['number'] for b in data['builds']] == [2], data
    assert data['lastSuccessfulBuild'] is None and data['lastStableBuild'] is None, data
    assert [a['fileName'] for a in results[-1]['artifacts']] == ['fixture.html']
    result = {'success_followed_by_failure': True, 'older_success_and_its_artifacts_removed': True, 'latest_failure_artifact_preserved': True, 'results': results, 'job_after_retention': data}
    (OUT / 'retention-verification.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    return result


if __name__ == '__main__':
    main = verify_build()
    retention = verify_retention()
    print('Verified Jenkins build ' + str(main['build']['number']) + ': real Trivy '+main['image_policy_result']+', deployment skipped, 5 HTML + 5 JSON artifacts, latest-only retention including prior success.')
