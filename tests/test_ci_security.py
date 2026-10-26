"""Gate behavior, identity, stale evidence, error handling and publication privacy."""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import hashlib
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('ci_security', ROOT / 'scripts/ci-security.py')
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)
IMAGE = 'sha256:' + 'a' * 64


def report(severity=None, fixed=True):
    findings = [] if severity is None else [{'VulnerabilityID': 'CVE-TEST-0001', 'PkgName': 'test-package', 'Severity': severity, 'FixedVersion': '2.0' if fixed else ''}]
    return {'SchemaVersion': 2, 'Metadata': {'ImageID': IMAGE}, 'Results': [{'Target': 'Synthetic policy fixture', 'Class': 'os-pkgs', 'Type': 'debian', 'Vulnerabilities': findings}]}


class ImagePolicyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        (ROOT / 'test-results').mkdir(exist_ok=True)

    def test_no_vulnerabilities_pass(self):
        self.assertEqual(ci.evaluate(report(), IMAGE)['status'], 'PASS')

    def test_runtime_build_inputs_are_bound_to_source_hash(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory:
            context=Path(directory)
            with patch.object(ci,'ROOT',context):
                for relative in ('ci/mail/package-lock.json','ci/apache-alpine.conf','.dockerignore'):
                    path=context/relative;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('original')
                    before=ci.source_hash();path.write_text('changed')
                    self.assertNotEqual(before,ci.source_hash(),relative)

    def test_low_is_allowed_and_reported(self):
        actual = ci.evaluate(report('LOW'), IMAGE)
        self.assertEqual(actual['status'], 'PASS')
        self.assertEqual(actual['counts']['LOW'], 1)

    def test_each_forbidden_severity_blocks_with_or_without_fix(self):
        for severity in ('MEDIUM', 'HIGH', 'CRITICAL', 'UNKNOWN'):
            for fixed in (True, False):
                with self.subTest(severity=severity, fixed=fixed):
                    self.assertEqual(ci.evaluate(report(severity, fixed), IMAGE)['status'], 'BLOCKED')

    def test_will_not_fix_and_not_affected_are_not_exceptions(self):
        for status in ('not_affected', 'will_not_fix', 'fix_deferred', 'end_of_life'):
            data = report('MEDIUM', False)
            data['Results'][0]['Vulnerabilities'][0]['Status'] = status
            self.assertEqual(ci.evaluate(data, IMAGE)['status'], 'BLOCKED')

    def test_different_image_denied(self):
        data = report()
        with self.assertRaises(ValueError):
            ci.evaluate(data, 'sha256:' + 'b' * 64)

    def test_incomplete_or_unrecognized_scan_denied(self):
        for data in ({}, [], {'SchemaVersion': 1}, {**report(), 'Results': []}, {**report(), 'Results': None}):
            with self.subTest(data=data), self.assertRaises(ValueError):
                ci.evaluate(data, IMAGE)

    def test_invalid_vulnerability_denied(self):
        for bad in ({}, {'Severity': 'NONE'}, {'Severity': 'LOW', 'VulnerabilityID': 'CVE-TEST'}, {'Severity': 'custom', 'VulnerabilityID': 'CVE-TEST', 'PkgName': 'x'}):
            data = report()
            data['Results'][0]['Vulnerabilities'] = [bad]
            with self.assertRaises(ValueError):
                ci.evaluate(data, IMAGE)

    def test_suppression_denied(self):
        for field in ('VulnerabilityIgnoreSummaries', 'ModifiedFindings'):
            data = report()
            data['Results'][0][field] = [{'ignored': True}]
            with self.assertRaises(ValueError):
                ci.evaluate(data, IMAGE)

    def test_complete_report_is_not_truncated(self):
        data = report('HIGH')
        data['Results'][0]['Vulnerabilities'] *= 12000
        self.assertEqual(ci.evaluate(data, IMAGE)['counts']['HIGH'], 12000)

    def test_missing_gate_cannot_deploy_or_call_compose(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory, patch.object(ci, 'PRIVATE', Path(directory)), patch.object(ci, 'current_lock', return_value={'images': {}}), patch.object(ci, 'run') as run:
            with self.assertRaises(FileNotFoundError):
                ci.deploy()
            run.assert_not_called()

    def test_scan_error_returns_failure_and_writes_html(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory, patch.object(ci, 'PRIVATE', Path(directory)), patch.object(ci, 'OUT', Path(directory) / 'reports'), patch.object(ci, 'current_lock', side_effect=ValueError('Technical scan fixture')):
            self.assertEqual(ci.scan(), 1)
            self.assertEqual(ci.read(Path(directory) / 'reports/summary.json')['status'], 'ERROR')
            self.assertIn('ERROR', (Path(directory) / 'reports/index.html').read_text())
            self.assertFalse((Path(directory) / 'gate.json').exists())

    def test_stale_gate_is_removed_on_technical_error(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory, patch.object(ci, 'PRIVATE', Path(directory)), patch.object(ci, 'OUT', Path(directory) / 'reports'), patch.object(ci, 'current_lock', side_effect=ValueError('Invalid construction')):
            ci.write(Path(directory) / 'gate.json', {'status': 'PASS', 'run': 'previous-build'})
            self.assertEqual(ci.scan(), 1)
            self.assertFalse((Path(directory) / 'gate.json').exists())

    def test_gate_revalidates_every_image_report_and_run(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory, patch.object(ci, 'PRIVATE', Path(directory)), patch.object(ci, 'OUT', Path(directory) / 'reports'):
            lock = {'run': 'current-build', 'source_sha256': 'current-source', 'images': {role: {'id': IMAGE} for role in ci.ROLES}}
            gate = {'status': 'PASS', 'run': lock['run'], 'source_sha256': lock['source_sha256'], 'images': []}
            for role in ci.ROLES:
                file = ci.OUT / (role + '.json')
                ci.write(file, report('LOW'))
                gate['images'].append({'role': role, 'id': IMAGE, 'config_id': IMAGE, 'status': 'PASS', 'report_sha256': hashlib.sha256(file.read_bytes()).hexdigest()})
            with patch.object(ci, 'current_lock', return_value=lock):
                ci.write(ci.PRIVATE / 'gate.json', gate)
                self.assertEqual(ci.verify_gate(), lock)
                for key, value in [('run', 'old-build'), ('status', 'BLOCKED'), ('source_sha256', 'changed-source')]:
                    altered = copy.deepcopy(gate)
                    altered[key] = value
                    ci.write(ci.PRIVATE / 'gate.json', altered)
                    with self.assertRaises(ValueError):
                        ci.verify_gate()
                ci.write(ci.PRIVATE / 'gate.json', {**gate, 'images': gate['images'][:-1]})
                with self.assertRaises(ValueError):
                    ci.verify_gate()
                ci.write(ci.PRIVATE / 'gate.json', gate)
                ci.write(ci.OUT / 'app.json', report('MEDIUM'))
                with self.assertRaises(ValueError):
                    ci.verify_gate()

    def test_publication_excludes_http_secrets_and_query_values(self):
        spec = importlib.util.spec_from_file_location('publish_security', ROOT / 'scripts/ci-publish-security.py')
        publisher = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(publisher)
        with tempfile.TemporaryDirectory(dir=ROOT / 'test-results') as directory, patch.object(publisher, 'SOURCE', Path(directory) / 'raw'), patch.object(publisher, 'OUT', Path(directory) / 'public'):
            ci.write(publisher.SOURCE / 'security-summary.json', {'date': 'fixture', 'status': 'FAIL', 'phases': [{'phase': 'frontend', 'status': 'FAIL'}]})
            ci.write(publisher.SOURCE / 'frontend-result.json', {'status': 'FAIL', 'risk_counts': {'0': 0, '1': 0, '2': 1, '3': 0}, 'scope': ['https://fixture.invalid/path?token=PRIVATE_QUERY']})
            ci.write(publisher.SOURCE / 'frontend-zap.json', {'site': [{'alerts': [{'name': 'Test finding', 'riskcode': '2', 'instances': [{'uri': 'https://fixture.invalid/path?token=PRIVATE_QUERY', 'request-header': 'Cookie: PRIVATE_COOKIE', 'response-body': 'PRIVATE_BODY'}]}]}]})
            publisher.publish()
            outputs = ''.join(p.read_text(encoding='utf-8') for p in publisher.OUT.iterdir())
            for secret in ('PRIVATE_QUERY', 'PRIVATE_COOKIE', 'PRIVATE_BODY'):
                self.assertNotIn(secret, outputs)
            self.assertIn('FAIL', outputs)
            self.assertIn('/path', outputs)

    def test_pipeline_keeps_security_and_has_no_skip_parameter(self):
        source = (ROOT / 'Jenkinsfile').read_text(encoding='utf-8')
        for text in ('ci-security.py build', 'ci-security.py scan', 'ci-security.py verify', 'ci-security.py deploy', 'waitForQualityGate abortPipeline: true', 'bash scripts/security-tests.sh', 'removeLastBuild: true', "artifactNumToKeepStr: '1'", 'archiveArtifacts'):
            self.assertIn(text, source)
        self.assertNotIn('parameters {', source)
        self.assertNotIn('catchError(', source)
        self.assertLess(source.index('ci-security.py verify'), source.index('withCredentials('))

    def test_sonar_uses_branch_project_credentials_and_exact_coverage_revision(self):
        source = (ROOT / 'Jenkinsfile').read_text(encoding='utf-8')
        self.assertIn("env.SONAR_PROJECT = 'ASCLA-' + env.ASCLA_BRANCH.toUpperCase()", source)
        self.assertIn('ASCLA_SONAR_${env.ASCLA_BRANCH.toUpperCase()}', source)
        self.assertIn('-Dsonar.projectKey=$SONAR_PROJECT', source)
        self.assertIn('-Dsonar.scm.revision=$GIT_COMMIT', source)
        self.assertIn('test -s coverage/source-revision', source)
        self.assertIn('scm.branches', source)
        self.assertNotIn('env.GIT_BRANCH', source)
        self.assertIn('Configure exactly one explicit SCM branch', source)
        self.assertIn("env.GIT_COMMIT = sh(returnStdout: true, script: 'git rev-parse HEAD').trim()", source)


if __name__ == '__main__':
    unittest.main()
