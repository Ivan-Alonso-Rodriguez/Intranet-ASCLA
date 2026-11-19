#!/usr/bin/env python3
"""Allowlisted public ZAP artifacts. Never archive raw HTTP/session/credential files."""
from pathlib import Path
from html import escape, unescape
from urllib.parse import urlsplit
import datetime
import json
import re

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / 'test-results/security-latest'
OUT = ROOT / 'test-results/ci-artifacts/zap'

# Presentation follows the self-contained ZAP Traditional HTML report. The logo
# is copied from ZAP 2.17.0's generated report; no remote assets are requested.
ZAP_LOGO = 'data:image/png;base64,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'
STYLE = '''
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; padding: 24px 30px; background: #fff; color: #111;
  font: 13px/1.55 "Helvetica Neue", Helvetica, Arial, sans-serif; }
main { max-width: 1440px; margin: auto; }
a { color: #075b9c; overflow-wrap: anywhere; }
h1 { display: flex; align-items: center; justify-content: center; gap: 20px;
  margin: 6px 0 26px; font-size: 30px; line-height: 1.2; }
h1 img { width: 215px; height: 71px; object-fit: contain; }
h2 { margin: 30px 0 10px; font-size: 18px; }
h3 { margin: 0 0 10px; font-size: 16px; }
p { margin: 8px 0; }
.metadata { border-bottom: 1px solid #d4d4d4; padding-bottom: 18px; }
.notice { padding: 12px 16px; margin: 18px 0; border: 1px solid #d9e3ed;
  border-left: 4px solid #1676a5; background: #f6f9fc; }
.table-wrap { overflow-x: auto; margin: 10px 0 20px; }
table { border-collapse: separate; border-spacing: 2px; width: 100%; font-size: 13px; }
td, th { padding: 8px 10px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
th { background: #666; color: white; }
td { background: #eee; }
.summary { width: min(100%, 640px); }
.summary td:last-child, .summary th:last-child { text-align: center; }
.risk-3 { background: #c60000; color: white; }
.risk-2 { background: #ef9d00; color: #111; }
.risk-1 { background: #fff36b; color: #111; }
.risk-0 { background: #1644b6; color: white; }
.level-low { background: #fff36b; color: #111; }
.level-info { background: #1644b6; color: white; }
.status { display: inline-block; padding: 4px 12px; border-radius: 4px;
  background: #eee; color: #222; font-weight: bold; }
.status-pass { background: #e3f3e9; color: #165c32; }
.status-fail, .status-error, .status-blocked { background: #fbe5e5; color: #9d1919; }
.status-warn { background: #fff1c6; color: #714500; }
.preserve-lines { white-space: pre-line; }
.alert-detail { scroll-margin-top: 20px; margin: 26px 0; }
.alert-detail th:first-child { width: 180px; }
.alert-detail td:first-child { width: 180px; font-weight: bold; }
.paths { margin: 0; padding-left: 20px; }
.muted, footer { color: #515151; }
.phases { display: grid; grid-template-columns: repeat(auto-fit,minmax(250px,1fr)); gap: 16px; margin: 24px 0; }
.phase { border: 1px solid #d9e0e6; padding: 18px; border-radius: 6px; }
.phase .status { margin-bottom: 10px; }
.phase p:last-child { margin-top: 18px; }
.nav { display: flex; flex-wrap: wrap; gap: 18px; margin-bottom: 18px; }
footer { margin: 34px 0 10px; padding-top: 16px; border-top: 1px solid #ccc; }
@media(max-width:600px) {
  body { padding: 16px 12px; }
  h1 { flex-direction: column; gap: 10px; font-size: 24px; text-align: center; }
  .alert-detail td:first-child, .alert-detail th:first-child { width: 100px; }
  .insights { min-width: 720px; }
  .phases { grid-template-columns: 1fr; }
}
@media print { body { padding: 0; } .nav { display: none; } .alert-detail { break-inside: avoid; } }
'''
RISK_NAMES = {'3': 'High', '2': 'Medium', '1': 'Low', '0': 'Informational'}


def clean(value):
    text = re.sub(r'<(?:br\s*/?|/p|/li)>', '\n', str(value or ''), flags=re.I)
    text = unescape(re.sub('<[^>]*>', '', text))
    text = re.sub(r'https?://[^\s<>"\']+', lambda match: safe_url(match.group()), text)
    return re.sub(r'nonce-[a-zA-Z0-9+/=]{8,}', 'nonce-[retirado]', text).strip()


def safe_url(value, origin=False):
    """Retain a report location without credentials, query values or fragments."""
    try:
        parts = urlsplit(str(value))
        if parts.scheme not in ('http', 'https') or not parts.hostname:
            return ''
        host = parts.hostname
        if ':' in host:
            host = '[' + host + ']'
        authority = host + (':' + str(parts.port) if parts.port else '')
        return parts.scheme + '://' + authority + ('' if origin else parts.path)
    except ValueError:
        return ''


def status_badge(status):
    classes = {'PASS': 'pass', 'FAIL': 'fail', 'ERROR': 'error', 'BLOCKED': 'blocked', 'WARN': 'warn'}
    return '<span class="status status-' + classes.get(status, 'error') + '">' + escape(status) + '</span>'


def document(title, body):
    return ('<!doctype html><html lang="es"><head><meta charset="utf-8">'
            '<meta name="viewport" content="width=device-width,initial-scale=1">'
            '<meta name="color-scheme" content="light"><title>' + escape(title) + '</title>'
            '<style>' + STYLE + '</style></head><body><main>' + body + '</main></body></html>')


def heading():
    return '<h1><img src="' + ZAP_LOGO + '" alt="ZAP by Checkmarx" width="215" height="71"><span>ZAP Scanning Report</span></h1>'


def alert_summary(counts):
    rows = ''.join('<tr><td class="risk-' + risk + '">' + label + '</td><td>' +
                   escape(str(counts.get(risk, 0))) + '</td></tr>' for risk, label in RISK_NAMES.items())
    return '<div class="table-wrap"><table class="summary"><thead><tr><th>Risk Level</th><th>Number of Alerts</th></tr></thead><tbody>' + rows + '</tbody></table></div>'


def export_metadata(native):
    sites = sorted({safe_url(site.get('@name', ''), origin=True) for site in native.get('site', [])} - {''})
    insights = [{k: clean(item.get(k, '')) for k in ('level', 'reason', 'key', 'description', 'statistic')}
                | {'site': safe_url(item.get('site', ''), origin=True)} for item in native.get('insights', [])]
    return {'engine': {'name': clean(native.get('@programName', 'ZAP')), 'version': clean(native.get('@version', '')),
                       'generated': clean(native.get('created', native.get('@generated', '')))},
            'sites': sites, 'insights': insights}


def insights_table(insights):
    if not insights:
        return '<p>No se registraron diagnósticos del motor en el reporte original.</p>'
    rows = ''
    for item in insights:
        level_class = {'Low': 'level-low', 'Info': 'level-info', 'Informational': 'level-info'}.get(item['level'], '')
        rows += '<tr><td class="' + level_class + '">' + escape(item['level']) + '</td>'
        rows += ''.join('<td>' + escape(item[key]) + '</td>' for key in ('reason', 'site', 'description', 'statistic')) + '</tr>'
    return ('<p class="muted">Diagnósticos originales del motor. Los warnings de esta tabla se muestran por separado de las alertas de seguridad.</p>'
            '<div class="table-wrap"><table class="insights"><thead><tr><th>Level</th><th>Reason</th><th>Site</th><th>Description</th><th>Statistic</th></tr></thead><tbody>' + rows + '</tbody></table></div>')


def render_report(kind, report):
    engine = report['engine']
    body = '<nav class="nav" aria-label="Reportes"><a href="index.html">← Todos los resultados</a><a href="' + kind + '.json">Descargar JSON</a></nav>' + heading()
    body += '<div class="metadata"><p><b>Site:</b> ' + escape(', '.join(report['sites']) or 'No especificado') + '</p>'
    body += '<p><b>Generated on:</b> ' + escape(engine['generated'] or report['date']) + '</p>'
    body += '<p><b>ZAP Version:</b> ' + escape(engine['version'] or 'No especificada') + '</p>'
    body += '<p>ZAP by <a href="https://checkmarx.com/">Checkmarx</a> · ' + escape(kind.upper()) + ' · ' + status_badge(report['status']) + '</p></div>'
    body += '<p class="notice">Edición para compartir del análisis original, con la presentación de ZAP. Se conservan los resultados; se omiten cabeceras, cuerpos HTTP, cookies, nonces y valores de consulta. Esta presentación no ejecuta un nuevo análisis.</p>'
    body += '<h2>Summary of Alerts</h2>' + alert_summary(report['risk_counts'])
    body += '<h2>Insights</h2>' + insights_table(report['insights'])
    rows, details = '', ''
    for index, alert in enumerate(report['alerts']):
        risk = str(alert['risk'])
        label = RISK_NAMES.get(risk, 'Unknown')
        anchor = 'alert-' + str(index + 1)
        rows += '<tr><td><a href="#' + anchor + '">' + escape(alert['name']) + '</a></td><td class="risk-' + risk + '">' + label + '</td><td>' + str(alert['count']) + '</td></tr>'
        locations = '<ul class="paths">' + ''.join('<li>' + escape(p) + '</li>' for p in alert['locations']) + '</ul>'
        detail_rows = [('Description', alert['description']), ('Solution', alert['solution']), ('Alert Reference', alert['id']), ('Confidence', alert['confidence'])]
        if alert['cwe']:
            detail_rows.append(('CWE ID', alert['cwe']))
        detail_body = ''.join('<tr><td>' + label_text + '</td><td class="preserve-lines">' + escape(value or 'No especificado') + '</td></tr>' for label_text, value in detail_rows)
        detail_body += '<tr><td>Locations</td><td>' + locations + '</td></tr><tr><td>Instances</td><td>' + str(alert['count']) + '</td></tr>'
        details += '<section class="alert-detail" id="' + anchor + '"><h3>' + escape(alert['name']) + '</h3><div class="table-wrap"><table><thead><tr><th class="risk-' + risk + '">' + label + '</th><th>' + escape(alert['name']) + '</th></tr></thead><tbody>' + detail_body + '</tbody></table></div></section>'
    body += '<h2>Alerts</h2><div class="table-wrap"><table><thead><tr><th>Name</th><th>Risk Level</th><th>Number of Instances</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    body += '<h2>Alert Detail</h2>' + (details or '<p>No se registraron alertas.</p>')
    body += '<footer>ASCLA · Reporte derivado de OWASP ZAP. El JSON adjunto conserva los datos publicables de esta vista. Los archivos HTTP originales se mantienen privados.</footer>'
    return document('ZAP Scanning Report · ' + kind, body)


def render_index(public, reports):
    body = heading() + '<div class="metadata"><h2>ASCLA · Seguridad de páginas y API</h2><p>' + status_badge(public['status']) + ' · ' + escape(public['date']) + '</p></div>'
    cards = ''
    for kind, report in reports.items():
        name = 'Páginas · Frontend' if kind == 'frontend' else 'API · Backend'
        cards += '<section class="phase"><h3>' + name + '</h3>' + status_badge(report['status']) + alert_summary(report['risk_counts'])
        cards += '<p><a href="' + kind + '.html">Abrir ZAP Scanning Report →</a> · <a href="' + kind + '.json">JSON</a></p></section>'
    body += '<div class="phases">' + cards + '</div><h2>Controles de la ejecución</h2>'
    rows = ''.join('<tr><td>' + escape(phase['phase']) + '</td><td>' + status_badge(phase['status']) + '</td></tr>' for phase in public['phases'])
    body += '<div class="table-wrap"><table><thead><tr><th>Control</th><th>Resultado</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
    body += '<h2>Alcance</h2><p>' + escape(public['scope_note']) + '</p><p class="notice">Presentación del análisis original; no constituye un nuevo escaneo. Los diagnósticos del motor y el detalle de alertas están dentro de cada reporte.</p>'
    body += '<footer><a href="summary.json">Resultado conjunto JSON</a> · ZAP by <a href="https://checkmarx.com/">Checkmarx</a> · ASCLA</footer>'
    return document('ZAP y CSP · ASCLA', body)


def publish():
    OUT.mkdir(parents=True, exist_ok=True)
    summary_file = SOURCE / 'security-summary.json'
    summary = json.loads(summary_file.read_text()) if summary_file.exists() else {'date': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'status': 'ERROR', 'phases': [], 'scope_note': 'No completed security analysis.'}
    public = {'date': summary['date'], 'status': summary['status'], 'scope_note': summary.get('scope_note', ''), 'phases': []}
    for phase in summary['phases']:
        public['phases'].append({k: phase[k] for k in ('phase', 'status', 'risk_counts') if k in phase})
    reports = {}
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
                               'solution': clean(alert.get('solution')), 'locations': sorted({urlsplit(i['uri']).path for i in alert.get('instances', [])}),
                               'count': int(alert.get('count', len(alert.get('instances', [])))),
                               'confidence': clean(alert.get('confidence')), 'cwe': clean(alert.get('cweid'))})
        report = {k: result[k] for k in ('status', 'risk_counts', 'active_complete', 'authenticated_before', 'authenticated_after', 'completed_active_rules') if k in result}
        report.update(date=summary['date'], alerts=alerts, scope=[urlsplit(u).path for u in result.get('scope', [])])
        report.update(export_metadata(native))
        (OUT / (kind + '.json')).write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
        (OUT / (kind + '.html')).write_text(render_report(kind, report), encoding='utf-8')
        reports[kind] = report
    (OUT / 'summary.json').write_text(json.dumps(public, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    (OUT / 'index.html').write_text(render_index(public, reports), encoding='utf-8')
    print('Public ZAP artifacts exported; original security result remains ' + public['status'])


if __name__ == '__main__':
    publish()
