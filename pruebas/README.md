# Pruebas de integración, E2E y seguridad de ASCLA

Esta carpeta reúne la entrega de pruebas para GitHub: colección Bruno con variables, explicación de los flujos, evidencias de Playwright y resultados publicables de seguridad con OWASP ZAP y CSP. Los resultados identifican su fecha y entorno; la fecha de preparación de esta carpeta no equivale a otra ejecución.

## Última versión desplegada: ejecución del 22 de octubre de 2026

La revisión del **22 de octubre de 2026** se ejecutó contra **https://wordpress.ingsoftware.lat**, con el plugin **1.10.9**: **81/81 peticiones y 149/149 comprobaciones de Bruno**, más **6/6 recorridos de Playwright con 12 videos y 12 capturas**. El JavaScript y el CSS desplegados coinciden por SHA-256 con el paquete local probado; las pruebas API/UI contrastan el comportamiento del backend. Esta comparación no descarga ni certifica todos los archivos PHP privados del servidor.

El asistente completó un trabajo real y conservó su hilo; el modo devuelto fue **Google Gemini · API real**. Las contraseñas de las tres cuentas dedicadas se renovaron con autorización del responsable y permanecen fuera de esta entrega. Los seis flujos terminan con Carla activa, la pareja desbloqueada y los eventos de prueba cancelados.

La [regresión reciente](actualizacion/index.html) añade **14/14 controles de interfaz, 16 videos y 16 capturas** sobre ese mismo QA: borradores en seis secciones; paridad administrativa del Ejecutivo con las dos excepciones; filtros de Usuarios e intereses; tipo de asociado en la cabecera; Directorio de 4 columnas × 3 filas; lista única de Foros; portada y cabecera de Galería; Archivos integrado; y menú de tres puntos de Aliados. Se retiraron a la papelera exclusivamente los nueve contenidos ficticios creados por esa ejecución. Estos controles son adicionales y no se cuentan como otros 14 casos únicos del plan.

## Los mismos seis flujos en Bruno y Playwright

| Flujo compartido | Bruno (peticiones / comprobaciones) | Playwright |
|---|---:|---|
| Perfil del asociado | 7 / 14 | Mismo flujo desde la interfaz |
| Conexión, chat y bloqueo | 12 / 24 | Mismo flujo desde la interfaz |
| Borrador, publicación e inscripción | 12 / 22 | Mismo flujo desde la interfaz |
| Aforo, lista de espera y notificación | 19 / 37 | Mismo flujo desde la interfaz |
| Suspensión y reactivación | 9 / 18 | Mismo flujo desde la interfaz |
| Consulta al asistente IA | 4 / 8 | Mismo flujo desde la interfaz |

Los seis flujos reúnen 63 peticiones y 123 comprobaciones de Bruno. La integración técnica añade 18 peticiones y 26 comprobaciones, total **81/149**. Playwright realiza los mismos pasos de negocio mediante clics y formularios, incluido el asistente IA; conserva **seis recorridos, 12 videos y 12 capturas**. El [catálogo comparado](flujos.json) y el dashboard impiden confundir sus resultados con los 14 controles UI adicionales.

## Abrir la entrega

- [Dashboard general](index.html): entrada a los resultados de backend, frontend y seguridad. Descarga la carpeta y abre el HTML en el navegador; GitHub permite consultar los MD y descargar los archivos.
- [Backend: integración y E2E](backend/README.md): endpoints, reglas de negocio, dependencias entre peticiones y uso de Bruno.
- [Frontend: E2E con Playwright](frontend/README.md): recorridos desde la interfaz, videos, capturas y diagnóstico de fallos.
- [Cambios recientes y QA publicado](actualizacion/index.html): explicación, videos y resultados de los 14 controles; comparación del despliegue y hallazgo actual de seguridad.
- [Seguridad: OWASP ZAP y CSP](seguridad/dashboard.html): análisis de páginas y API, severidades, comprobaciones CSP y funcionamiento en navegador.
- [Colección Bruno en ZIP](backend/coleccion-bruno.zip): incluye URL, usuarios de prueba, IDs y nombres de las variables. También está [sin comprimir](backend/coleccion-bruno).

```text
pruebas/
  README.md
  index.html
  manifest.json
  acceso.example.json
  backend/
    README.md
    coleccion-bruno.zip
    coleccion-bruno/
    dashboard.html
    resultados/
  frontend/
    README.md
    dashboard.html
    aprobadas/
  seguridad/
    dashboard.html
    resumen.json
    frontend-zap.html / frontend-zap.json
    backend-zap.html / backend-zap.json
    csp-validation.json
    browser-security.json
    procedencia.json
    qa-publicado.json
    sustento.html / sustento.json
    fuentes.html / source-manifest.json / sources/
  actualizacion/
    index.html / resumen.json
    latest-ui.json / remote-browser-security.json
    deployment.json / remote-csp-validation.json
    sources/ / latest-videos/ / security-videos/
```

## Seguridad

Las [pruebas locales incluidas en Git](seguridad/fuentes.html) contienen los reportes de páginas/API, el resultado CSP, la revisión de navegador y ocho copias de las fuentes/configuraciones usadas, contrastadas con el [manifiesto original de fuentes](seguridad/source-manifest.json). Este último identifica 209 archivos analizados y la imagen ZAP fijada por digest. Los lanzadores y fixtures completos están en el repositorio; no se necesita publicar `test-results/` para consultar esta evidencia.

Para exponer, abre la [guía de sustento de seguridad](seguridad/sustento.html). Relaciona cada conclusión con sus reportes, compara los mismos 20 controles entre el entorno aislado y QA, e incluye un guion y la solicitud exacta para el hosting. El 22 de octubre se recomprueba el hallazgo en [cinco respuestas públicas](actualizacion/server-exposure.json): login, acceso nativo, archivo estático, API y error 404. El responsable solo dispone del acceso WordPress; aplicar `ServerTokens Prod` y `ServerSignature Off` requiere al operador del servidor. La configuración ya está en el repositorio, pero no se instala con el ZIP del plugin. QA permanece en **19/20** hasta comprobar el cambio real.

La seguridad se presenta con dos alcances explícitos. Sobre el **QA publicado, el 22 de octubre**, pasan **19/20 controles HTTP de cabeceras y CSP**; el control fallido detecta la versión de Apache en `Server`. Debe corregirse en la configuración del hosting. Pasan **seis controles autenticados en 42 vistas de cuatro roles**, sin errores JavaScript ni infracciones CSP. Se incluyen cuatro videos y cuatro capturas. No se lanzan ataques activos contra el sitio compartido.

El nuevo escaneo activo de **OWASP ZAP del 22 de octubre** aprobó en **WordPress local HTTPS temporal con el código actual**: **14 rutas HTML y 18 operaciones GET REST**, **52 reglas activas completadas por alcance**, cero alertas altas, medias o bajas, nueve informativas de páginas y una de API. También aprobaron **20/20 controles CSP** y **ocho controles de navegador en 24 vistas**. El [dashboard](seguridad/dashboard.html) y sus [resultados](seguridad/resumen.json) conservan las fases y alertas reales. Este resultado aislado no elimina el hallazgo de cabeceras del hosting publicado.

El [dashboard de seguridad](seguridad/dashboard.html) muestra qué se analizó, resultados y pendientes. Los HTML y JSON de ZAP en `seguridad/` son **resúmenes publicables derivados de los reportes nativos**: omiten cabeceras completas, cuerpos HTTP, cookies, nonces, payloads y valores de consulta. La [procedencia](seguridad/procedencia.json) identifica sus fuentes y huellas. La [revisión remota](seguridad/qa-publicado.json) tiene su propio resultado; los originales completos permanecen privados en `test-results/`.

El análisis activo usa intensidad LOW y limita la navegación del Client Spider a 60 segundos; cada reporte indica las reglas efectivamente completadas. Excluye escrituras, el administrador nativo de WordPress, rutas de terceros e integraciones externas reales. Log4Shell no aplica al entorno PHP/Apache/MariaDB. No acredita ausencia universal de vulnerabilidades ni aceptación UAT. **La integración automática de ZAP y CSP en Jenkins queda pendiente de acreditar**; publicar estas evidencias no la implementa.

Para actualizar únicamente las copias de una ejecución local ya terminada:

```bash
node scripts/build-security-delivery.cjs
```

Este comando no ejecuta ataques ni modifica los reportes originales. El constructor general `scripts/build-qa-delivery.cjs` también incorpora la seguridad disponible; en otro checkout conserva las copias publicadas aunque no existan los originales privados. El [procedimiento ZAP](../MANUAL_QA_UAT.md#zap) explica cómo ejecutar una nueva validación.

## Regresión UI/UX del 21 de octubre de 2026

Como antecedente, #573–#576 se validaron en un WordPress local temporal. La [evidencia de esa etapa](../docs/evidence/release-1.10.9/drafts-ui109.json) conserva **76 pruebas PHP dirigidas/859 aserciones**, **diez controles de navegador** y **49 controles portables**, con su propio ZIP. No se reescriben esas cifras. La ejecución remota actual vuelve a comprobar los borradores y galerías y la versión final de Foros en una sola lista, según la indicación posterior del responsable.

El [manual](../MANUAL_QA_UAT.md#regresion-borradores) explica qué comprobar y los requisitos de las fixtures. Los originales de esta regresión y sus capturas quedan en `test-results/`, fuera de Git. Las fuentes y el resumen revisado sí se conservan en el repositorio.

## Variables y accesos

La copia de GitHub incluye las variables de configuración y los IDs obtenidos del servidor. Las contraseñas están vacías: se cargan desde un entorno privado; las cookies y los nonces se mantienen en memoria. Así el ZIP puede versionarse sin publicar accesos.

Antes de ejecutar, crea `test-results/` si no existe y copia [acceso.example.json](acceso.example.json) a `test-results/playwright-usuarios.json`. Completa los accesos autorizados por el canal interno del equipo y comprueba URL, usuarios e IDs del entorno elegido. El archivo privado y las dependencias locales permanecen ignorados por Git; no se presupone que existan en otro checkout. No copies ZIP con contraseñas dentro de esta carpeta.

## Base documental y trazabilidad

Se revisaron **DOCUMENTO DE ANALISIS Y DISEÑO ASCLA .docx** y **Plan de Prueba y Casos de Prueba.docx** entregados por el responsable. [referencias.json](referencias.json) registra sus nombres y SHA-256; los Word originales no se redistribuyen en la carpeta.

La selección sigue los módulos y los IDs de las matrices CU/CP y RF/CP del plan. [trazabilidad.json](trazabilidad.json) conserva sus **103 casos**, los requisitos relacionados y los límites de la evidencia: 12 escenarios cubiertos, 17 casos parcialmente cubiertos y 74 no incluidos en esta entrega. Una prueba aprobada no significa que estén completos todos los criterios de un CP ni todos los requisitos del proyecto.

| Grupo | Casos del plan relacionados | Alcance real |
|---|---|---|
| Integración API | CP-001, CP-013, CP-021, CP-101 | Login, Empresa obligatoria, paginación, inicio administrativo y exclusión del administrador del networking. |
| Conexión y bloqueo | CP-024, CP-025, CP-032, CP-033, CP-035, CP-036 | Solicitud/aceptación, mensaje exacto, bloqueo y desbloqueo; falta cubrir todas las exclusiones y el orden de varios mensajes. |
| Evento y espera | CP-047, CP-049, CP-051, CP-052, CP-053, CP-055, CP-056, CP-057, CP-059, CP-061 | Aforo uno, inscripción, cancelación y oferta; la interfaz abre el aviso, comprueba su lectura y confirma la vacante. CP-058 sigue pendiente: no se espera la caducidad. |
| Suspensión | CP-005, CP-009, CP-010 | Sesión revocada y acceso recuperado; no contrasta auditoría ni todos los datos históricos. |
| Asistente IA | CP-070, CP-071, CP-072 | Trabajo asíncrono y contenido del hilo; falta contrastar autorización y cronología entre sesiones. |
| Interfaz | CP-011, CP-013 y escenarios de conexión, eventos y suspensión anteriores | Seis flujos mediante controles reales: perfil; conexión/chat/bloqueo; publicación/inscripción; aforo/espera/notificación; suspensión/reactivación; asistente IA. |

El diseño menciona **OpenAI**. La ejecución vigente utiliza **Google Gemini · API real**, según la respuesta del trabajo; no acredita la integración específica con OpenAI ni la corrección semántica de todas las respuestas. La suspensión devuelve **403** por nonce revocado; no acredita RNF-005 sobre cierre de sesión/inactividad y HTTP 401. Tampoco se acredita carga, disponibilidad, latencia bajo concurrencia, SMTP real ni los módulos 6 y 8. No equivale a aprobar todos los criterios de salida ni UAT.

## Ejecutar y actualizar

Necesitas Node.js, pnpm, Microsoft Edge y las herramientas fijadas para esta entrega: Playwright 1.62.1 y Bruno CLI 4.2.1. Desde la raíz, prepara las dependencias una vez:

```bash
pnpm install --frozen-lockfile
pnpm exec playwright install chromium
npm install --prefix test-results/bruno-tools --no-save --no-package-lock @usebruno/cli@4.2.1
```

Los binarios instalados por Playwright incluyen el codificador de video; los recorridos usan Edge. Las dependencias y herramientas quedan fuera de Git. Copia el ejemplo de accesos a su ruta privada y completa las contraseñas autorizadas antes de ejecutar.

Para backend:

```bash
node scripts/run-publishable-backend.cjs
```

Para frontend, ejecuta `node scripts/run-playwright-test-users.cjs`. En PowerShell también puedes mostrar el navegador con:

```powershell
.\scripts\playwright-usuarios-prueba.ps1 -Visible
```

Ambos ejecutores admiten una fixture local explícita: `--fixture test-results/playwright-usuarios-local.json` en Node.js o `-Fixture test-results/playwright-usuarios-local.json` en PowerShell. Las variables del ZIP mantienen el QA compartido; el ejecutor de Bruno aplica los IDs locales solo a una copia temporal.

Para los controles recientes y la revisión autenticada remota, `tests/qa-latest-ui.cjs` y `tests/qa-security-browser.cjs` usan además `test-results/qa-refresh-20261022/private-provided-access.json`, con `base` y los actores `Administrador`, `Ejecutivo` y `Moderador` (ID, login y contraseña autorizados). El Asociado se toma del acceso de Ana. Este archivo no se publica. La regresión crea solo contenidos con prefijo propio y los retira al terminar; el ejecutor de seguridad recorre páginas y verifica permisos sin modificar perfiles. El constructor incorpora sus resultados en `actualizacion/`.

La salida de cada comando indica la carpeta original en `test-results/`. Para renovar esta entrega con los últimos resultados:

```bash
node scripts/build-qa-delivery.cjs
python3 scripts/verify-qa-delivery.py
python3 scripts/check-docs.py
```

El constructor publica el último lote seleccionado de esta colección Bruno y de los seis flujos Playwright, retira los anteriores de sus carpetas, genera las vistas y el ZIP publicable, y registra las huellas. Incorpora la seguridad local disponible o conserva sus copias publicadas cuando no existen originales privados en el checkout. No convierte fallos en aprobaciones. Las copias publicadas retiran rutas personales y los códigos de color incompatibles con JUnit; su procedencia conserva la huella del archivo original y la de la copia. Los originales completos siguen en `test-results/`.

## Qué entregar

Sube `pruebas/` junto con los scripts y fuentes del repositorio. La carpeta no depende de enlaces a carpetas personales ni de los reportes locales ignorados para consultar la evidencia ya publicada. Los videos tienen tamaño apto para Git; no se incluyen instalaciones de herramientas ni credenciales.

Bruno y Playwright ejecutan los mismos seis flujos de negocio; la integración técnica de API y los 14 controles UI adicionales se presentan por separado. Se conserva un solo lote publicado de cada suite principal y una validación de seguridad identificada por su fecha. Las regresiones adicionales se documentan mediante sus propios resúmenes. Si la última de Playwright falla, su evidencia queda en `fallidas/`; si aprueba, queda en `aprobadas/`. Al renovar la entrega se retiran los lotes anteriores de ambas categorías. Los originales locales de `test-results/` quedan fuera de esta carpeta.
