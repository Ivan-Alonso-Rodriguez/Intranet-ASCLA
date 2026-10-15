# ASCLA Core — Intranet ASCLA

## Versión actual: 1.10.8 · esquema 15

Plugin WordPress para una comunidad profesional privada: perfiles, Directorio, conexiones, mensajería, Hub, foros, eventos, microeventos, Centro de Conocimiento, Galería, Aliados, Contacto y administración. Elementor es opcional; no se modifica WordPress Core.

El código instalable está en [wp-content/plugins/ascla-core](wp-content/plugins/ascla-core). La carpeta del plugin siempre es `ascla-core`; el número de versión no forma parte de su nombre. Sus datos se conservan al desactivarlo o desinstalarlo.

## Documentación

Se mantienen cinco documentos Markdown, cada uno con un propósito:

| Documento | Contenido |
|---|---|
| [README](README.md) | Entrada al proyecto, instalación y estructura. |
| [Manual QA/UAT](MANUAL_QA_UAT.md) | Guía paso a paso de PHPUnit, SonarQube, Bruno, Playwright, instalación y aceptación UAT, con resultados y solución de errores. |
| [Cumplimiento](COMPLIANCE.md) | Estado vigente, reglas, correcciones QA, evidencia y pendientes. |
| [Historial](VERSION_HISTORY.md) | Evolución por versión; las cifras antiguas conservan su fecha y alcance. |
| [Matriz](requirements/ascla-analysis.md) | Los 302 requisitos originales, prioridades y trazabilidad. Su fuente editable es el [JSON](requirements/ascla-analysis.json). |

## Preparar un entorno local

Desde la raíz del repositorio en WSL, con Docker Desktop funcionando, sigue el [paso a paso local](MANUAL_QA_UAT.md#local): configuración privada, publicación de puertos de loopback e inicialización. El [Compose base](compose.yaml) no publica puertos al navegador; la guía muestra cómo añadirlos para desarrollo. `.env`, credenciales, cargas, resultados y dependencias locales permanecen fuera de Git.

## Crear e instalar el plugin

Desde la raíz del repositorio:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
```

Se generan `dist/ascla-core.zip` y `dist/ascla-core.sha256`, ignorados por Git. El [resumen público del paquete](docs/evidence/release-1.10.8/package.json) registra versión, tamaño, SHA-256 y coincidencia con las fuentes. En WordPress: Plugins → Añadir plugin → Subir plugin → seleccionar el ZIP → reemplazar la versión instalada. El responsable del entorno realiza esta instalación; la promoción de Git y las pruebas no despliegan el plugin.

## Calidad y entrega

El flujo es `development → qa → uat → main`, sin Jenkins por la excepción confirmada para este proyecto. UAT requiere revisión de testers y autorización del PM. `main` debe estar protegida y recibir los cambios mediante pull request. No se exige cobertura del 100 %.

El [manual](MANUAL_QA_UAT.md) indica el orden de ejecución y los resultados esperados. Primero genera PHPUnit/cobertura con `quality-ci.sh`; después envía esa misma revisión a [SonarQube](MANUAL_QA_UAT.md#sonarqube). El scanner no despliega ni prueba el WordPress publicado.

Las colecciones [Bruno](tests/http) verifican endpoints y flujos encadenados. [Playwright](tests/acceptance-ui.cjs) recorre perfil, conexiones/chat y eventos, conservando videos, capturas y reportes. El [manual](MANUAL_QA_UAT.md#pruebas-http-e2e) explica la ejecución local y contra el QA publicado.

La [matriz de cumplimiento](COMPLIANCE.md#estado) distingue código revisado, decisiones del usuario y validaciones pendientes. La aceptación remota existente corresponde al paquete identificado en su propia evidencia; no acredita automáticamente los ZIP posteriores.

## Política documental y evidencias

Los cinco MD y el JSON de requisitos deben viajar con el repositorio. Usa rutas relativas y dominios del proyecto; no incluyas carpetas personales, rutas absolutas de equipos, nombres de dispositivo ni IP privadas. Los ejemplos de loopback son genéricos. `docs/evidence/` contiene únicamente los resúmenes JSON y JUnit revisados y permitidos expresamente por `.gitignore`. Los reportes completos, videos, capturas y archivos de acceso temporal permanecen en `test-results/`, fuera de Git; sus rutas se documentan como artefactos locales, sin enlaces que aparenten estar publicados en GitHub.

Para verificar documentos, referencias personales, enlaces, anclas, exclusiones de Git y sincronización de la matriz:

```bash
python3 scripts/check-docs.py
```
