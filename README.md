# ASCLA Core — Intranet ASCLA

## Versión actual: 1.13 · esquema 17

La versión instalable se mantiene en **1.13**, por indicación del proyecto. La [revisión del acceso](docs/evidence/release-1.13/login-language-auto-20261120/validation.json) compacta el formulario, acerca la ayuda al enlace de regreso y centra el botón de sol/luna y el idioma en la parte inferior, conservando el panel izquierdo azul y ambos temas. El idioma se aplica automáticamente al seleccionarlo, sin botón Cambiar.

La [revisión del asistente](docs/evidence/release-1.13/assistant-language-20261119/validation.json) reconoce «que es el intranet ascla?» y variantes de artículo, acentos y espacios. Incluye las mejoras anteriores; las evidencias históricas conservan su propio paquete y alcance.

El diseño conserva la barra lateral azul en ambos temas y recupera los resaltados azules en modo oscuro, con el fondo general en gris carbón. Consulta la [comprobación visual](docs/evidence/release-1.13.2/theme.json) de esta corrección.

El asistente describe la intranet con información institucional y distingue estas preguntas de las consultas previas sobre videos o galerías.

La [regresión anterior](docs/evidence/release-1.13.1/assistant-about.json) aprobó 93 pruebas PHP, 932 aserciones, 49 controles de versión y el chat real en español e inglés, incluida la persistencia tras recargar; conserva su propio paquete y alcance.

La versión 1.13 reúne las mejoras de grupos, los recorridos interactivos por usuario, el menú de cuenta, los idiomas de IA, los banners, las reacciones animadas y el modo oscuro en gris carbón. Consulta el [historial de 1.13](VERSION_HISTORY.md#release-113) para ver el alcance desde el último commit de 1.12.

La [validación técnica inicial de 1.13](docs/evidence/release-1.13/validation.json) registra las ejecuciones independientes de QA y UAT del 19 de noviembre: regresión PHP/E2E, Bruno, Playwright, manual, onboarding, SonarQube, Trivy, ZAP/CSP, Jenkins y carga. Conserva los commits analizados, el SHA-256 del ZIP y la única omisión histórica de comparación de catálogos; no sustituye la aceptación humana UAT.

La [versión 1.11.1](VERSION_HISTORY.md#release-1111) incorporó la importación revisada de intereses desde CSV, PDF tabular, HTML y ZIP de una hoja, además de mejoras en estadísticas y asistencia de Zoom.

Plugin WordPress para una comunidad profesional privada: perfiles, Directorio, conexiones, mensajería, Hub, foros, eventos, microeventos, Centro de Conocimiento, Galería, Aliados, Contacto y administración. Elementor es opcional; no se modifica WordPress Core.

El código instalable está en [wp-content/plugins/ascla-core](wp-content/plugins/ascla-core). La carpeta del plugin siempre es `ascla-core`; el número de versión no forma parte de su nombre. Sus datos se conservan al desactivarlo o desinstalarlo.

Los recorridos de ayuda se definen en `Domain/OnboardingTours.php` y se pueden extender con el filtro `ascla_onboarding_tours`. Cada recorrido usa una clave estable, `page`, `section`, `cap` y una lista de `steps` con `id`, `target`, `title`, `body` y `cap`. `target` admite un selector o una lista de alternativas ordenadas; los controles ausentes se omiten. El motor de interfaz y el servicio de progreso se reutilizan sin cambios. El corte de cuentas antiguas se conserva en `ascla_onboarding_rollout`; los avances están en metadatos del usuario y no se reinician al actualizar el plugin. El botón «?» de cada módulo permite continuar o repetir el recorrido.

## Documentación

Se mantienen diez Markdown con funciones distintas:

| Documento | Contenido |
|---|---|
| [README](README.md) | Entrada al proyecto, instalación y estructura. |
| [Manual QA/UAT](MANUAL_QA_UAT.md) | Ejecución de herramientas, instalación y aceptación, con resultados esperados y solución de errores. |
| [Cumplimiento](COMPLIANCE.md) | Estado vigente, decisiones, evidencias y pendientes. |
| [Historial](VERSION_HISTORY.md) | Cambios por versión y alcance de cada ejecución. |
| [Diseño](DESIGN.md) | Referencias, tipografía, paleta y criterios de la interfaz. |
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
python3 scripts/verify-package.py --manifest docs/evidence/release-1.13/login-language-auto-20261120/package.json
```

Se generan `dist/ascla-core.zip` y `dist/ascla-core.sha256`, ignorados por Git. El [manifiesto de la revisión actual de 1.13](docs/evidence/release-1.13/login-language-auto-20261120/package.json) registra versión, tamaño, SHA-256 y coincidencia con las fuentes. Para instalar manualmente: WordPress → Plugins → Añadir plugin → Subir plugin → seleccionar el ZIP → reemplazar la versión instalada. Registra la huella del paquete, pues una versión puede recibir varias correcciones.

## Calidad y entrega

La promoción sigue `development → qa → uat → main`. El [pipeline de Jenkins](ci/README.md) construye y analiza las imágenes con Trivy antes del despliegue; en QA/UAT añade pruebas, SonarQube/Quality Gate y ZAP/CSP. `main` no despliega y debe estar protegida para recibir cambios mediante pull request. La aceptación humana de UAT corresponde a testers y PM.

La [validación completa de 1.11.1 del 5 de noviembre](docs/evidence/release-1.11.1/full-validation1111.json) reúne QA/UAT técnicos, SonarQube del curso, los seis flujos de Bruno/Playwright, Trivy, ZAP, Jenkins y carga local. Los reportes publicados identifican el laboratorio local y conservan por separado la revisión remota histórica.

Las pruebas también pueden ejecutarse [manualmente](MANUAL_QA_UAT.md). La [colección Bruno](pruebas/backend/coleccion-bruno) y [Playwright](tests/acceptance-ui.cjs) comparten seis flujos: perfil, conexión/chat/bloqueo, publicación/inscripción, aforo/espera, suspensión/reactivación y asistente IA. Bruno añade integración técnica: 81 peticiones y 149 comprobaciones en la entrega publicada. El [catálogo comparado](pruebas/flujos.json) identifica los recorridos y sus límites.

Consulta los [dashboards de pruebas](pruebas/index.html) y [seguridad](pruebas/seguridad/dashboard.html). Cada reporte conserva fecha, entorno, fuentes y paquete: los resultados publicados del 22 de octubre no acreditan automáticamente el código posterior. La [validación técnica de QA/UAT](docs/evidence/release-1.11.0/full-qa1110.json) y la [corrección posterior de imágenes](docs/evidence/release-1.11.0/trivy-remediation1110.json) tienen revisiones distintas; Cumplimiento explica sus resultados y pendientes.

La revisión documental conserva los reportes de cobertura existentes. `quality-ci.sh` genera cobertura cuando se realiza una nueva validación completa; SonarQube exige que corresponda a la revisión analizada. No se reasignan reportes antiguos a un commit nuevo.

## Política documental y evidencias

Los diez Markdown y el JSON de requisitos viajan con el repositorio. Usa rutas relativas y dominios del proyecto; evita carpetas personales, rutas de equipos, nombres de dispositivo e IP privadas. Los ejemplos de loopback son genéricos.

`docs/evidence/` contiene resúmenes JSON y JUnit revisados y permitidos expresamente por `.gitignore`. `pruebas/` conserva la entrega publicable de Bruno/Playwright y las copias revisadas de seguridad, con procedencia y huellas. Los originales privados que se retienen, las sesiones y los accesos temporales permanecen en `test-results/`, fuera de Git; también se excluyen cobertura, dependencias y ZIP con contraseñas. La procedencia indica cuándo un original histórico ya no está disponible y conserva su huella.

Antes de confirmar cambios, revisa `git status --short` y `git diff`, comprueba el paquete si cambió el plugin y valida la documentación y las evidencias publicables:

```bash
python3 scripts/check-docs.py
python3 scripts/check-translations.py
python3 scripts/verify-qa-delivery.py
```
