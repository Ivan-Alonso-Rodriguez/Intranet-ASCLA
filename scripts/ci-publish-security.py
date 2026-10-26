#!/usr/bin/env python3
"""Allowlisted public ZAP artifacts. Never archive raw HTTP/session/credential files."""
from pathlib import Path
from html import escape
from urllib.parse import urlsplit
import datetime
import json
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'test-results/security-latest'
OUT = ROOT / 'test-results/ci-artifacts/zap'


def clean(value):
    return re.sub(r'nonce-[a-zA-Z0-9+/=]{8,}', 'nonce-[retirado]', re.sub('<[^>]*>', '', str(value or '')))


def publish():
    OUT.mkdir(parents=True, exist_ok=True)
    summary_file = SOURCE / 'security-summary.json'
    summary = json.loads(summary_file.read_text()) if summary_file.exists() else {'date': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'status': 'ERROR', 'phases': [], 'scope_note': 'No completed security analysis.'}
    public = {'date': summary['date'], 'status': summary['status'], 'scope_note': summary.get('scope_note', ''), 'phases': []}
    for phase in summary['phases']:
        public['phases'].append({k: phase[k] for k in ('phase', 'status', 'risk_counts') if k in phase})
    rows = ''
    for kind in ('frontend', 'backend'):
        result_file, native_file = SOURCE / (kind + '-result.json'), SOURCE / (kind + '-zap.json')
        if not result_file.exists() or not native_file.exists():
            continue
        result, native = json.loads(result_file.read_text()), json.loads(native_file.read_text())
        alerts = []
        for site in native.get('site', []):
            for alert in site.get('alerts', []):
                alerts.append({'id': str(alert.get('alertRef', alert.get('pluginid', ''))), 'name': clean(alert.get('name')),
                               'risk': int(alert['riskcode']), 'description': clean(alert.get('desc')),
                               'solution': clean(alert.get('solution')), 'locations': sorted({urlsplit(i['uri']).path for i in alert.get('instances', [])})})
        report = {k: result[k] for k in ('status', 'risk_counts', 'active_complete', 'authenticated_before', 'authenticated_after', 'completed_active_rules') if k in result}
        report.update(date=summary['date'], alerts=alerts, scope=[urlsplit(u).path for u in result.get('scope', [])])
        (OUT / (kind + '.json')).write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        details = ''.join('<details><summary>' + escape(a['name']) + ' · riesgo ' + str(a['risk']) + '</summary><p>' + escape(a['description']) + '</p><p>' + escape(a['solution']) + '</p><ul>' + ''.join('<li>' + escape(p) + '</li>' for p in a['locations']) + '</ul></details>' for a in alerts)
        (OUT / (kind + '.html')).write_text('<!doctype html><html lang="es"><meta charset="utf-8"><title>ZAP · ' + kind + '</title><h1>ZAP: ' + escape(result['status']) + '</h1><p>' + escape(str(result['risk_counts'])) + '</p><p>Resumen del reporte nativo, sin cabeceras, cuerpos HTTP, cookies, nonces ni valores de consulta.</p>' + details, encoding='utf-8')
        rows += '<li>' + kind + ': ' + escape(result['status']) + ' · <a href="' + kind + '.html">HTML</a> · <a href="' + kind + '.json">JSON</a></li>'
    (OUT / 'summary.json').write_text(json.dumps(public, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    (OUT / 'index.html').write_text('<!doctype html><html lang="es"><meta charset="utf-8"><title>ZAP y CSP · ASCLA</title><h1>ZAP y CSP: ' + escape(public['status']) + '</h1><p>' + escape(public['date']) + '</p><ul>' + rows + '</ul><p>' + escape(public['scope_note']) + '</p><a href="summary.json">Resultado conjunto JSON</a>', encoding='utf-8')
    print('Public ZAP artifacts exported; original security result remains ' + public['status'])


if __name__ == '__main__':
    publish()
