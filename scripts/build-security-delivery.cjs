/* Publish a portable, reviewed view of an existing local security run. Never runs ZAP. */
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const read = file => JSON.parse(fs.readFileSync(file, 'utf8'));
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const E = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
const json = value => JSON.stringify(value, null, 2) + '\n';
const clean = value => String(value ?? '').replace(/<[^>]*>/g, '').replace(/nonce-[a-z0-9+/=]{8,}/gi, 'nonce-[retirado]');
const route = value => new URL(value, 'https://local.invalid').pathname;
const localDate = date => new Intl.DateTimeFormat('es-PE', {dateStyle:'long', timeStyle:'short', timeZone:'America/Lima'}).format(new Date(date));
const style = `body{font:16px/1.65 system-ui;margin:0;background:#f1f5f9;color:#163047}main{max-width:1100px;margin:30px auto;padding:25px}h1{font-size:32px;line-height:1.2}h2{font-size:23px}section,details,.card{background:white;padding:22px;border:1px solid #dce5ee;border-radius:12px;margin:18px 0}a{color:#1658a2}.stats{display:flex;gap:16px;flex-wrap:wrap}.card{flex:1;min-width:145px}.card strong{display:block;font-size:30px}.PASS{color:#11663b;font-weight:bold}.FAIL,.ERROR{color:#b02929;font-weight:bold}.WARN{color:#815000;font-weight:bold}.muted,small{color:#526a7d}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #dce5ee}.table-wrap{overflow-x:auto}code{overflow-wrap:anywhere}summary{cursor:pointer}li{margin:7px 0}@media(max-width:650px){main{padding:14px}.card{padding:16px}td,th{padding:8px}h1{font-size:27px}}`;
const page = (title, body) => `<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${E(title)} · ASCLA</title><style>${style}</style><main><p class="muted">ASCLA · Pruebas de seguridad · Evidencia local</p><h1>${E(title)}</h1>${body}</main></html>\n`;

function files(folder) {
  return fs.readdirSync(folder, {withFileTypes:true}).sort((a,b) => a.name.localeCompare(b.name)).flatMap(entry => {
    if (entry.isSymbolicLink()) throw Error('No se publican enlaces simbólicos.');
    const file = path.join(folder, entry.name);
    return entry.isDirectory() ? files(file) : [file];
  });
}

function publishSecurity({root = path.resolve(__dirname, '..'), source, refreshManifest = true} = {}) {
  const delivery = path.join(root, 'pruebas');
  const output = path.join(delivery, 'seguridad');
  source = path.resolve(source || path.join(root, 'test-results/security-latest'));
  let publication;
  if (fs.existsSync(path.join(source, 'security-summary.json'))) {
    const inputs = new Map();
    const load = name => {
      const file = path.join(source, name), bytes = fs.readFileSync(file);
      const relative = path.relative(root, file).replaceAll(path.sep, '/');
      if (relative.startsWith('../') || path.isAbsolute(relative)) throw Error('La fuente debe estar dentro del repositorio.');
      inputs.set(name, {source_relative:relative, source_sha256:sha(bytes)});
      return JSON.parse(bytes.toString('utf8'));
    };
    const original = load('security-summary.json');
    const sourceManifest = load('source-manifest.json');
    const cspOriginal = load('csp-validation.json');
    const browserOriginal = load('browser-security.json');
    const artifacts = new Map();
    const records = [];
    const add = (name, bytes, originalName) => {
      bytes = Buffer.from(bytes);
      artifacts.set(name, bytes);
      records.push({published:'seguridad/' + name, published_sha256:sha(bytes), transformed:true,
        ...(originalName ? inputs.get(originalName) : {})});
    };
    const scans = {};
    for (const kind of ['frontend', 'backend']) {
      const result = load(kind + '-result.json');
      const native = load(kind + '-zap.json');
      const alerts = native.site.flatMap(site => site.alerts || []).map(alert => ({
        id:String(alert.alertRef || alert.pluginid), name:clean(alert.name), risk:Number(alert.riskcode),
        confidence:Number(alert.confidence), count:Number(alert.count),
        description:clean(alert.desc), solution:clean(alert.solution),
        locations:[...new Map((alert.instances || []).map(item => {
          const location = {path:route(item.uri), method:item.method};
          return [JSON.stringify(location), location];
        })).values()]
      }));
      const counts = Object.fromEntries([0,1,2,3].map(risk => [String(risk), alerts.filter(a => a.risk === risk).length]));
      if (JSON.stringify(counts) !== JSON.stringify(result.risk_counts)) throw Error('Las alertas no coinciden con el resumen ZAP: ' + kind);
      const phase = original.phases.find(item => item.phase === kind);
      if (!phase || phase.status !== result.status || JSON.stringify(phase.risk_counts) !== JSON.stringify(counts)) throw Error('El resultado conjunto no coincide con ZAP: ' + kind);
      const report = {format:'ascla-public-zap-summary-v1', kind, date:original.date, status:result.status,
        tool:{name:native['@programName'], version:result.engine_version, generated:native['@generated']},
        scope:result.scope.map(route), phases:result.phases, risk_counts:counts,
        authenticated_before:result.authenticated_before, authenticated_after:result.authenticated_after,
        active_complete:result.active_complete, active_strength:result.active_strength,
        completed_active_rules:result.completed_active_rules, active_request_count:result.active_request_count,
        client_spider_limit_seconds:result.client_spider_limit_seconds,
        not_applicable_active_rules:(result.not_applicable_active_rules || []).map(item => ({id:item.id, name:item.name, reason:clean(item.reason)})),
        alerts, publication_note:'Resumen publicable del informe nativo. Se omiten cabeceras, cuerpos HTTP, cookies, nonces, payloads y valores de consulta. Se conservan estado y conteos.'};
      scans[kind] = report;
      add(kind + '-zap.json', json(report), kind + '-zap.json');
      const label = kind === 'frontend' ? 'Páginas de ASCLA' : 'API de ASCLA';
      add(kind + '-zap.html', page('ZAP · ' + label,
        `<p><a href="dashboard.html">Volver a Seguridad</a> · <a href="sustento.html">Cómo sustentar los resultados y revisar los 20 controles</a> · <a href="${kind}-zap.json">Resumen JSON</a></p><p>Resumen publicable de la ejecución del ${E(localDate(original.date))} (hora de Lima). Estado original: <b class="${E(result.status)}">${E(result.status)}</b>.</p><p>${E(report.publication_note)}</p><section><h2>Alertas por severidad</h2><p>Altas: <b>${counts['3']}</b> · Medias: <b>${counts['2']}</b> · Bajas: <b>${counts['1']}</b> · Informativas: <b>${counts['0']}</b>.</p><p>Intensidad del análisis activo: ${E(result.active_strength)}. Reglas completadas: ${result.completed_active_rules.length}. Solicitudes activas: ${result.active_request_count}.</p></section>${alerts.map(alert => `<details><summary>${E(alert.name)} · ${['Informativa','Baja','Media','Alta'][alert.risk]} · ${alert.count} instancias</summary><p>Regla ${E(alert.id)}.</p><p>${E(alert.description)}</p>${alert.solution ? '<p><b>Recomendación:</b> ' + E(alert.solution) + '</p>' : ''}<ul>${alert.locations.map(location => '<li><code>' + E(location.method) + ' ' + E(location.path) + '</code></li>').join('')}</ul></details>`).join('')}<section><h2>Rutas incluidas</h2><ul>${report.scope.map(item => '<li><code>' + E(item) + '</code></li>').join('')}</ul></section>`), kind + '-zap.json');
    }
    const csp = {url:new URL(cspOriginal.url).origin + route(cspOriginal.url), fecha:cspOriginal.fecha,
      estado:cspOriginal.estado, validaciones:cspOriginal.validaciones.map(item => ({nombre:clean(item.nombre), cumple:item.cumple}))};
    const browser = {checks:browserOriginal.checks.map(clean), errors:browserOriginal.errors.map(clean),
      violations:browserOriginal.violations.map(item => ({role:clean(item.role), text:clean(item.text)})),
      responses:browserOriginal.responses.map(item => ({role:clean(item.role), path:route(item.path), status:item.status}))};
    const summary = {format:'ascla-public-security-summary-v1', date:original.date, status:original.status,
      environment:'WordPress local HTTPS temporal', source_sha256:sourceManifest.source_sha256,
      phases:original.phases.map(item => ({phase:item.phase, status:item.status, ...(item.risk_counts ? {risk_counts:item.risk_counts} : {}), ...(item.error ? {error:clean(item.error)} : {})})),
      pages:scans.frontend.scope.length, api_get_operations:scans.backend.scope.length,
      csp_checks:csp.validaciones.length, browser_checks:browser.checks.length, browser_views:browser.responses.length,
      jenkins_evidence:false,
      limitations:[
        'Ejecución local con la fecha y las fuentes indicadas; publicar esta carpeta no vuelve a ejecutar las pruebas ni acredita el servidor publicado.',
        'El análisis incluye rutas HTML propias de ASCLA y 18 operaciones GET REST; excluye escrituras, wp-admin nativo, rutas de otros plugins y las integraciones externas reales.',
        'El escaneo activo utiliza intensidad LOW y el Client Spider limita su navegación a 60 segundos. Log4Shell no aplica al entorno PHP/Apache/MariaDB.',
        'La integración automática en Jenkins y la configuración del hosting quedan pendientes de acreditar. Este resultado no equivale a aprobar UAT.'
      ]};
    add('resumen.json', json(summary), 'security-summary.json');
    add('csp-validation.json', json(csp), 'csp-validation.json');
    add('browser-security.json', json(browser), 'browser-security.json');
    // These exact source bytes were fingerprinted by the executed security run.
    const testSources = Object.entries(sourceManifest.files).filter(([name]) => !name.startsWith('wp-content/plugins/'));
    add('source-manifest.json', json(sourceManifest), 'source-manifest.json');
    for (const [name, expected] of testSources) {
      if (name.startsWith('/') || name.split('/').includes('..')) throw Error('Ruta de fuente de seguridad no válida.');
      const bytes = fs.readFileSync(path.join(root,name));
      if (sha(bytes) !== expected) throw Error('La fuente ya no corresponde al escaneo local: ' + name);
      add('sources/' + name, bytes);
      Object.assign(records.at(-1), {source_relative:name, source_sha256:expected, transformed:false});
    }
    add('fuentes.html', page('Fuentes de las pruebas locales de seguridad',
      `<p><a href="dashboard.html">Seguridad</a> · <a href="sustento.html">Cómo sustentar resultados</a> · <a href="source-manifest.json">Manifiesto de la ejecución</a></p><p>Fecha de la ejecución: ${E(localDate(original.date))} (Lima). Estas ${testSources.length} copias coinciden byte a byte con las huellas registradas durante el análisis local. El manifiesto identifica además los archivos del plugin analizado y la imagen ZAP fijada.</p><div class="table-wrap"><table><thead><tr><th>Archivo de prueba o configuración</th><th>SHA-256 de la ejecución</th></tr></thead><tbody>${testSources.map(([name,digest])=>`<tr><td><a href="sources/${E(name)}">${E(name)}</a></td><td><code>${E(digest)}</code></td></tr>`).join('')}</tbody></table></div><section><h2>Repetir en un checkout del repositorio</h2><p>Desde la raíz del repositorio, con Bash/WSL y Docker Linux disponibles:</p><pre>bash scripts/security-tests.sh</pre><p>El lanzador, sus fixtures y los archivos Compose se incluyen en el repositorio. El comando crea un entorno HTTPS desechable, genera credenciales temporales y retira sus recursos al terminar. Publicar estas copias no ejecuta otro escaneo. Los originales completos y datos de sesión permanecen fuera de Git.</p><p>Para reconstruir la entrega desde una ejecución terminada, usa <code>node scripts/build-qa-delivery.cjs</code> y sus verificadores, según el manual del proyecto.</p></section>`), 'source-manifest.json');
    const row = (label, scan) => `<tr><th>${label}</th><td class="${E(scan.status)}">${E(scan.status)}</td><td>${scan.risk_counts['3']}</td><td>${scan.risk_counts['2']}</td><td>${scan.risk_counts['1']}</td><td>${scan.risk_counts['0']}</td></tr>`;
    add('dashboard.html', page('Seguridad · OWASP ZAP y CSP',
      `<p><a href="../index.html">Todas las pruebas</a> · <a href="sustento.html">Cómo sustentar la seguridad</a> · <a href="fuentes.html">Fuentes de las pruebas locales</a> · <a href="../README.md#seguridad">Guía y alcance</a></p><p>Resultado registrado: <b class="${E(summary.status)}">${E(summary.status)}</b> · ${E(localDate(summary.date))} (hora de Lima).</p><p><b>Pruebas ejecutadas en un WordPress local HTTPS temporal.</b> Esta página reúne su evidencia original; no representa una nueva ejecución.</p><div class="stats"><div class="card"><strong>${summary.pages}</strong>Rutas de páginas analizadas</div><div class="card"><strong>${summary.api_get_operations}</strong>Operaciones GET de la API</div><div class="card"><strong>${summary.csp_checks}</strong>Comprobaciones CSP</div><div class="card"><strong>${summary.browser_checks}</strong>Controles de navegador</div></div><section><h2>Qué se hizo</h2><ul><li><b>Páginas:</b> descubrimiento con Traditional Spider y Client Spider, análisis activo y revisión pasiva de las respuestas HTTP.</li><li><b>API:</b> importación del contrato OpenAPI y análisis de operaciones GET autenticadas.</li><li><b>CSP:</b> validación independiente de HTTPS, cabeceras, restricciones de scripts y nonce diferente por respuesta.</li><li><b>Navegador:</b> recorridos con administrador y asociado, guardado del perfil, idioma y permisos bajo CSP; ${summary.browser_views} vistas revisadas.</li></ul></section><section><h2>Resultados ZAP</h2><div class="table-wrap"><table><thead><tr><th>Alcance</th><th>Estado</th><th>High / Altas</th><th>Medium / Medias</th><th>Bajas</th><th>Informativas</th></tr></thead><tbody>${row('Páginas',scans.frontend)}${row('API',scans.backend)}</tbody></table></div><p>Se conservan las alertas informativas. El estado y los conteos proceden de los resultados originales.</p><p><a href="frontend-zap.html">Ver análisis de páginas</a> · <a href="backend-zap.html">Ver análisis de API</a></p></section><section><h2>Validación CSP · <span class="${E(csp.estado)}">${E(csp.estado)}</span></h2><div class="table-wrap"><table><thead><tr><th>Comprobación</th><th>Resultado</th></tr></thead><tbody>${csp.validaciones.map(item => '<tr><td>' + E(item.nombre) + '</td><td class="' + (item.cumple ? 'PASS' : 'FAIL') + '">' + (item.cumple ? 'Aprobada' : 'Fallida') + '</td></tr>').join('')}</tbody></table></div><p><a href="csp-validation.json">Resultado CSP en JSON</a></p></section><section><h2>Comprobaciones en el navegador</h2><ul>${browser.checks.map(item => '<li>' + E(item) + '</li>').join('')}</ul><p>Errores JavaScript registrados: ${browser.errors.length}. Infracciones CSP registradas: ${browser.violations.length}.</p><p><a href="browser-security.json">Ver resultado del navegador</a></p></section><section><h2>Alcance y pendientes</h2><ul>${summary.limitations.map(item => '<li>' + E(item) + '</li>').join('')}</ul><p>La cobertura de los casos del plan se consulta en <a href="../trazabilidad.json">la trazabilidad funcional</a>; estos controles de seguridad se presentan por separado.</p></section><section><h2>Reportes y procedencia</h2><p>Las copias publicables omiten datos de sesión, cabeceras completas y cuerpos HTTP. Los originales se conservan en el entorno local.</p><p><a href="resumen.json">Resultado conjunto</a> · <a href="frontend-zap.json">ZAP páginas JSON</a> · <a href="backend-zap.json">ZAP API JSON</a> · <a href="procedencia.json">Fechas y huellas de los originales</a> · <a href="../manifest.json">Huellas de la entrega</a></p><p>Identificador de las fuentes analizadas: <code>${E(summary.source_sha256)}</code>.</p></section>`), 'security-summary.json');
    publication = {prepared_at:new Date().toISOString(), date:summary.date, status:summary.status,
      source_sha256:summary.source_sha256, report:'seguridad/dashboard.html', retention:'latest-only',
      note:'Copias derivadas y revisadas; los HTML/JSON nativos completos permanecen privados. Se preservan las fechas, los estados y los conteos originales.',
      sources:[...inputs.values()], files:records};
    artifacts.set('procedencia.json', Buffer.from(json(publication)));
    // Construct and inspect every public byte before writing any security artifact.
    for (const [name, bytes] of artifacts) {
      const text = bytes.toString('utf8');
      // Exact, hash-verified source copies contain header variable references, not recorded HTTP.
      const pattern = name.startsWith('sources/')
        ? /wordpress_logged_in_[a-f0-9]+=|PRIVATE KEY|[a-z]:[\\/]+Users[\\/]/i
        : /wordpress_logged_in_[a-f0-9]+=|(?:Cookie|Authorization):\s*\S|nonce-[a-z0-9+/=]{8,}|PRIVATE KEY|[a-z]:[\\/]+Users[\\/]/i;
      if (pattern.test(text)) throw Error('Dato privado detectado en la copia de seguridad: ' + name);
    }
    fs.mkdirSync(output, {recursive:true});
    for (const [name, bytes] of artifacts) {
      const file = path.join(output, name);
      fs.mkdirSync(path.dirname(file), {recursive:true});
      fs.writeFileSync(file, bytes);
    }
  } else if (fs.existsSync(path.join(output, 'procedencia.json'))) {
    // A checkout without private originals can keep the already published evidence.
    publication = read(path.join(output, 'procedencia.json'));
  } else {
    return null;
  }
  const summary = read(path.join(output, 'resumen.json'));
  const card = `<!-- security-delivery:start --><section><h2>3. Seguridad con OWASP ZAP y CSP</h2><p>Escaneo de páginas y API, validación CSP y comprobaciones en navegador. Resultado local: <b>${E(summary.status)}</b> · ${E(localDate(summary.date))} (Lima).</p><a href="seguridad/dashboard.html">Abrir resultados de seguridad</a><p>${summary.pages} rutas HTML · ${summary.api_get_operations} operaciones GET · ${summary.csp_checks} comprobaciones CSP. La integración en Jenkins queda pendiente de acreditar.</p></section><!-- security-delivery:end -->`;
  const indexPath = path.join(delivery, 'index.html');
  let index = fs.readFileSync(indexPath, 'utf8').replace(/<!-- security-delivery:start -->[\s\S]*?<!-- security-delivery:end -->/g, '');
  const insertion = '</div><section><h2>Base documental y alcance</h2>';
  if (!index.includes(insertion)) throw Error('No se encontró la sección para enlazar Seguridad en el dashboard general.');
  index = index.replace(insertion, card + insertion)
    .replace(/<title>[^<]*<\/title>/, '<title>Pruebas de integración, E2E y seguridad · ASCLA</title>')
    .replace(/<h1>[^<]*<\/h1>/, '<h1>Pruebas de integración, E2E y seguridad</h1>');
  fs.writeFileSync(indexPath, index);
  const provenancePath = path.join(delivery, 'procedencia.json');
  const provenance = read(provenancePath);
  provenance.security = {date:publication.date, status:publication.status, source_sha256:publication.source_sha256,
    report:publication.report, prepared_at:publication.prepared_at, environment:'local-ephemeral-https', jenkins_evidence:false};
  provenance.files = provenance.files.filter(item => !item.published.startsWith('seguridad/')).concat(publication.files,
    {published:'seguridad/procedencia.json', published_sha256:sha(fs.readFileSync(path.join(output,'procedencia.json'))), transformed:true});
  fs.writeFileSync(provenancePath, json(provenance));
  if (refreshManifest) {
    const manifest = {generated_at:new Date().toISOString(), algorithm:'SHA-256', files:{}};
    for (const file of files(delivery)) if (file !== path.join(delivery,'manifest.json')) manifest.files[path.relative(delivery,file).replaceAll(path.sep,'/')] = sha(fs.readFileSync(file));
    fs.writeFileSync(path.join(delivery,'manifest.json'), json(manifest));
  }
  return provenance.security;
}

module.exports = {publishSecurity};
if (require.main === module) {
  const result = publishSecurity();
  if (!result) throw Error('No se encontró una ejecución de seguridad para publicar.');
  console.log(JSON.stringify(result));
}
