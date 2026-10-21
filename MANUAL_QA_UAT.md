# Guía práctica de QA, SonarQube y UAT

Versión documentada: **1.10.9**, esquema **15**. Este proyecto WordPress utiliza un flujo manual; el [Jenkinsfile](Jenkinsfile) se conserva como referencia opcional para un uso futuro. Aquí se explica cómo ejecutar las herramientas, revisar sus resultados e instalar la entrega. No se exige cobertura del 100 %; las pruebas y el Quality Gate deben satisfacer los criterios acordados para la entrega.

Todos los comandos se ejecutan **desde la raíz del repositorio**, en una terminal Bash; en Windows, usa WSL con Docker Desktop operativo e integrado con esa distribución. Las rutas de los ejemplos son relativas al repositorio. Los dominios publicados corresponden a servicios del proyecto; `localhost` y `127.0.0.1` son ejemplos genéricos de acceso local.

Esta guía distingue procedimientos disponibles de pruebas ya ejecutadas. La preparación de 1.10.9 conserva los reportes de cobertura anteriores; no los regenera ni cambia su revisión. `quality-ci.sh` sí genera cobertura: úsalo únicamente cuando corresponda realizar una nueva validación completa de QA. Las comprobaciones de documentación, paquete, entrega y seguridad no requieren modificar esos reportes.

## 1. Elegir qué vas a comprobar

| Objetivo | Comando principal | Dónde actúa | Qué revisar |
|---|---|---|---|
| Verificar documentación | `python3 scripts/check-docs.py` | Repositorio | Mensaje `Documentation OK`. |
| Verificar evidencias publicables | `python3 scripts/verify-qa-delivery.py` | Carpeta `pruebas/` | Inventario, huellas, ZIP y enlaces correctos; no ejecuta pruebas nuevas. |
| Probar PHP e interfaz y generar cobertura | `BRANCH_NAME=qa bash scripts/quality-ci.sh` | WordPress Docker desechable | Pruebas, JUnit, Clover y LCOV en `coverage/`. |
| Analizar código con SonarQube | `bash scripts/sonar-manual.sh qa` | Fuentes y reportes anteriores | Análisis de ASCLA-QA y su Quality Gate. |
| Probar integración y E2E con Bruno/Playwright | [Ejecutores de la entrega vigente](pruebas/README.md#ejecutar-y-actualizar) | QA con cuentas dedicadas o fixture local explícita | 58 peticiones, 105 comprobaciones y cinco flujos UI con evidencias. |
| Preparar el plugin | `python3 scripts/build.py` y `python3 scripts/verify-package.py` | Fuentes del plugin | ZIP, SHA-256 y manifiesto. |
| Comprobar seguridad con ZAP | [Procedimiento ZAP](#zap) | WordPress local HTTPS desechable | Informes separados de páginas y API, CSP y límites del análisis. |

Sigue **2 → 3 → 4 → 5** para validar una revisión de QA. Usa la sección **6** para probar el sitio instalado. La aceptación UAT requiere después revisión humana y evidencia del entorno.

## 2. Preparar herramientas y una revisión reproducible

Comprueba que estás en el repositorio y que las herramientas responden:

```bash
git status --short
git branch --show-current
python3 --version
docker version
docker compose version
openssl version
```

Necesitas Git, Python 3, Bash, OpenSSL y Docker con Compose. La primera ejecución descarga imágenes y dependencias, por lo que requiere acceso a sus registros. PHP, PHPUnit/PCOV, Bruno CLI y Playwright se preparan en contenedores; no necesitas instalarlos globalmente ni dentro del WordPress publicado.

Antes de cambiar de rama, revisa `git diff` y guarda los cambios intencionados en un commit. Revisa también los archivos nuevos con `git status`: los que todavía no se agregaron no aparecen en `git diff`. No incluyas contraseñas, archivos `.env`, accesos temporales ni reportes privados.

Si la entrega incluye cambios del plugin, prepara primero su paquete:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
python3 scripts/check-docs.py
```

El verificador puede actualizar el [manifiesto público](docs/evidence/release-1.10.9/package.json); incorpora ese cambio al commit de la entrega antes de generar cobertura. Revisa y agrega los archivos correspondientes, confirma el commit y comparte `development` según el procedimiento del equipo.

Para promover esa revisión a QA, con el checkout ya limpio:

```bash
git fetch origin
git switch qa
git pull --ff-only origin qa
git merge --ff-only development
git push origin qa
git status --short
git rev-parse HEAD
```

`git status --short` debe quedar sin salida. Conserva el identificador del commit. Si `--ff-only` falla, las ramas divergieron: revisa `git log --graph --oneline --decorate --all -30` y resuelve la integración con el equipo antes de continuar. No fuerces el avance ni descartes cambios para pasar el análisis.

## 3. Ejecutar PHPUnit, pruebas de interfaz y cobertura

En la rama `qa`, ejecuta:

```bash
python3 scripts/check-docs.py
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=qa bash scripts/quality-ci.sh
```

El script crea su propia base y WordPress desechables, ejecuta PHPUnit con PCOV, comprobaciones portables y suites de navegador. Al terminar retira sus propios servicios y volúmenes y conserva los resultados. No requiere arrancar el local de la sección 9, no instala el plugin en el servidor y no ejecuta Bruno ni SonarQube.

Espera a que termine sin errores. Si PHPUnit muestra `Failures` o `Errors`, o un recorrido de navegador falla, corrige la causa antes de aprobar la revisión. El resultado debe corresponder al comando que acabas de terminar.

| Archivo o carpeta | Para qué sirve |
|---|---|
| `coverage/php-summary.txt` | Porcentaje y detalle de cobertura PHP. |
| `coverage/junit.xml` | Casos PHP, tiempos y fallos. |
| `coverage/clover.xml` | Cobertura PHP importada por SonarQube. |
| `coverage/lcov.info` | Cobertura JavaScript importada por SonarQube. |
| `coverage/source-revision` | Commit de la ejecución válida y sin cambios pendientes. |
| `test-results/` | Resultados y capturas de las suites de navegador ejecutadas. |

El script valida que los reportes sean utilizables y correspondan a rutas del repositorio; no impone llegar al 100 %. No todas las suites puntuales del repositorio forman parte de este comando: la evidencia de una corrección específica debe indicar qué se ejecutó.

Si PHP termina con `OK` pero después aparece `Disposable test site required`, la suite completa todavía no ha pasado: se detuvo al preparar los datos del navegador. El servicio `cli` debe recibir el mismo entorno `local` y la captura de correo que WordPress mediante `WORDPRESS_CONFIG_EXTRA`. El script comprueba ese contexto antes de PHPUnit. Actualiza la configuración y repite el comando; no retires la protección de las fixtures ni fabriques `coverage/source-revision` para continuar con SonarQube.

Los mensajes `ASCLA technical_error` durante PHPUnit pueden corresponder a casos que simulan fallos de base, proveedores o correo. Su presencia no determina por sí sola el resultado: revisa el resumen de PHPUnit y el final del script. Los avisos de instalación de paquetes/PCOV tampoco equivalen a pruebas fallidas.

Si un recorrido falla con `Timeout`, revisa `test-results/e2e.json` y las capturas `test-results/failure-admin.png` y `test-results/failure-member.png`. Contrasta la sección y los controles visibles con la navegación del caso. El reporte JavaScript se guarda también al fallar y puede ser parcial: su porcentaje no acredita que terminó la suite. SonarQube rechaza esa ejecución porque todavía no existe `coverage/source-revision`; corrige el caso y repite el flujo completo sobre un commit limpio.

En accesibilidad, revisa `test-results/accessibility107.json` y `test-results/accessibility107-progress.json`; al fallar también se guardan `test-results/accessibility107-failure.png` y `test-results/accessibility107-failure.json` con el foco y estado de los adjuntos. El caso de carga debe abrir el selector nativo con teclado, en ambos temas, antes de comprobar el editor de imagen. No sustituyas esa comprobación por asignar directamente el archivo al campo.

<a id="sonarqube"></a>

## 4. Enviar el análisis a SonarQube y revisar el Quality Gate

### Preparar el acceso

1. Abre [SonarQube del proyecto](https://sonarqube.ingsoftware.lat) e inicia sesión.
2. Comprueba que tienes acceso a **ASCLA-QA** y permiso para analizarlo. Si falta ese acceso, debe habilitarlo quien administra SonarQube; ser administrador de WordPress no concede ese permiso.
3. Obtén un token desde la gestión de seguridad de tu cuenta o solicita el acceso correspondiente. Consérvalo privado; no lo escribas en comandos, Markdown, capturas ni commits.

### Ejecutar el scanner

Sin editar archivos ni cambiar de commit después de las pruebas:

```bash
export SONAR_HOST_URL=https://sonarqube.ingsoftware.lat
unset SONAR_TOKEN
bash scripts/sonar-manual.sh qa
```

Cuando la terminal solicite el token de ASCLA-QA, pégalo allí y pulsa Enter. La entrada no se muestra. La URL debe ser texto simple, sin los corchetes ni paréntesis de un enlace Markdown.

El script exige rama `qa`, checkout limpio, reportes presentes y `coverage/source-revision` igual al commit actual. Ejecuta SonarScanner en Docker con la configuración de [sonar-project.properties](sonar-project.properties), importa cobertura PHP/JavaScript y espera el Quality Gate hasta cinco minutos. No ejecuta pruebas contra WordPress ni despliega.

### Leer y guardar el resultado

1. Abre el enlace al análisis que devuelve el scanner y verifica **ASCLA-QA**, fecha y revisión analizada.
2. Comprueba el **Quality Gate** y abre cada condición incumplida si aparece fallido. Subir los archivos no basta para aprobar el gate.
3. Revisa incidencias, seguridad, duplicación y cobertura. Evalúa los hallazgos que requieren revisión humana; no los cierres solo para cambiar el indicador.
4. Registra enlace al análisis, commit, fecha, estado y condiciones evaluadas. No captures el token.

Los umbrales efectivos son los configurados en SonarQube. Si falla una condición de cobertura, consulta ese umbral y las líneas afectadas; no se requiere convertir todo el proyecto a 100 %. Cambiar las condiciones debe ser una decisión explícita del equipo, no una forma de ocultar fallos.

Un gate puede fallar por incidencias aunque la cobertura ya cumpla. En ese caso, corrige las reglas señaladas y valida el comportamiento con las pruebas existentes; no necesitas añadir casos para aumentar ese porcentaje. Conserva por separado la exportación original y el análisis posterior. Una comprobación local sin cobertura no reemplaza el análisis de QA ni permite atribuir reportes de cobertura anteriores a otro commit.

### Si algo falla

| Situación | Cómo continuar |
|---|---|
| Docker no conecta | Abre Docker Desktop, comprueba la integración WSL y repite `docker version`. |
| Rama incorrecta | Conserva tus cambios y sitúate en `qa`; el argumento del scanner no cambia de rama. |
| Cambios sin commit | Revisa y confirma los cambios intencionados; repite `quality-ci.sh` sobre esa revisión limpia. |
| Git muestra `Unable to read current working directory` | En WSL, sal del directorio con `cd /` y vuelve a entrar a la raíz real del repositorio, o abre una terminal nueva allí. Repite `git status` y `git diff` antes de continuar. Si Windows y WSL leen estados distintos, comprueba de nuevo el montaje antes de confirmar o descartar archivos. Los scripts se detienen si Git no puede comprobar el estado. |
| Falta `source-revision` o no coincide | Repite la suite sobre el commit actual y sin ediciones durante la ejecución. No crees ni modifiques ese archivo a mano. |
| Faltan Clover, JUnit o LCOV | Revisa dónde se interrumpió `quality-ci.sh`, resuelve el fallo y vuelve a ejecutarlo. |
| Token rechazado | Comprueba vigencia y permiso sobre ASCLA-QA; facilita el token al repetir la entrada oculta. |
| Quality Gate fallido | Revisa las condiciones, corrige lo que corresponda, guarda el nuevo commit y repite pruebas y scanner. |
| Se agota la espera del gate | Revisa en SonarQube si el procesamiento sigue pendiente o falló. No registres aprobación hasta conocer el resultado. |

Si habías exportado `SONAR_TOKEN` en la terminal padre, ejecuta `unset SONAR_TOKEN` allí al terminar. El script solo puede limpiar su propio proceso.

<a id="pruebas-http-e2e"></a>

## 5. Ejecutar integración y E2E con Bruno y Playwright

La [entrega vigente](pruebas/README.md) reúne **58 peticiones y 105 comprobaciones de Bruno**, y **cinco recorridos de Playwright**. La ejecución del 16 de octubre aprobó en WordPress local aislado con el código actualizado, con 11 videos y 11 capturas. El asistente usó mock local; no acredita una API externa ni una nueva instalación en el servidor.

| Grupo | Fuente vigente | Alcance |
|---|---|---|
| Integración API | [Colección](pruebas/backend/coleccion-bruno/integracion) | 18 peticiones y 26 comprobaciones: acceso, perfil, paginación, validaciones y restricciones del administrador. |
| E2E de backend | [Cuatro flujos](pruebas/backend/coleccion-bruno/flujos-e2e) | 40 peticiones y 79 comprobaciones con IDs y sesiones obtenidos de pasos anteriores. |
| E2E de interfaz | [Playwright](tests/acceptance-ui.cjs) | Cinco flujos mediante controles reales; videos, capturas y diagnóstico tanto al aprobar como al fallar. |

Prepara las [herramientas y los accesos privados](pruebas/README.md#ejecutar-y-actualizar). Desde la raíz, ejecuta los dos grupos de forma secuencial con las mismas cuentas:

```bash
node scripts/run-publishable-backend.cjs
node scripts/run-playwright-test-users.cjs
node scripts/build-qa-delivery.cjs
python3 scripts/verify-qa-delivery.py
python3 scripts/check-docs.py
```

Los dos ejecutores admiten `--fixture` para seleccionar un acceso local explícito. El [ZIP publicable](pruebas/backend/coleccion-bruno.zip) incluye URL, usuarios e IDs, con contraseñas vacías; estas se cargan desde el archivo privado. Cookies y nonces se mantienen en memoria. El ejecutor de Bruno usa una copia temporal para que los IDs locales no reemplacen las variables del QA compartido.

El constructor publica **solo la última ejecución por herramienta** en `pruebas/`, con su estado original, y retira las anteriores de esa carpeta. Los originales quedan en `test-results/`, fuera de Git. Los reportes registran revisión, rama, destino y cambios sin commit; conservarlos no equivale a aprobar UAT ni a instalar el plugin.

<a id="cuatro-flujos-bruno"></a>

### Cuatro flujos principales del backend

Los pasos, su utilidad y la relación con los Word están en el [README de backend](pruebas/backend/README.md#e2e-flujos-completos-de-negocio). La colección importable de `pruebas/backend/coleccion-bruno/` es la fuente vigente.

| Flujo | Peticiones | Comprobaciones | Secuencia |
|---|---:|---:|---|
| Conexión, mensaje y bloqueo | 12 | 24 | Login de Ana y Bruno → solicitud → aceptación → mensaje leído → bloqueo → envío rechazado con 404 → desbloqueo sin restaurar conexión. |
| Evento con cupo y espera | 15 | 29 | Crear aforo uno → Ana confirma → Bruno recibe 409 → espera → Ana cancela → oferta y aviso → cancelar evento → inscripción rechazada con 409. |
| Suspensión y reactivación | 9 | 18 | Acceso de Carla → suspender → sesión revocada con 403 → reactivar → sesión anterior sigue inválida → login nuevo → acceso 200. |
| Asistente IA | 4 | 8 | Pregunta → ID del trabajo → polling → completed → comprobar respuesta → consultar el mismo hilo. |

El asistente consulta cada dos segundos, hasta 120 segundos, y conserva el modo devuelto por el servidor. Un resultado mock no acredita un proveedor externo. El ejecutor intenta reactivar a Carla, retirar el bloqueo y cancelar únicamente su propio evento; revisa `cleanup` en el resumen.

<a id="playwright-usuarios-prueba"></a>

### Playwright con los mismos usuarios de prueba de Bruno

El [ejecutor de interfaz](scripts/run-playwright-test-users.cjs) usa Ana, Bruno, Carla y Administrador desde `test-results/playwright-usuarios.json`, ignorado por Git. En otro equipo copia [el ejemplo sin contraseñas](pruebas/acceso.example.json) y completa los accesos autorizados por el canal interno.

| Recorrido | Usuarios | Secuencia |
|---|---|---|
| Perfil | Carla | Editar biografía → guardar → recargar → rechazar Empresa vacía → conservar datos. |
| Conexión y chat | Ana y Bruno | Solicitud → aceptación → enviar y leer → bloquear → impedir chat → desbloquear sin restaurar conexión. |
| Publicación e inscripción | Administrador y Ana | Inicio administrativo y restricciones sociales → borrador → validar → publicar → inscribirse → persistir → cancelar evento. |
| Aforo y espera | Administrador, Ana y Bruno | Un cupo → confirmación → espera → vacante → abrir aviso → aceptar oferta → cancelar evento. |
| Suspensión | Administrador y Carla | Suspender → revocar sesión → rechazar login → reactivar → login nuevo → conservar biografía. |

En Windows puedes mostrar el navegador con:

```powershell
.\scripts\playwright-usuarios-prueba.ps1 -Visible
```

Para un acceso local preparado añade `-Fixture test-results/playwright-usuarios-local.json`. Se requieren Node.js, las dependencias del proyecto, Microsoft Edge y los binarios de video de Playwright. La sesión se verifica mediante REST; las acciones de negocio se realizan desde la interfaz. No ejecutes ambos grupos simultáneamente con las mismas cuentas.

### Interfaz y cuenta de administración

El administrador inicia en **Administración ASCLA**; `/intranet/` también lo lleva a ese panel. El enlace principal y el logo regresan a administración. En escritorio se muestra un único logo y las secciones administrativas encima del contenido, con un solo panel lateral. El menú sigue disponible en móvil. Moderadores, ejecutivos y asociados conservan su participación comunitaria.

La [regresión PHP](tests/php/AdministratorRoleTest.php) y la [prueba de navegación](tests/admin-navigation.cjs) comprueban inicio, permisos, directorio y restricciones sociales. El [fixture](tests/admin-navigation-fixture.php) es exclusivo de un WordPress local ya inicializado. Con ese entorno y las herramientas disponibles:

```bash
docker compose exec -T wordpress php /opt/ascla-tests/admin-navigation-fixture.php > test-results/admin-role-access.json
node tests/admin-navigation.cjs
```

Las evidencias quedan en `test-results/admin-role-ui-FECHA/`; los accesos permanecen privados. El fixture admite la limpieza por CLI con `action: cleanup` y valida IDs y usuarios antes de retirar sus propias cuentas.

### Suite complementaria con cuentas temporales

`bash scripts/acceptance-tests.sh` sigue activo para una aceptación aislada: diez peticiones de integración, 23 de negocio y tres recorridos de interfaz, con preparación y limpieza automática de cuentas. Cubre también desconexión con historial y reprogramación de eventos. Sus fuentes están en [tests/http](tests/http); no sustituye los cuatro flujos y cinco recorridos de la entrega vigente. El modo remoto con auxiliar WordPress se explica en la sección 6.

<a id="exponer-pruebas"></a>

### Cómo exponer los dos primeros puntos

1. Abre el [dashboard general](pruebas/index.html). Identifica fecha, rama, revisión y destino; aclara que la última ejecución fue local y que el asistente usó mock.
2. En [Bruno Run Dashboard](pruebas/backend/dashboard.html), muestra las 18 peticiones de integración y los rechazos esperados de acceso y networking administrativo. Los HTTP 400/401/403/404/409 previstos son comprobaciones aprobadas.
3. Explica los cuatro flujos con las flechas del [README de backend](pruebas/backend/README.md). Muestra un ID obtenido por una petición y utilizado por la siguiente; no son valores inventados.
4. En el [dashboard de Playwright](pruebas/frontend/dashboard.html), reproduce los cinco recorridos. Son clics y formularios reales; también verifican el inicio administrativo y el logo único.
5. Muestra JSON/JUnit, las fuentes de esa ejecución, `procedencia.json`, `manifest.json` y el ZIP sin secretos. Las huellas permiten detectar modificaciones, no son una certificación independiente.
6. Revisa el estado de limpieza. Si la última ejecución falla, enseña el diagnóstico y sus videos sin convertirla en aprobación. `pruebas/` conserva un único lote por herramienta.

El código se confirma en `development` y luego se promueve a `qa` y `uat`. Los reportes conservan la rama real en que se ejecutaron; preparar un commit en otra rama no cambia sus metadatos. La aceptación UAT exige revisión humana posterior.

## 6. Probar el QA publicado teniendo solo administración de WordPress

Este recorrido corresponde al entorno de pruebas [wordpress.ingsoftware.lat](https://wordpress.ingsoftware.lat/). Requiere permiso para instalar plugins en WordPress y Docker en el equipo que ejecuta las pruebas; **no requiere SSH ni WP-CLI en el servidor**. Administración ASCLA y administración técnica de WordPress tienen permisos distintos.

1. Ejecuta `python3 scripts/build.py` y `python3 scripts/verify-package.py`. Conserva el SHA-256 del ZIP que vas a evaluar.
2. En WordPress, abre **Plugins → Añadir plugin → Subir plugin**. Selecciona `dist/ascla-core.zip` y reemplaza la versión instalada. Comprueba que ASCLA Core **1.10.9** está activo; el mismo número de versión puede contener correcciones distintas, por eso debes registrar también el paquete.
3. Ejecuta `python3 scripts/build-qa-tools.py`. Instala y activa `dist/ascla-qa-tools.zip` desde Plugins. Es un auxiliar independiente del core y restringido a entornos permitidos de pruebas.
4. Abre **Herramientas → ASCLA QA**, confirma el entorno y pulsa **Crear lote y descargar acceso**. Se crean cinco cuentas sintéticas y se descarga `ascla-qa-access.json`.
5. Crea la carpeta `test-results` si no existe y coloca el archivo en `test-results/ascla-qa-access.json`. Es privado y está ignorado por Git. No lo agregues a reportes ni al repositorio.
6. Desde la raíz del repositorio, ejecuta:

```bash
ASCLA_ACCEPTANCE_MODE=remote \
ASCLA_TEST_SITE_URL=https://wordpress.ingsoftware.lat/ \
ASCLA_TEST_FIXTURE=test-results/ascla-qa-access.json \
bash scripts/acceptance-tests.sh
```

7. Abre el reporte indicado, comprueba que el destino es el QA publicado y revisa los tres grupos. Examina `cleanup.log`: el ejecutor intenta retirar el lote temporal con autenticación y comprobación de propiedad.
8. Si no se confirma la limpieza, entra con tu administrador habitual a **Herramientas → ASCLA QA → Limpiar lote temporal**. Conserva el acceso privado hasta confirmar la limpieza. Después retira el JSON descargado y desactiva/elimina **ASCLA QA Tools**. Para repetir, prepara un lote nuevo.

El archivo de acceso debe corresponder a ese sitio, al auxiliar y a Core 1.10.9. El runner rechaza cuentas ajenas al lote. No instala el core ni cambia ramas; prueba lo que ya está instalado. Las pruebas locales no sustituyen este paso.

### Alternativa para quien administra un QA mediante Compose

Solo en el host que gestiona ese proyecto, con su configuración privada y Core 1.10.9 ya activo:

```bash
ASCLA_ACCEPTANCE_MODE=deployed \
ASCLA_TEST_PROJECT=ascla_qa \
ASCLA_TEST_ENV_FILE=.env.qa \
bash scripts/acceptance-tests.sh
```

El nombre debe empezar por `ascla` y contener `qa`. Este modo usa CLI para preparar/limpiar únicamente sus cuentas temporales; no inicia, elimina ni despliega los servicios existentes. Si solo tienes el panel WordPress, utiliza el modo `remote` anterior.

<a id="resultados"></a>

## 7. Abrir reportes y comprobar de dónde salen los datos

En el explorador de archivos, abre la carpeta `test-results/acceptance-FECHA-ID` indicada al finalizar y haz doble clic en **index.html**. `FECHA-ID` representa el nombre real de tu ejecución; no copies literalmente ese marcador.

| Página o archivo | Qué revisar |
|---|---|
| `index.html` | Estado de cada grupo, cantidades, destino y acceso a evidencias. |
| `integration.html` y `business-e2e.html` | Peticiones y comprobaciones de integración/flujos. |
| `integration.bruno.html` y `business-e2e.bruno.html` | Reportes originales de Bruno, separados de la presentación ASCLA. |
| `frontend.html` y `evidence.html` | Pasos de navegador, resultado, videos y capturas. |
| `tools.html` — Herramientas y origen | Herramientas/versiones, colección usada, código y huellas. |
| `summary.json` | Fecha, destino, versión registrada al emitir el acceso, commit local y estado limpio/con cambios. |
| `integration.xml`, `business-e2e.xml` y `frontend.xml` | Resultados JUnit para inspección o entrega técnica. |
| `cleanup.log` | Confirmación o diagnóstico de limpieza. |

Para revisar peticiones en la aplicación **Bruno**, abre la carpeta [tests/http](tests/http), que contiene `bruno.json`. Para inspeccionar la colección capturada en un reporte, descarga `bruno-collection.zip` desde Herramientas y origen, extráela y abre en Bruno la carpeta que contiene `bruno.json`.

Abrir la colección permite leer peticiones, pruebas y encadenamiento de IDs. No prepara cuentas ni limpia datos; ejecuta el flujo completo con `acceptance-tests.sh`, que genera las variables privadas y separa las sesiones de cada actor. La colección descargable no contiene credenciales.

`sources/` y `sources.json` conservan las fuentes capturadas; `evidence-manifest.json` permite contrastar sus huellas SHA-256. Detectan cambios posteriores, pero no son una firma independiente. Los reportes antiguos sin captura de fuentes lo declaran y no se atribuyen la colección actual.

Para regenerar solo la presentación de un reporte existente, con Node.js disponible:

```bash
node tests/acceptance-report.cjs test-results/acceptance-FECHA-ID
```

Sustituye el último argumento por la carpeta real. Esto no repite pruebas ni transforma fallos en aprobaciones. Conserva también los diagnósticos de ejecuciones incompletas. Los reportes omiten cuerpos/cabeceras HTTP sensibles; revisa la evidencia antes de compartirla y no publiques accesos, cookies ni nonces.

Registra fecha, entorno/URL, commit, SHA-256 del ZIP instalado, resultado de cada suite, enlace a SonarQube, limpieza y responsable. El commit del runner no demuestra por sí solo qué ZIP está instalado en el servidor. Los [resúmenes públicos existentes](COMPLIANCE.md#evidencia) identifican su propio alcance y no certifican paquetes posteriores.

<a id="uat"></a>

## 8. Pasar a UAT y completar la aceptación

Después de QA y su Quality Gate, con revisión de testers y autorización del PM:

```bash
git fetch origin
git switch uat
git pull --ff-only origin uat
git merge --no-ff qa -m "merge: integrar qa en uat"
git push origin uat
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=uat bash scripts/quality-ci.sh
bash scripts/sonar-manual.sh uat
```

El scanner selecciona **ASCLA-UAT** y solicita su token. Aplica las mismas comprobaciones de revisión limpia y reportes del commit actual. Instala manualmente en UAT el paquete de esa revisión y registra su SHA-256. Las presentaciones deben corresponder a `uat`; una repetición local no acredita su instalación remota.

La integración desde `qa` conserva los dos historiales mediante un merge. `git pull --ff-only origin uat` solo actualiza la copia local desde su remoto. Si el merge encuentra conflictos, resuélvelos, comprueba el resultado y confirma la integración antes de hacer push o ejecutar las validaciones; no uses un push forzado para borrar la divergencia.

Los testers recorren los casos acordados del [Documento de Análisis y Diseño](requirements/ascla-analysis.md), registran esperado/obtenido, evidencia y defectos. Incluye los controles de entorno de la sección 10 y las excepciones autorizadas de [COMPLIANCE](COMPLIANCE.md#decisiones). Ante un fallo, conserva la evidencia, corrige mediante el flujo del equipo y repite los casos afectados antes de aprobar.

El PM registra la aceptación o sus pendientes. Después abre un pull request **uat → main** y completa revisiones/controles. `main` debe estar protegida, sin push directo. Si el merge genera un commit nuevo, reincorpóralo después en `development` según el procedimiento del equipo; no ocultes divergencias mediante force push. La nomenclatura FE/BCK del curso requiere confirmar la excepción para este repositorio WordPress antes de dividirlo o renombrarlo.

<a id="local"></a>

## 9. Opcional: abrir un WordPress local para revisión manual

No necesitas este entorno para las suites desechables. Para explorar la interfaz, copia `.env.example` a `.env` **solo si todavía no existe** y completa las contraseñas de base, administrador y demo; configura `ASCLA_SITE_URL=http://localhost:8088`. Mantén `.env` privado.

El [Compose base](compose.yaml) no publica puertos al navegador. Para un local nuevo, crea esta extensión privada con puertos de loopback:

```bash
mkdir -p test-results
cat > test-results/compose.local-ports.yaml <<'YAML'
services:
  wordpress:
    ports: ["127.0.0.1:8088:80"]
  mailpit:
    ports: ["127.0.0.1:8025:8025"]
YAML
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
docker compose -f compose.yaml -f test-results/compose.local-ports.yaml up -d
docker compose -f compose.yaml -f test-results/compose.local-ports.yaml run --rm cli sh /opt/ascla-scripts/init-wordpress.sh
```

Abre `http://localhost:8088` y el correo capturado en `http://localhost:8025`. Si los puertos están ocupados, ajusta el archivo de puertos y la URL en `.env` antes de inicializar. El inicializador activa el plugin y configura URL/zona horaria; úsalo únicamente en el proyecto local previsto. Usa nombres/volúmenes distintos por entorno y conserva la misma configuración Compose al administrar ese local. El contenedor cron procesa tareas vencidas.

<a id="operacion"></a>

## 10. Completar comprobaciones manuales y del entorno

Estos pasos aportan evidencia para los [pendientes del PDF](COMPLIANCE.md#pendientes). Los controles de hosting corresponden al responsable técnico; el acceso de administrador WordPress puede no ser suficiente. Registra resultados reales por entorno, sin atribuirlos a las suites locales.

<a id="credenciales"></a>

### Credenciales y servicios externos

El responsable técnico conserva una copia privada y configura en entorno o `wp-config.php` solo las claves utilizadas:

| Servicio | Variable/constante |
|---|---|
| Gemini | `ASCLA_AI_KEY` |
| OpenAI | `ASCLA_OPENAI_KEY` |
| DeepSeek | `ASCLA_DEEPSEEK_KEY` |
| Google OAuth | `ASCLA_GOOGLE_CLIENT_SECRET` |
| Turnstile | `ASCLA_TURNSTILE_SECRET` |
| SMTP | `ASCLA_SMTP_PASSWORD` |

1. En **Configuración → Custodia de credenciales**, comprueba el origen sin registrar valores. Para RNF-007, activa `ASCLA_EXTERNAL_SECRETS_ONLY=1` y verifica que llega a HTTP, CLI y cron.
2. Prueba los servicios utilizados y la recuperación ante claves ausentes. El modo externo no recupera claves heredadas; una variable definida vacía deshabilita esa clave. El modo de compatibilidad `0` no acredita RNF-007.
3. Solo después de verificar cada servicio, el responsable puede retirar su opción cifrada heredada. No se elimina automáticamente. Conserva la configuración privada anterior y verifica rotación/protección de logs y backups. Los tokens OAuth personales siguen cifrados para poder renovarse.

<a id="entregas"></a>

### Correo, Calendar y cron

1. Envía una notificación de prueba a una cuenta autorizada y comprueba recepción con el proveedor real. Mailpit solo acredita captura local; véase el [alcance SMTP](COMPLIANCE.md#correo).
2. Comprueba que WP-Cron tiene tráfico suficiente o un ejecutor externo operativo. En **Configuración → Correo y recuperación → Revisar entregas**, revisa pendientes, fallos y reintentos; el operador con CLI dispone de `wp ascla deliveries`.
3. En un entorno desechable, simula un fallo y comprueba recuperación y auditoría del reintento. El esquema 15 conserva entregas cifradas junto con la transacción; tras ocho intentos fallidos requieren revisión administrativa. Verifica preferencias, membresía y caducidad de ofertas antes del envío.
4. Con Calendar real, crea/reprograma/cancela una prueba autorizada. Comprueba que el estado remoto final coincide y que un trabajo atrasado no recrea un evento cancelado. Una aceptación SMTP no prueba lectura ni garantiza recepción exactamente una vez; un resultado ambiguo puede ocasionar reintentos.

<a id="cache"></a>

### Caché y continuidad de transacciones

1. Si se utiliza Redis/Memcached u otro proveedor, pide al operador verificar desconexión y reconexión en QA/UAT. Las pruebas simuladas no certifican ese proveedor ni los hooks de terceros.
2. Abre dos sesiones y comprueba que cambios de perfil/permisos se reflejan entre procesos. Un rollback o savepoint no debe dejar datos no confirmados visibles.
3. El operador prueba interrupción entre commit e invalidación y recuperación por otro proceso/cron, con más de diez pendientes. Una invalidación antigua no debe restaurar valores obsoletos. La recuperación de caché reintenta sin el límite de ocho intentos del correo.
4. Conserva las sales de WordPress mientras haya trabajo cifrado y registra solo metadatos: no expongas claves ni payloads.

<a id="accesibilidad"></a>

### Accesibilidad, privacidad y aceptación funcional

1. Recorre las 13 rutas a 360, 768 y 1024 px en ambos temas, con roles y contenido representativos. Revisa menús, pestañas, tablas, adjuntos, diálogos anidados, imágenes y errores. Tab/Shift+Tab permanecen en el diálogo activo; Escape/cierre restauran foco y el fondo queda inerte.
2. Evalúa los resultados axe `incomplete`, lector de pantalla, zoom/reflujo, orden de lectura, textos alternativos, videos y PDF. Chromium local no acredita las dos versiones exigidas de Chrome, Firefox, Safari y Edge. Referencias: [patrón modal W3C](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/) y [API axe](https://github.com/dequelabs/axe-core/blob/develop/doc/API.md).
3. Repite en español/inglés ayudas, etiquetas y errores, conservando textos del usuario. Comprueba entradas inválidas desde UI y REST: el rechazo debe conservar lo anterior. Incluye los [formularios implementados](COMPLIANCE.md#formularios).
4. Revisa con cuentas de prueba acceso anónimo/membresía inactiva, descargas y REST; Directorio incompleto autorizado, búsquedas sin inferir identidades, chat inmutable/solo lectura, bloqueos/esperas, borradores, reprogramación y ofertas con vencimiento. Incluye Aliados, intereses automáticos respetando desmarcaciones, permisos acumulados y autorización de Junta. Los [casos QA](COMPLIANCE.md#casos-qa) describen correcciones, no sustituyen su ejecución en destino.
5. Para microeventos, comprueba propuesta, diagnóstico de candidatos, aprobación, publicación, cancelación y archivo/restauración del historial. Los grupos deben cumplir consentimiento, mínimos y afinidad antes de aprobar; registra el motivo del diagnóstico cuando no se genere una propuesta. Como administrador, abre la ficha y la revisión: Personas seleccionadas para el microevento debe mostrar los nombres del grupo y Por invitar antes de publicar. Aprobar por sí solo no debe indicar un envío. Publica, entra con dos asociados invitados, acepta con uno y rechaza con otro; vuelve a abrir la ficha administrativa y contrasta sus estados. Actualizar candidatos debe retirar de la lista a quienes dejaron de ser elegibles. Una cancelación anterior a publicar debe indicar que no hay invitación registrada. Los asociados y ejecutivos no deben recibir esta lista administrativa, ni siquiera accediendo directamente al detalle REST. Revisa móvil, ambos temas e inglés. La lista no acredita entrega externa de correos o Calendar.
6. En Perfil, guarda los datos del administrador sin activar participación social y comprueba los indicadores obligatorios. Desde Directorio, administración abre Actividad e intereses o Gestionar usuario. Al editar una cuenta antigua sin clasificación, selecciona Individual o Empresa asociada; luego inicia sesión como ese asociado y verifica que el tipo sea de lectura. No hay agrupación de cuentas ni control de cupos empresariales en esta entrega. Una conexión pendiente muestra Solicitud enviada y Cancelar visible, con confirmación. Tras eliminar una conexión, la tarjeta muestra la fecha de disponibilidad y no ofrece Conectar durante la espera; revisa la alineación con tarjetas de otros estados.
7. Para revisar carga, abre las herramientas del navegador → Red y recorre los 13 módulos con un asociado y un administrador de prueba. El arranque normal se incluye en el HTML privado; no necesita un GET adicional a `bootstrap`. Comprueba que puedas cambiar de sección mientras una consulta tarda, reintentar después de un error y guardar sin recibir contadores o perfiles anteriores. Mide el servidor publicado por separado: las comparaciones locales no certifican sus tiempos ni el umbral del análisis con 50 asociados simultáneos.
8. En Teléfono, elige país con ratón y teclado, pega números con + o 00 y comprueba la bandera y el prefijo. Incluye países que comparten +1, cambia de país sin perder la parte nacional, guarda, recarga y finalmente vacía el campo opcional. Otro asociado no debe recibir el teléfono; administración puede editarlo directamente desde Editar usuario. El selector de país y el número deben estar habilitados y guardarse juntos. Comprueba móvil, ambos temas y el campo internacional de respaldo si se bloquea la descarga del selector.
9. En Revisar contenido, alterna Publicaciones, Comentarios y Reportes recibidos: comprueba borradores aparte, aprobación de un comentario y revisión de un reporte sin eliminar el contenido. Abre Reportes ya revisados para consultar su registro. En Crear evento, revisa las explicaciones de asistentes, cupos y avisos y conserva un borrador incompleto. En Perfil, las dos ayudas retiradas no deben aparecer; el asterisco de ubicación queda en País/región y el teléfono muestra su aviso privado.
10. Para comprobar RAG, publica un recurso de prueba con un dato identificable y pregunta por ese tema al Asistente. Abre sus fuentes y contrasta la respuesta; continúa con dos repreguntas y luego inicia una conversación nueva. Un borrador ajeno o una fuente retirada no deben usarse. Repite con los proveedores configurados en el entorno autorizado. Las pruebas locales de los tres transportes interceptan HTTP: comprueban recuperación, permisos y contexto, sin acreditar disponibilidad, coste ni calidad de una respuesta real del proveedor.
11. En Consultas de Contacto, alterna Todas y los cuatro estados; busca por asunto o mensaje y comprueba que se conserve el filtro. Abre una consulta con ratón y teclado, escribe una respuesta y cierra/abre el resumen sin recargar: el texto debe conservarse. Al guardar, la consulta queda abierta. Resolver sin respuesta debe rechazarse. Cambia el responsable y verifica que el anterior deje de recibir asunto, mensaje e historial. Archiva una consulta cerrada y recupérala con Incluir archivadas; su autor debe conservar el historial. Revisa móvil, ambos temas e inglés.
12. Para privacidad de IA, utiliza una fuente sintética con referencias públicas a autores, instituciones, normas y conceptos y una intervención privada. Indica sus identidades y afiliaciones reservadas en el campo del editor y activa Chatham House. Verifica que el Asistente y el resumen conserven las referencias públicas sin mostrar identidad reservada ni revelar los datos marcados. Comprueba también una intervención reconocible por su rol, un correo y un enlace de perfil. El original editorial y la transcripción deben conservarse para el editor autorizado; otro asociado no debe recibirlos. La limpieza del historial no reconstruye nombres omitidos por generaciones antiguas: para recuperar esos nombres, regenera solo el recurso autorizado y revisa el resultado.

13. En Administración → Usuarios e intereses, abre Editar usuario con un asociado de prueba sin consultas de Contacto. Cambia nombres, correo, cargo, empresa, clasificación, teléfono, nacimiento y ubicación; despliega biografía, experiencia, enlaces e intereses, guarda y vuelve a abrir para comprobar persistencia. Prueba un enlace inválido, datos obligatorios vacíos y una edición simultánea desde otra sesión: el error debe conservar lo guardado y el formulario. Revisa Historial de cambios, privacidad y participación del asociado. Si vinculas una consulta opcional, debe estar asignada y activa y no podrá reutilizarse. Contrasta móvil, ambos temas e inglés. Esta edición directa es una excepción autorizada a RN-001/RN-032.

14. En Estadísticas, elige fechas y un tema y compara las tarjetas con las listas de asistencia guardadas. Distingue personas únicas de asistencias por evento, e inscripciones de asistencia real. Comprueba Resumen, Participación, Intereses, Comparar períodos y Temas con IA, sus explicaciones y navegación con teclado. Las vistas y el filtro deben aprovechar el ancho disponible incluso cuando estén cargados los estilos nativos de formularios de WordPress; en Participación deben verse las seis columnas en escritorio y la tabla debe poder desplazarse internamente en móvil. Buscar un título debe actualizar opciones; seleccionar un evento debe filtrar cifras. Quitar filtros conserva las fechas. Envía un período futuro: el servidor debe rechazarlo sin hacer desaparecer el formulario. Un período sin eventos debe orientar para cambiar las fechas y mostrar Sin datos donde no puede calcular una tasa. Abre Revisar asistencias, corrige una persona, revisa/importa un CSV y verifica la lista. La vista previa no debe escribir y una corrección manual debe conservarse al importar Zoom. Repite escritorio/móvil, ambos temas y el acceso del ejecutivo en inglés. La IA propone temas; no aporta las cifras de asistencia.

### Seguridad, rendimiento y copias

Con el responsable del entorno, comprueba HTTPS/TLS, Wordfence/Akismet, Turnstile, bloqueos acordados y logs privados con rotación. Mide 50 asociados simultáneos y los umbrales de navegación, mensajería y estadísticas del PDF; las suites funcionales no equivalen a una prueba de carga.

Verifica copias diarias de base y archivos esenciales, retención mínima de siete días y una restauración ensayada, incluidas las sales para descifrar datos privados. Conserva historial de disponibilidad y mantenimientos. Registra fecha, revisión, destino, responsable y resultado de cada control.

Para comprobar los cambios de perfil, introduce un enlace de otro dominio en LinkedIn o X: el formulario debe impedir guardarlo y explicar el dominio permitido. Repite con linkedin.com, x.com y twitter.com y confirma que se guarda; el sitio personal acepta su propia URL. Comprueba también la edición administrativa y la etiqueta «Individual».

En Administración → Galerías y recursos, busca una publicación y pulsa Ocultar. Introduce el motivo, confirma que aparece como Oculto y abre Historial. Con un asociado distinto del autor, comprueba que desaparece del catálogo y que su enlace directo deja de ser accesible. Vuelve a publicarla desde el registro y verifica que conserva el mismo ID, contenido y archivos. Repite con galería y recurso; un Ejecutivo solo puede gestionar el contenido autorizado. En Mensajería, una conexión activa permite escribir «Escribe un mensaje…» y no debe pedir aceptar una solicitud antigua.

<a id="zap"></a>

## 11. Pruebas de seguridad con ZAP, sin Jenkins

Estas pruebas son independientes de Jenkins. El proyecto es un monolito WordPress: sus páginas y su API se comprueban por separado en la misma instalación temporal. No se utiliza una aplicación Next.js ni se ejecutan ataques contra el WordPress publicado.

### Preparar y ejecutar

1. Abre Docker Desktop y comprueba que funciona el motor Linux. En Bash/WSL necesitas Docker Compose, Python 3, OpenSSL y `flock`. Reserva al menos 8 GB disponibles para Docker. La primera ejecución descarga las imágenes y las dependencias fijadas en el lockfile.
2. Desde la raíz del repositorio, ejecuta:

```bash
bash scripts/security-tests.sh
```

3. Espera el resultado final y abre `test-results/security-latest/index.html`. La ejecución prepara una base nueva, cuatro cuentas sintéticas, correo capturado e IA simulada; usa HTTPS con un certificado temporal. El verificador HTTP independiente confía expresamente en ese certificado. No necesita contraseñas del sitio ni tokens personales.
4. Conserva los reportes de esta ejecución junto con la revisión del código. El comando sustituye únicamente sus archivos generados conocidos; el directorio contiene la última ejecución. Al terminar elimina sus contenedores, volúmenes de datos, cookies, contraseñas y clave TLS privados. Revisa `cleanup.log` si hubo una interrupción. No publica ni instala el ZIP.

### Qué se comprueba y cómo exponerlo

| Etapa | Prueba y evidencia |
|---|---|
| Páginas ASCLA | Traditional Spider, Client Spider y escaneo activo con ZAP. Reportes nativos `frontend-zap.html` y `frontend-zap.json`. |
| API ASCLA | Importación del contrato OpenAPI generado y escaneo activo de 18 operaciones GET. Reportes `backend-zap.html`, `backend-zap.json` y contrato `openapi.json`. |
| CSP independiente | Peticiones HTTPS fuera de ZAP: política impuesta, restricciones de scripts/estilos, nonce diferente por respuesta y cabeceras. Resultado `csp-validation.json`. |
| Navegador | Administrador y asociado recorren los módulos, guardan el perfil y cambian idioma bajo CSP; comprueba permisos, CORS y acceso nativo con un nonce ajeno. Resultado `browser-security.json`. |
| Resultado conjunto | `security-summary.json` conserva PASS, FAIL, WARN o ERROR; `source-manifest.json` identifica archivos e imagen ZAP. Una modificación de las fuentes durante el análisis invalida el resultado. |

En la exposición muestra primero el resultado conjunto, después ambos HTML nativos y la validación CSP. Explica qué rutas y operaciones se analizaron, qué alertas se corrigieron, qué no se analizó y la revisión exacta utilizada. Una prueba interrumpida o sin autenticación válida es ERROR; no se presenta como aprobada.

La política de `security/zap-rules.tsv` rechaza por defecto toda alerta de riesgo bajo, medio o alto. Las informativas se conservan. No se han añadido exclusiones de alertas para obtener un resultado favorable. Los códigos son **0 PASS**, **1 FAIL**, **2 WARN**, **3 ERROR**; un fallo tiene prioridad sobre una advertencia. El análisis activo ejecuta las reglas aplicables al WordPress/PHP, con intensidad LOW: primero XSS DOM con un solo hilo y navegador, después una pasada HTTP con ocho hilos. Ambas deben completar sus reglas; la unión debe completar las 52 reglas aplicables. El informe registra además la regla 40043 (Log4Shell) como no aplicable: su objetivo es Java/Log4j y este laboratorio ejecuta PHP, Apache y MariaDB, sin ese runtime ni un servicio OAST de Java. No se declara ejecutada ni se filtran sus posibles alertas. La intensidad reduce la cantidad de payloads, por lo que debe constar al presentar el alcance. Los tiempos máximos, la navegación del Client Spider limitada a 60 segundos y los límites de recursos evitan ejecuciones indefinidas. Se exige que cada regla declarada aplicable termine: una regla pendiente, un navegador XSS fallido, falta de recursos o una cola pasiva sin terminar producen ERROR, aunque ZAP indique 100 % en el estado general.

El alcance incluye las 14 rutas HTML ASCLA, acceso anónimo y páginas con una sesión administrativa comprobada antes y después del análisis. El navegador comprueba también al asociado. No incluye todo el administrador nativo de WordPress, rutas arbitrarias de otros temas/plugins, operaciones destructivas o de escritura ni integraciones reales de IA, correo, Calendar o Zoom. Las pruebas de integración/E2E y la revisión de permisos complementan este análisis; un resultado local sin alertas no certifica esos componentes ni demuestra ausencia universal de vulnerabilidades.

El recorrido DOM excluye explícitamente el acceso nativo de WordPress, su cierre de sesión y las acciones de contraseña: puede pulsar contenedores de botones y enlaces, y un simple contexto de inclusión no impide esas salidas. Las suites dedicadas verifican el acceso y sus permisos por separado. El escáner solo acepta el host temporal generado y utiliza una red interna de Docker. No admite un parámetro para atacar producción. Las reglas de fuerza bruta pueden bloquear correctamente la IP compartida del laboratorio: el fixture limpia exclusivamente sus contadores entre fases, con cuentas y entorno local validados. Nunca desactiva la protección ni limpia contadores durante los ataques.

### Configuración que debe llegar al entorno de destino

El plugin impone CSP con nonce por respuesta y `strict-dynamic`, bloquea eventos en atributos, añade SRI a los assets locales servidos por WordPress, restringe CORS de su API y protege cookies y formularios de acceso. `script-src` no autoriza dominios completos: los scripts iniciales requieren nonce y pueden cargar sus dependencias. Los marcos, imágenes y conexiones de Turnstile y los reproductores tienen permisos específicos; los marcos YouTube son `credentialless`. El código no incorpora una clave Turnstile de pruebas al producto.

Para revisar la alerta de JSONP de CSP Evaluator, consulta la cabecera de una respuesta nueva de `/login/` o de un módulo ASCLA. En `script-src` deben aparecer solo el nonce y `strict-dynamic`; YouTube puede seguir apareciendo en `frame-src` y en las directivas de imágenes. Verifica además un video y Turnstile en el navegador: una evaluación de texto de la política no demuestra compatibilidad. El botón «Actualizar datos de YouTube» ya no existe; los metadatos se obtienen automáticamente al guardar o procesar el recurso. Si cambias la política o el ZIP después de un análisis ZAP, conserva el resultado anterior con sus huellas y vuelve a ejecutarlo para atribuir un resultado completo al nuevo paquete.

`config/security.conf` y `config/security.ini` se montan en el WordPress de Docker para ocultar versiones de Apache/PHP, bloquear TRACE y listados de directorios y proteger también los archivos estáticos. **Estos archivos no se instalan con el ZIP del plugin**: el responsable del hosting debe aplicar su equivalente y reiniciar o recargar el servicio. En el proxy HTTPS del destino debe establecer HSTS también para recursos estáticos y errores, TLS 1.2 o superior y un certificado válido; el certificado temporal del laboratorio no se despliega. Comprueba compatibilidad de las políticas con cualquier plugin de terceros antes de extenderlas a todo `wp-admin`.

Tras instalar el plugin y aplicar la configuración del servidor, repite las verificaciones en QA con la revisión y el entorno reales autorizados. El chequeo independiente permite revisar las cabeceras sin realizar ataques:

```bash
node scripts/check-security-headers.cjs https://wordpress.ingsoftware.lat/login/ test-results/csp-qa.json
```

Los originales ZAP pueden contener URLs, nonces o cabeceras de sesión: permanecen privados en `test-results/`, ignorado por Git. Comparte únicamente un resumen revisado sin credenciales. Los reportes HTML/JSON y los accesos privados no deben subirse automáticamente a Git.

Referencias del fabricante: [Full Scan](https://www.zaproxy.org/docs/docker/full-scan/), [API Scan](https://www.zaproxy.org/docs/docker/api-scan/) y [API de ZAP](https://www.zaproxy.org/docs/api/). La integración YouTube utiliza el [reproductor oficial sobre un iframe existente](https://developers.google.com/youtube/iframe_api_reference).

## 12. Mantener la guía y las evidencias

Antes de compartir cambios documentales:

```bash
python3 scripts/check-docs.py
```

Si cambias la matriz, edita primero [requirements/ascla-analysis.json](requirements/ascla-analysis.json) y ejecuta `python3 scripts/check-docs.py --write-matrix`. Conserva textos/prioridades originales y justifica estados; una corrección documental no aumenta el porcentaje de cumplimiento.

Se mantienen los cinco MD principales y los tres MD de la [entrega de pruebas](pruebas/README.md), enlazados en [README](README.md). Usa rutas relativas y dominios del proyecto; evita carpetas personales, rutas absolutas de equipos, nombres de dispositivo e IP privadas en texto, enlaces y bloques de comandos. El verificador comprueba estas referencias, enlaces, anclas, exclusiones Git y sincronización de la matriz.

Los resúmenes JSON/JUnit revisados de `docs/evidence/` se permiten en Git. Los originales, accesos y archivos locales siguen en `test-results/`, ignorados. Por solicitud del responsable, `pruebas/` incluye copias publicables de la colección, reportes, videos y capturas, con las credenciales excluidas y la procedencia registrada. Valida la carpeta con `python3 scripts/verify-qa-delivery.py`. Este cambio documental no requiere otra versión ni reconstruir el plugin.
