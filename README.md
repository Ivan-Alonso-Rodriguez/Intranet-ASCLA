# ASCLA Core — Intranet ASCLA

## Versión actual: 1.11.0 · esquema 15

Plugin WordPress para una comunidad profesional privada: perfiles, Directorio, conexiones, mensajería, Hub, foros, eventos, microeventos, Centro de Conocimiento, Galería, Aliados, Contacto y administración. Elementor es opcional; no se modifica WordPress Core.

El código instalable está en [wp-content/plugins/ascla-core](wp-content/plugins/ascla-core). La carpeta del plugin siempre es `ascla-core`; el número de versión no forma parte de su nombre. Sus datos se conservan al desactivarlo o desinstalarlo.

## Documentación

Se mantienen nueve Markdown con funciones distintas:

| Documento | Contenido |
|---|---|
| [README](README.md) | Entrada al proyecto, instalación y estructura. |
| [Manual QA/UAT](MANUAL_QA_UAT.md) | Ejecución de herramientas, instalación y aceptación, con resultados esperados y solución de errores. |
| [Cumplimiento](COMPLIANCE.md) | Estado vigente, decisiones, evidencias y pendientes. |
| [Historial](VERSION_HISTORY.md) | Cambios por versión y alcance de cada ejecución. |
| [Matriz](requirements/ascla-analysis.md) | Los 302 requisitos originales y su trazabilidad; la fuente editable es el [JSON](requirements/ascla-analysis.json). |
| [Integración continua](ci/README.md) | Jenkins, imágenes, Trivy, ZAP/CSP, archivado y configuración del entorno. |
| [Entrega de pruebas](pruebas/README.md) | Resultados publicables, accesos privados y procedencia de las copias. |
| [Pruebas de backend](pruebas/backend/README.md) | Integración y flujos E2E con Bruno. |
| [Pruebas de frontend](pruebas/frontend/README.md) | Recorridos Playwright, videos, capturas y diagnóstico. |

## Preparar un entorno local

Desde la raíz del repositorio en WSL, con Docker Desktop funcionando, sigue el [paso a paso local](MANUAL_QA_UAT.md#local): configuración privada, publicación de puertos de loopback e inicialización. El [Compose base](compose.yaml) no publica puertos al navegador; la guía muestra cómo añadirlos para desarrollo. `.env`, credenciales, cargas, resultados y dependencias locales permanecen fuera de Git.

## Crear e instalar el plugin

Desde la raíz del repositorio:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
```

Se generan `dist/ascla-core.zip` y `dist/ascla-core.sha256`, ignorados por Git. El [manifiesto actual de 1.11.0](docs/evidence/release-1.11.0/package.json) registra versión, tamaño, SHA-256 y coincidencia con las fuentes. Para instalar manualmente: WordPress → Plugins → Añadir plugin → Subir plugin → seleccionar el ZIP → reemplazar la versión instalada. Registra la huella del paquete, pues una versión puede recibir varias correcciones.

## Calidad y entrega

La promoción sigue `development → qa → uat → main`. El [pipeline de Jenkins](ci/README.md) construye y analiza las imágenes con Trivy antes del despliegue; en QA/UAT añade pruebas, SonarQube/Quality Gate y ZAP/CSP. `main` no despliega y debe estar protegida para recibir cambios mediante pull request. La aceptación humana de UAT corresponde a testers y PM.

Las pruebas también pueden ejecutarse [manualmente](MANUAL_QA_UAT.md). La [colección Bruno](pruebas/backend/coleccion-bruno) y [Playwright](tests/acceptance-ui.cjs) comparten seis flujos: perfil, conexión/chat/bloqueo, publicación/inscripción, aforo/espera, suspensión/reactivación y asistente IA. Bruno añade integración técnica: 81 peticiones y 149 comprobaciones en la entrega publicada. El [catálogo comparado](pruebas/flujos.json) identifica los recorridos y sus límites.

Consulta los [dashboards de pruebas](pruebas/index.html) y [seguridad](pruebas/seguridad/dashboard.html). Cada reporte conserva fecha, entorno, fuentes y paquete: los resultados publicados del 22 de octubre no acreditan automáticamente el código posterior. La [validación técnica de QA/UAT](docs/evidence/release-1.11.0/full-qa1110.json) y la [corrección posterior de imágenes](docs/evidence/release-1.11.0/trivy-remediation1110.json) tienen revisiones distintas; Cumplimiento explica sus resultados y pendientes.

La revisión documental conserva los reportes de cobertura existentes. `quality-ci.sh` genera cobertura cuando se realiza una nueva validación completa; SonarQube exige que corresponda a la revisión analizada. No se reasignan reportes antiguos a un commit nuevo.

## Política documental y evidencias

Los nueve Markdown y el JSON de requisitos viajan con el repositorio. Usa rutas relativas y dominios del proyecto; evita carpetas personales, rutas de equipos, nombres de dispositivo e IP privadas. Los ejemplos de loopback son genéricos.

`docs/evidence/` contiene resúmenes JSON y JUnit revisados y permitidos expresamente por `.gitignore`. `pruebas/` conserva la entrega publicable de Bruno/Playwright y las copias revisadas de seguridad, con procedencia y huellas. Los originales privados que se retienen, las sesiones y los accesos temporales permanecen en `test-results/`, fuera de Git; también se excluyen cobertura, dependencias y ZIP con contraseñas. La procedencia indica cuándo un original histórico ya no está disponible y conserva su huella.

Antes de confirmar cambios, revisa `git status --short` y `git diff`, comprueba el paquete si cambió el plugin y valida la documentación y las evidencias publicables:

```bash
python3 scripts/check-docs.py
python3 scripts/verify-qa-delivery.py
```
