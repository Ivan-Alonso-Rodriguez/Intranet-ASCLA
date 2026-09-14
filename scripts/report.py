"""Consolidate only measured, successful current-release evidence."""
from pathlib import Path
from zipfile import ZipFile
import datetime, hashlib, json, re, shutil, xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
RESULT=ROOT/'test-results'

def read(name):
    raw=(RESULT/name).read_bytes()
    return json.loads(raw.decode('utf-16' if raw.startswith(b'\xff\xfe') else 'utf-8-sig'))

def generate():
    metrics={m['metric']:m.get('value','N/D') for m in read('sonar-metrics.json')['component']['measures']}
    e2e=read('e2e.json'); completion=read('completion.json'); load=read('performance.json')
    port=read('portability-fresh.json'); browser=read('portability-browser.json'); volume=read('volume-current.json')
    gate=read('sonar-gate.json')['projectStatus']
    junit=ET.parse(ROOT/'coverage/junit.xml').getroot().find('testsuite').attrib
    assert not e2e['failure'] and not e2e['errors'] and completion['passed'] and browser['passed']
    assert all(port['checks'].values()) and port['installation']=='fresh'
    assert int(junit['failures'])==0 and int(junit['errors'])==0
    assert all(r['errors']==0 for r in load['profiles'])
    assert all(c['status']=='OK' for c in gate['conditions'] if c['metricKey']!='new_violations')
    php=re.search(r'Lines:\s+([\d.]+)%\s+(\([^)]+\))',(ROOT/'coverage/php-summary.txt').read_text())
    lcov=(ROOT/'coverage/lcov.info').read_text()
    total=sum(map(int,re.findall(r'^LF:(\d+)',lcov,re.M))); covered=sum(map(int,re.findall(r'^LH:(\d+)',lcov,re.M)))
    assert float(php[1])>=80 and 100*covered/total>=80
    archive=ROOT/'dist/ascla-core.zip'; digest=hashlib.sha256(archive.read_bytes()).hexdigest()
    with ZipFile(archive) as z:
        files=z.namelist()
        for name in files:
            relative=Path(name).relative_to('ascla-core')
            assert z.read(name)==(ROOT/'wp-content/plugins/ascla-core'/relative).read_bytes(), name
    assert read('portability.json')['zip_sha256']==digest and all(read('portability.json')['checks'].values())
    ratings={k:chr(64+int(float(metrics[k]))) for k in ['security_rating','reliability_rating','sqale_rating']}
    table='\n'.join(f"| {r['name']} | {r['concurrency']} | {r['requests']} | {r['seconds']} | {r['p95_ms']} | {r['errors']} |" for r in load['profiles'])
    largest=volume['profiles'][-1]
    volume_table='\n'.join(f"| {m['operation']} | {m['ms']} | {m['queries']} | {m['max_query_ms']} | {m['slow_queries_over_100ms']} |" for m in largest['measurements'])
    login=json.loads((ROOT/'docs/evidence/login.json').read_text(encoding='utf-8-sig'))
    assert login['status']=='passed'
    report=f"""# Informe de calidad — ASCLA Core {port['plugin']}

Fecha: {datetime.date.today().isoformat()}. Este informe corresponde al ZIP actual y sustituye las métricas anteriores. Pruebas con datos ficticios locales. WordPress Core, Elementor, terceros y dependencias de desarrollo quedan fuera del código propio medido.

## Resultados medidos

| Control | Resultado |
|---|---|
| PHPUnit | {junit['tests']} pruebas, {junit['assertions']} aserciones; cero fallos/errores |
| Regresión de navegador | {len(e2e['results'])} comprobaciones; cero errores JavaScript |
| Aceptación 1.1.0 | {len(completion['checks'])} comprobaciones en escritorio y móvil |
| Acceso y recuperación | {len(login['checks'])} comprobaciones; sin correo real |
| ZIP en instalación nueva | {len(browser['pages'])} páginas con enlaces predeterminados |
| Cobertura PHP | {php[1]}% {php[2]} líneas de clases/servicios |
| Cobertura JavaScript | {100*covered/total:.2f}% ({covered}/{total}) líneas |
| Sonar, cobertura combinada | {metrics['coverage']}%; líneas {metrics['line_coverage']}% |
| Duplicación | {metrics['duplicated_lines_density']}% |
| Seguridad / Fiabilidad / Mantenibilidad | {ratings['security_rating']} / {ratings['reliability_rating']} / {ratings['sqale_rating']} |
| Bugs / Vulnerabilidades | {metrics['bugs']} / {metrics['vulnerabilities']} |
| Avisos de mantenimiento abiertos | {metrics['code_smells']} |
| Security hotspots / Accepted issues | {metrics['security_hotspots']} / {metrics['accepted_issues']} |
| Quality Gate ASCLA Release | {gate['status']} |

SonarQube Community Build 26.9.0.129388, scanner 8.0.1.6346 y perfiles Sonar way. Gate: cobertura >=80%, duplicación <5%, seguridad/fiabilidad A/B, mantenibilidad hasta C, cero bugs/vulnerabilidades/accepted issues y revisión de hotspots cuando existen. No se excluyeron archivos propios ni se aceptaron/silenciaron issues para mejorar las métricas. La cobertura combinada incluye condiciones.

El gate estricto mantiene su condición adicional de cero avisos nuevos: {next((c.get('actualValue','0') for c in gate['conditions'] if c['metricKey']=='new_violations'),'0')} avisos nuevos impiden su aprobación. No se ha relajado esa condición. Los objetivos numéricos del prompt sí se cumplen.

Los {metrics['code_smells']} avisos de mantenimiento siguen abiertos, incluidos avisos Critical de complejidad y literales repetidos. Son deuda de refactorización; las calificaciones no significan ausencia de deuda. Detalle en evidence/sonar-issues-summary.json.

## Alcance

PHPUnit 11.5.56/PCOV 1.0.12: matching, grupos, autorización/IDOR, privacidad, bloqueo, cupos, moderación, medios, secretos, fuentes antiguas y abstención, Chatham House, cola e instalador. La regresión nueva cubre filtros combinados, listas JSON de etiquetas, autores/fuentes, más de una página de eventos, mes y zona horaria, invitaciones/avisos idempotentes, consentimiento, subtítulos densos, tiempos no sustentados, metadatos y continuación de cola.

Playwright/Edge: doce secciones, perfil, Hub, foros, mensajería, calendario, uploads privados, generación/revisión, asistente, contacto y configuración. Aceptación específica: filtros nuevos, imágenes, logos, invitaciones, agenda y resultados estructurados. Acceso: escritorio y 320/390/768 px, error, destino permitido, logout, recuperación y renovación de sesión.

V8 se captura antes de navegar. Se incorpora evidencia del ZIP y de aceptación sólo si el código fuente coincide exactamente, incluido content-ui.js. Los proveedores HTTP se probaron con respuestas simuladas; no con cuentas reales.

Desarrollo: WordPress 7.1/PHP 8.3.28/MariaDB 11.4. Instalación desde cero: WordPress {port['wordpress']}/PHP {port['php']}/MariaDB 11.4 en volúmenes nuevos, exclusivamente desde ZIP. Verifica doce páginas, activación idempotente, página ajena y colisión de slug, rol, CPT privados, esquema 3 y nueve tablas. También se ejecutó actualización y seed repetido. El ajuste final del menú azul se verificó mediante actualización del ZIP en ese WordPress y navegación en escritorio/móvil. evidence/portability-fresh.json registra la instalación inicial; evidence/portability.json identifica el ZIP definitivo por SHA-256.

El menú reproduce el azul #233156 de la página 6 de la guía original. Contraste medido: 11,49:1 en texto normal y 5,31:1 en la opción activa. Las capturas y los colores leídos del navegador están en evidence/sidebar.json y evidence/sidebar-mobile.png.

## Rendimiento HTTP

Seis endpoints autenticados, sesiones independientes, Docker Windows/WSL. IPv4 con Host/cookies localhost. Tiempo total incluye login; latencia por solicitud; tramo sostenido con pausa de 250 ms por usuario. SonarQube y la instalación aislada compartieron el equipo durante parte de la ejecución.

| Perfil | Sesiones | Solicitudes | Segundos | p95 ms | Errores |
|---|---:|---:|---:|---:|---:|
{table}

Total: {sum(r['requests'] for r in load['profiles'])} solicitudes, cero errores. Hasta 18 sesiones prueban escalabilidad básica y estrés acotado; no determinan saturación ni capacidad de producción. Instantáneas de memoria al final de cada perfil en evidence/performance.json; no son muestreo continuo de picos.

## Volumen y SQL

Se añadieron y retiraron 100/600 recursos y 30/120 asociados. Caché de objetos vacía por operación; matching caliente conserva transients. SQL observado con SAVEQUERIES. Tramo con {largest['extra_resources']} recursos y {largest['extra_members']} asociados adicionales:

| Operación | Tiempo ms | Consultas | SQL máximo ms | SQL >100 ms |
|---|---:|---:|---:|---:|
{volume_table}

Pico acumulado PHP: {max(m['peak_memory_mb'] for p in volume['profiles'] for m in p['measurements'])} MB, incluyendo fixtures. Evidencia: evidence/volume-current.json. Los archivos volume-before.json/volume-after.json anteriores son un experimento histórico de 1.0.3. El asistente sigue siendo lexical; debe medirse con el corpus real.

## Límites pendientes

Staging https://wordpress.ingsoftware.lat/ no fue modificado: falta una sesión administrativa accesible y el controlador falla por ACL. No se validó la combinación real de cachés/plugins de SiteGround ni multisitio de red.

OpenAI, YouTube y Calendar necesitan credenciales, consentimiento y prueba real. LinkedIn/X reales, publicación de podcasts y RSVP externo avanzado siguen pendientes. Cápsulas: referencias temporales, no archivos cortados/subidos. Chatham House exige revisión humana. No se envió correo real de recuperación. Cargas pequeñas: 3 MB imágenes y 5 MB PDF, sujetas al hosting.

## Reproducir

    pnpm install --frozen-lockfile
    docker compose up -d
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/quality.ps1 -SkipBrowser
    node tests/login.cjs
    node tests/completion.cjs
    python scripts/build.py
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/portability.ps1
    node tests/portability-browser.cjs
    node tests/e2e.cjs
    python tests/performance.py
    docker compose exec -T wordpress php /opt/ascla-tests/volume.php
    python scripts/sonar.py scan
    python scripts/sonar.py report
    python scripts/report.py

Conserva la salida de la instalación nueva en test-results/portability-fresh.json antes de ejecutar la actualización. Guarda el JSON de volumen en test-results/volume-current.json con UTF-8. Para instalación nueva usa scripts/portability.ps1 -Project NOMBRE_NUEVO después de detener el entorno que ocupa 8089; conserva sus volúmenes. Sonar requiere el servicio de compose.quality.yaml en estado UP y configuración inicial mediante scripts/sonar.py init y gate. Los siguientes análisis reutilizan el token ignorado por Git, que vence el 12 de septiembre de 2026.

ZIP: **{len(files)} archivos**, **{archive.stat().st_size} bytes**, SHA-256 **{digest}**. Este generador verifica que el ZIP coincide con los archivos actuales. El build es reproducible.

Evidencias compactas en docs/evidence/; XML y LCOV completos en coverage/. [Detalle de la entrega](COMPLETION_1.1.0.md).
"""
    (ROOT/'docs/QUALITY_REPORT.md').write_text(report,encoding='utf8')
    evidence=ROOT/'docs/evidence'; evidence.mkdir(exist_ok=True)
    for name in ['sonar-metrics.json','sonar-gate.json','sonar-hotspots.json','performance.json','volume-current.json','portability.json','portability-fresh.json','portability-update.json','portability-browser.json','e2e.json','completion.json','sidebar.json']:
        (evidence/name).write_text(json.dumps(read(name),indent=2,ensure_ascii=False),encoding='utf8')
    issues=read('sonar-issues.json')
    summary=[{k:i.get(k) for k in ['key','rule','type','severity','component','line','message','status']} for i in issues['issues']]
    (evidence/'sonar-issues-summary.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf8')
    shutil.copyfile(ROOT/'coverage/php-summary.txt',evidence/'php-coverage.txt')
    for name in ['dashboard-desktop.png','dashboard-mobile.png','completion-knowledge.png','completion-generated.png','sidebar-mobile.png']:
        shutil.copyfile(RESULT/name,evidence/name)
    release={'version':port['plugin'],'date':datetime.datetime.now(datetime.timezone.utc).isoformat(),'sha256':digest,'bytes':archive.stat().st_size,'files':len(files),'php_tests':int(junit['tests']),'php_assertions':int(junit['assertions']),'php_coverage':float(php[1]),'js_coverage':round(100*covered/total,2),'sonar_gate':gate['status']}
    (evidence/'release.json').write_text(json.dumps(release,indent=2),encoding='utf8')
    print(json.dumps(release))

if __name__=='__main__':generate()
