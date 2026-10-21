# Pruebas de integración y E2E de ASCLA

Esta carpeta reúne la entrega de pruebas para GitHub: colección Bruno con variables, explicación de los flujos, dashboard de resultados y evidencias de Playwright. Los resultados identifican su fecha y entorno; la fecha de preparación de esta carpeta no equivale a otra ejecución.

## Última ejecución publicada: 16 de octubre de 2026

La revisión del **16 de octubre de 2026** se ejecutó en un **WordPress local aislado con el código de esa fecha**: **58/58 peticiones y 105/105 comprobaciones de Bruno**, más **5/5 recorridos de Playwright con 11 videos y 11 capturas**. Incluye las reglas del administrador comprobadas en esa etapa. El asistente usa el proveedor mock local. Esta carpeta guarda únicamente la última ejecución de cada herramienta; no certifica los cambios posteriores de 1.10.9 ni instala el plugin en el servidor publicado.

El **19 de octubre** se actualizan la colección importable, su ZIP y los ejecutores para obtener la cookie y el nonce del nuevo formulario de acceso. Los reportes, videos y fuentes de sus ejecuciones del 16 de octubre se conservan sin reescribirlos; no se presentan como una nueva ejecución de seguridad. El [manual ZAP](../MANUAL_QA_UAT.md#zap) describe esa validación independiente.

## Abrir la entrega

- [Dashboard general](index.html): entrada a los resultados de backend y frontend. Descarga la carpeta y abre el HTML en el navegador; GitHub permite consultar los MD y descargar los archivos.
- [Backend: integración y E2E](backend/README.md): endpoints, reglas de negocio, dependencias entre peticiones y uso de Bruno.
- [Frontend: E2E con Playwright](frontend/README.md): recorridos desde la interfaz, videos, capturas y diagnóstico de fallos.
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
```

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
| Interfaz | CP-011, CP-013 y escenarios de conexión, eventos y suspensión anteriores | Cinco flujos mediante controles reales: perfil; conexión/chat/bloqueo; publicación/inscripción; aforo/espera/notificación; suspensión/reactivación. |

El diseño menciona **OpenAI**. La ejecución vigente utiliza **mock local** para comprobar el flujo asíncrono; no acredita la integración específica con OpenAI. La suspensión devuelve **403** por nonce revocado; esa comprobación no acredita RNF-005 sobre cierre de sesión/inactividad y HTTP 401. Tampoco se acredita carga, disponibilidad, latencia bajo concurrencia, SMTP real ni los módulos 6 y 8. No equivale a aprobar todos los criterios de salida ni UAT.

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

La salida de cada comando indica la carpeta original en `test-results/`. Para renovar esta entrega con los últimos resultados:

```bash
node scripts/build-qa-delivery.cjs
python3 scripts/verify-qa-delivery.py
python3 scripts/check-docs.py
```

El constructor publica solo la última ejecución de Bruno y la última de Playwright, retira las anteriores de `pruebas/`, genera las vistas y el ZIP publicable, y registra las huellas de los archivos. No convierte fallos en aprobaciones. Las copias publicadas retiran rutas personales y los códigos de color incompatibles con JUnit; su procedencia conserva la huella del archivo original y la de la copia. Los originales completos siguen en `test-results/`.

## Qué entregar

Sube `pruebas/` junto con los scripts y fuentes del repositorio. La carpeta no depende de enlaces a carpetas personales ni de los reportes locales ignorados para consultar la evidencia ya publicada. Los videos tienen tamaño apto para Git; no se incluyen instalaciones de herramientas ni credenciales.

Los cuatro flujos de backend y los cinco de interfaz tienen alcances propios. Se conserva una sola ejecución por herramienta. Si la última de Playwright falla, su evidencia queda en `fallidas/`; si aprueba, queda en `aprobadas/`. Al renovar la entrega se retiran los lotes anteriores de ambas categorías. Los originales locales de `test-results/` quedan fuera de esta carpeta.
