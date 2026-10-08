# Flujo manual de desarrollo, QA y UAT

El flujo activo usa Git, Docker y SonarQube. No requiere Jenkins; el Jenkinsfile es opcional e inactivo mientras no se configure un job.

Versión documentada: **1.10.7**, esquema **14**; revisión del 8 de octubre de 2026. Usa **el mismo checkout** para desarrollar y probar. Antes de cambiar de rama, identifica y guarda los cambios que deban viajar con la entrega; no ejecutes estos comandos a ciegas sobre cambios ajenos.

## Guardar desarrollo

```bash
git switch development
git pull --ff-only origin development
git status
git diff
git add .
git diff --cached --stat
git commit -m "fix: cerrar ajustes funcionales y documentacion de ASCLA 1.10.7"
git push origin development
```

Revisa los archivos preparados antes del commit. No necesitas crear un nuevo commit al pasar por cada entorno. Checkout, pruebas y push no crean commits.

## Promover y validar QA

```bash
git fetch origin
git switch qa
git pull --ff-only origin qa
git merge --ff-only development
git push origin qa
```

En WSL y sobre el mismo checkout:

```bash
cd /mnt/c/Users/USER/Downloads/Intranet-ASCLA
git switch qa
git pull --ff-only origin qa
docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=qa bash scripts/quality-ci.sh
bash scripts/sonar-manual.sh qa
```

Ajusta la ruta anterior si mueves el repositorio. El scanner pide el token sin mostrarlo, usa ASCLA-QA y espera el Quality Gate. Utiliza un token nuevo; revoca los compartidos en el chat. La URL es `https://sonarqube.ingsoftware.lat`, sin formato Markdown.

El script de calidad levanta y elimina su propio WordPress desechable, ejecuta PHPUnit y navegador y genera Clover, JUnit y LCOV. **No despliega el sitio.** Solo registra `coverage/source-revision` cuando termina correctamente y el checkout permanece limpio y en el mismo commit. Incluso un cambio documental posterior exige guardar el nuevo commit y regenerar los reportes para ese commit. El scanner rechaza reportes de otra revisión o cambios sin commit.

## Promover UAT

Con pruebas, Quality Gate y revisión de testers aprobados, y autorización del PM:

```bash
git fetch origin
git switch uat
git pull --ff-only origin uat
git merge --ff-only qa
git push origin uat

docker network inspect proxy_net >/dev/null 2>&1 || docker network create proxy_net
BRANCH_NAME=uat bash scripts/quality-ci.sh
bash scripts/sonar-manual.sh uat
```

El último comando usa ASCLA-UAT y pide su token. Si exportas SONAR_TOKEN manualmente, usa el token correcto y al terminar ejecuta `unset SONAR_TOKEN` en esa terminal. Un script no puede limpiar variables de su terminal padre.

## Instalar el paquete y aceptar UAT

La promoción de ramas y `quality-ci.sh` no actualizan el WordPress de destino. Genera y verifica el instalable desde la revisión que vas a entregar:

```bash
python3 scripts/build.py
python3 scripts/verify-package.py
```

Entrega `dist/ascla-core.zip` junto con `dist/ascla-core.sha256`. En WordPress, usa **Plugins → Añadir plugin → Subir plugin** y, si corresponde, reemplaza la versión instalada. La carpeta interna y el plugin siguen llamándose **ascla-core**; **1.10.7** es su versión. Comprueba versión, carga de recursos y operación de cron en el entorno de destino.

La aceptación UAT debe recorrer los [casos QA reportados](BUGFIXES_QA.md), Directorio, perfiles, conexiones, chat, eventos/microeventos y únicamente Me gusta. Comprueba además integraciones reales y la configuración operativa indicada en [COMPLIANCE.md](COMPLIANCE.md). Registra el commit, SHA-256 del ZIP, fecha, entorno, resultados y responsable de aceptación.

Las pruebas locales completas registradas del 8 de octubre son anteriores al ajuste final de reacción única; ese ajuste tiene pruebas específicas aprobadas. Ninguna de esas evidencias sustituye QA/UAT del commit que promociones ni un Quality Gate remoto. No se exige 100 % de cobertura; deben cumplirse las condiciones configuradas del proyecto y corregirse los fallos.

## Publicar en main

Las instrucciones del curso exigen **main protegida y sin push directo**. Abre un pull request **uat → main** en GitHub, adjunta la evidencia QA/UAT y realiza el merge mediante sus controles y revisiones.

Si la política de main crea un commit de merge, reincorpora después main en development con el procedimiento del equipo. No uses force push ni squash repetido para ocultar divergencias.

La nomenclatura de repositorios exigida por el curso para frontend/backend debe confirmarse con el docente para esta arquitectura de plugin WordPress antes de dividir o renombrar el proyecto.

## Cuando --ff-only se detiene

`git merge --no-edit` evita abrir el editor, pero puede crear un commit de merge. `--ff-only` avanza la rama sin crear commits adicionales y se detiene si las ramas divergieron. Examina `git log --graph --oneline --decorate --all -30`, integra conscientemente los cambios y vuelve a probar.

Los commits compartidos conservan su identificador: GitHub puede mostrarlos en varias ramas, pero no los duplica.

Referencias: [git merge](https://git-scm.com/docs/git-merge), [git pull](https://git-scm.com/docs/git-pull), [ramas protegidas](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches), [Quality Gate y parámetros de SonarScanner](https://docs.sonarsource.com/sonarqube-server/analyzing-source-code/analysis-parameters/parameters-not-settable-in-ui).
