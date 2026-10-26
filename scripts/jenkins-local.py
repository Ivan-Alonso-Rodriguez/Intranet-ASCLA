#!/usr/bin/env python3
"""Explicitly authorized, loopback-only Jenkins lab; secrets never printed or committed."""
from pathlib import Path
import base64
import http.cookiejar
import json
import os
import secrets
import shutil
import subprocess
import sys
import time
import urllib.request
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
LAB = ROOT / 'test-results/jenkins-local'
URL = 'http://127.0.0.1:18090'


def dc(*args):
    env = {**os.environ, 'ASCLA_DOCKER_GID': str(os.stat('/var/run/docker.sock').st_gid), 'ASCLA_JENKINS_SOURCE': str(LAB / 'source'), 'ASCLA_JENKINS_PRIVATE': str(LAB / 'private'), 'ASCLA_JENKINS_WORKSPACES': str(LAB / 'workspaces')}
    return subprocess.run(['docker', 'compose', '-f', str(ROOT / 'ci/jenkins/compose.yaml'), '-p', 'ascla-jenkins-local', *args], env=env, check=True)


def client():
    password = (LAB / 'private/admin-password').read_text().strip()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    opener.addheaders = [('Authorization', 'Basic ' + base64.b64encode(('ascla.local:' + password).encode()).decode())]
    return opener


def request(path, data=None, content_type=None):
    opener = client()
    headers = {}
    if data is not None:
        with opener.open(URL + '/crumbIssuer/api/json', timeout=15) as response:
            crumb = json.load(response)
        headers[crumb['crumbRequestField']] = crumb['crumb']
        headers['Content-Type'] = content_type or 'application/x-www-form-urlencoded'
    with opener.open(urllib.request.Request(URL + path, data=data, headers=headers), timeout=60) as response:
        return response.read()


def start():
    (LAB / 'private').mkdir(parents=True, exist_ok=True)
    (LAB / 'workspaces').mkdir(exist_ok=True)
    password = LAB / 'private/admin-password'
    if not password.exists():
        password.write_text(secrets.token_urlsafe(32))
        password.chmod(0o600)
    # Snapshot only Git-visible source files, including pending requested CI changes.
    source = LAB / 'source'
    if source.resolve().parent != LAB.resolve() or source.name != 'source':
        raise ValueError('Unexpected snapshot path')
    source.mkdir(exist_ok=True)
    files = subprocess.check_output(['git', '-C', str(ROOT), 'ls-files', '--cached', '--others', '--exclude-standard', '-z']).decode().split('\0')
    for name in set(files) - {''}:
        original = ROOT / name
        if not original.is_file():
            continue
        if original.is_symlink() or not original.resolve().is_relative_to(ROOT.resolve()):
            raise ValueError('Unexpected source link')
        target = source / name
        target.parent.mkdir(parents=True, exist_ok=True)
        if not target.exists() or original.read_bytes() != target.read_bytes():
            shutil.copyfile(original, target)
    if (source / '.git').exists():
        previous = subprocess.check_output(['git', '-C', str(source), 'ls-files', '-z']).decode().split('\0')
        for name in set(previous) - set(files) - {''}:
            stale = source / name
            if not stale.resolve().is_relative_to(source.resolve()):
                raise ValueError('Unexpected snapshot deletion')
            stale.unlink(missing_ok=True)
    os.chdir('/')
    os.chdir(ROOT)
    for args in (['init', '--initial-branch=development'], ['config', 'user.name', 'ASCLA local verification'], ['config', 'user.email', 'ci@example.invalid'], ['add', '--all'], ['commit', '--allow-empty', '-m', 'Local snapshot for authorized CI verification']):
        subprocess.run(['git', '-C', str(source), *args], check=True, stdout=subprocess.DEVNULL)
    # The local job validates the real pipeline on its protected, non-deploying branch.
    subprocess.run(['git','-C',str(source),'update-ref','refs/heads/main','HEAD'],check=True)
    dc('up', '-d', '--no-build')
    os.chdir('/')
    os.chdir(ROOT)
    print('Jenkins lab starting at ' + URL + '; private credentials stored in ignored test-results/jenkins-local/private.')


if __name__ == '__main__':
    command = sys.argv[1]
    if command == 'start':
        start()
    elif command == 'status':
        print(request('/job/ascla-local/api/json?tree=builds[number,result,building,artifacts[fileName,relativePath]],lastBuild[number,result,building]').decode())
    elif command == 'run':
        request('/job/ascla-local/build', b'')
        print('Local Jenkins build requested.')
    elif command == 'lint':
        data = urllib.parse.urlencode({'jenkinsfile': (ROOT / 'Jenkinsfile').read_text(encoding='utf-8')}).encode()
        print(request('/pipeline-model-converter/validate', data).decode())
    elif command == 'plugins':
        data = json.loads(request('/pluginManager/api/json?tree=plugins[shortName,version,active]'))
        plugins = sorted(p['shortName'] + ':' + p['version'] for p in data['plugins'] if p['active'])
        (ROOT / 'ci/jenkins/plugins.txt').write_text('\n'.join(plugins) + '\n')
        print(str(len(plugins)) + ' active plugin versions pinned.')
    elif command == 'console':
        print(request('/job/ascla-local/lastBuild/consoleText').decode())
    elif command == 'stop':
        dc('down', '--volumes', '--remove-orphans')
        (LAB / 'private/admin-password').unlink(missing_ok=True)
        for name in ('source', 'workspaces'):
            target = LAB / name
            if target.exists():
                if target.resolve().parent != LAB.resolve() or not target.resolve().is_relative_to(ROOT.resolve()):
                    raise ValueError('Unexpected cleanup target')
                shutil.rmtree(target)
    else:
        raise ValueError('Use start, status, run, lint, console or stop')
