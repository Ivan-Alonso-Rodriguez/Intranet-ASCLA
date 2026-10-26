# Jenkins, Trivy y seguridad de ASCLA

El [Jenkinsfile](../Jenkinsfile) ejecuta controles reales antes del despliegue. No hay parámetros para saltarlos ni excepciones por vulnerabilidades sin corrección.

## Flujo obligatorio

1. Checkout limpio y pruebas del control de seguridad.
2. Construcción de las cuatro imágenes desde bases fijadas por digest. WordPress conserva PHP 8.3 y usa Apache con PHP-FPM sobre Alpine; MariaDB conserva la rama 11.4 y la inicialización oficial sobre Alpine. El buzón local utiliza MailDev 3 con dependencias corregidas y fijadas por lockfile; el nombre interno `mailpit` se conserva para la conexión SMTP existente. Las versiones están fijadas en [images.json](images.json).
3. Controles en paralelo, todos bloqueantes: Trivy en todas las ramas; en `qa` y `uat`, pruebas/cobertura + SonarQube + Quality Gate y ZAP/CSP en HTTPS desechable.
4. Solo si todos pasan, verificación adicional del resultado de Trivy, las fuentes y los cuatro IDs de imagen; después se solicitan las credenciales y se despliega.
5. Archivado incluso con build fallido, publicación HTML y limpieza del workspace. Cada job de rama conserva únicamente su última ejecución y sus artefactos, aunque la anterior haya sido exitosa.

`main` continúa sin desplegar. `development` conserva el alcance previo de calidad: ahora debe pasar Trivy antes de desplegar. SonarQube y ZAP son obligatorios en QA/UAT.

Usa un job multibranch o una única rama explícita de SCM (`development`, `qa`, `uat`, `main`). Una configuración ambigua se rechaza. La rama se obtiene de `BRANCH_NAME` o de esa configuración SCM explícita: un checkout detached puede carecer de `GIT_BRANCH` y no debe impedir que se ejecuten los controles de QA/UAT.

## Regla de Trivy

Solo se permite LOW (o ausencia de vulnerabilidades). MEDIUM, HIGH y CRITICAL bloquean; UNKNOWN y los errores técnicos también bloquean por no poder acreditar el cumplimiento. No se usa `ignore-unfixed`, archivos de excepciones, políticas VEX ni filtros de estado que excluyan hallazgos. El contenedor del escáner no recibe variables `TRIVY_*`, configuración del repositorio, secretos ni el socket Docker.

Se genera el JSON completo con todas las severidades. La salida cero utilizada para recoger ese JSON **no decide la aprobación**: el mismo proceso aplica inmediatamente una política fija y devuelve error si no cumple. El JSON no se recorta; el HTML oficial se convierte desde ese mismo archivo, sin volver a analizar ni consumir otra base de datos. La conversión falla de forma bloqueante si no puede completarse.

Se escanean las cuatro imágenes efectivas: ASCLA/WordPress, CLI/cron, MariaDB y el buzón MailDev (rol histórico `mailpit`). `docker image save` liga cada ID inmutable con el digest de configuración OCI que aparece en Trivy; esto funciona también con el almacén containerd de Docker. El despliegue revalida las huellas de los reportes y utiliza exclusivamente esos IDs, con `pull_policy: never` y sin montar el código del workspace.

No se garantiza que las imágenes actuales aprueben. Los hallazgos requieren actualizar/corregir las imágenes y volver a ejecutar el pipeline; no se declaran falsamente resultados verdes. Los conteos representan hallazgos por paquete: una misma CVE puede repetirse en varios paquetes.

## Corrección de las imágenes del 26 de octubre de 2026

El análisis anterior registró 379 hallazgos bloqueantes: 357 en WordPress/Apache sobre Debian, 21 en MariaDB sobre Ubuntu y 1 en el capturador de correo. La corrección cambia las bases a Alpine actualizadas, conserva WordPress 7.1.3, PHP 8.3, Apache y MariaDB 11.4, y sustituye el capturador local por MailDev con un lockfile de dependencias corregidas. No se borran bases de vulnerabilidades ni metadatos de las dependencias; npm/yarn quedan fuera del runtime porque solo se necesitan en la etapa de construcción. El primer build corregido de Jenkins local aprueba las cuatro imágenes con cero hallazgos de todas las severidades. Los resultados son de esa ejecución y base de datos de Trivy; futuras CVE pueden volver a bloquear una imagen.

La prueba de migración inicia un volumen con MariaDB oficial 11.4.13 y lo vuelve a abrir con la nueva imagen 11.4.13: conserva las mismas huellas de datos UTF-8, JSON y binarios. Antes de actualizar una instalación real debe existir un respaldo de su base de datos. El SMTP interno conserva `mailpit:1025`; el buzón usa ahora la API MailDev `/api/email` y la web en el puerto 8025. La prueba de recuperación comprueba envío SMTP, consulta del mensaje, cambio de contraseña y rechazo del enlace consumido.

La suite de calidad usa [compose.quality.yaml](compose.quality.yaml) para copiar el plugin incluido en la imagen a un volumen nativo y ejecuta PHPUnit sobre una copia nativa de las pruebas. Esto evita que la iteración de directorios PHP en Alpine omita archivos de un bind mount de Windows. El validador de cobertura exige que cada archivo `tests/php/*Test.php` tenga casos ejecutados; un JUnit parcial no acredita la suite completa. Los volúmenes de calidad se eliminan al terminar.

## Artifacts y presentación

En el build de Jenkins:

- `Artifacts → test-results/ci-artifacts/trivy/index.html`: resumen y resultado global.
- `app.html`, `cli.html`, `db.html`, `mailpit.html`: detalle oficial completo de Trivy.
- Sus JSON originales y `summary.json`: evidencia verificable, versiones, digest y conteos.
- El enlace lateral **Trivy · seguridad de imágenes** publica el HTML. También puede descargarse y abrirse localmente; no se debilita la CSP de Jenkins para visualizarlo.
- En QA/UAT, **ZAP y CSP · seguridad web** y sus HTML/JSON publicables. Se excluyen cookies, sesiones, credenciales, cuerpos HTTP y valores de consulta del material archivado.
- Cobertura, resultados JUnit y `coverage/source-revision` se conservan cuando se generan, para comprobar la revisión exacta de las pruebas importadas.

La retención utiliza `numToKeepStr: '1'`, `artifactNumToKeepStr: '1'`, `removeLastBuild: true` y HTML Publisher sin historial adicional. Los volúmenes de una aplicación desplegada y las cachés de herramientas no son reportes y no se destruyen para cumplir la retención.

## Requisitos del Jenkins del curso

Agente Linux de confianza con Git, Python 3, Bash, OpenSSL, `flock`, Docker y Compose moderno compatible con `volumes_from`, `pull_policy` y `--renew-anon-volumes`. Todas las etapas usan el mismo agente. Debe existir la red externa `proxy_net` que ya utiliza el proyecto.

Plugins: Pipeline declarativo, Git, Credentials Binding, Timestamper, JUnit, SonarQube Scanner y HTML Publisher. Las versiones utilizadas en el laboratorio local se conservan en [jenkins/plugins.txt](jenkins/plugins.txt).

Mantener los nombres de configuración existentes:

- SonarQube: servidor `SonarQube-Server`, herramienta `SonarScanner`, credenciales Secret text `ASCLA_SONAR_QA` y `ASCLA_SONAR_UAT`, y webhook de Quality Gate hacia Jenkins. El análisis utiliza respectivamente `ASCLA-QA` y `ASCLA-UAT` y exige cobertura de la misma revisión Git.
- Credenciales Secret file: `ASCLA_DEV`, `ASCLA_QA`, `ASCLA_UAT`, con los valores de entorno adecuados. No incluirlas en Git ni en imágenes.
- Proteger Jenkinsfile/configuración y limitar quién puede ejecutar código en el agente. El repositorio no ofrece interruptores para desactivar controles.

La configuración de [compose.runtime.yaml](compose.runtime.yaml) conserva DB y uploads. Reemplaza el core anónimo al actualizar para impedir que una instalación anterior oculte el código de la imagen recién analizada; reinicia cron con el mismo volumen. No monta el plugin desde el workspace, que se limpia al terminar.

## Laboratorio local opcional

Ejecutar desde la raíz del repositorio en Linux/WSL con Docker Desktop. El laboratorio monta el socket Docker y, por ello, requiere autorización explícita de su operador. Da acceso amplio al daemon; úsalo únicamente con código de confianza. Se limita a `127.0.0.1:18090`, requiere autenticación, usa una contraseña aleatoria fuera de Git y se retira al finalizar. Jenkins recibe el grupo exacto del socket, no el grupo root.

```bash
docker build -t ascla-jenkins-local:verified -f ci/jenkins/Dockerfile ci/jenkins
python3 scripts/jenkins-local.py start
python3 scripts/jenkins-local.py lint
python3 scripts/jenkins-local.py run
python3 scripts/jenkins-local.py status
```

Usuario local: `ascla.local`. La contraseña está exclusivamente en `test-results/jenkins-local/private/admin-password`. El helper usa esas credenciales sin imprimirlas. El snapshot Git temporal incluye las fuentes actuales, incluso cambios pendientes, sin copiar los archivos ignorados; su commit local se distingue del commit original del repositorio mediante las huellas de fuentes.

El job local usa un snapshot privado en `main`, la rama que el Jenkinsfile ya configura sin despliegue. Comprueba la construcción, Trivy, el archivado y la retención sin solicitar credenciales de despliegue. No cambia la rama `development` del repositorio del usuario. Los controles de QA/UAT permanecen activos en sus ramas.

Después de que termine el build:

```bash
python3 tests/jenkins-security.py
python3 scripts/jenkins-local.py stop
```

La prueba descarga los artefactos del build real a `test-results/ci-jenkins-latest/`, comprueba el resultado de Trivy sin presuponer aprobación, verifica que no se ejecutó despliegue y comprueba aparte la retención con un job de almacenamiento SUCCESS → FAILURE, sin funciones de despliegue. El último informe se puede presentar sin mantener Jenkins encendido. El helper no configura ni despliega los entornos externos del curso.

Pruebas rápidas de la política (incluyen reportes alterados, otra imagen, ausencia de evidencia, severidades, errores y privacidad):

```bash
python3 -m unittest discover -s tests -p test_ci_security.py -v
```

Referencias oficiales: [reportes de Trivy](https://trivy.dev/docs/latest/guide/configuration/reporting/), [Pipeline y archivado](https://www.jenkins.io/doc/pipeline/tour/tests-and-artifacts/), [opciones de Pipeline](https://www.jenkins.io/doc/book/pipeline/syntax/).
