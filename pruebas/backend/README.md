# Backend: integración y E2E con Bruno

Abre el [Bruno Run Dashboard](dashboard.html) para revisar resultados, peticiones, comprobaciones y reportes HTML/JSON/JUnit. La [colección ZIP](coleccion-bruno.zip) y la [carpeta importable](coleccion-bruno) contienen los mismos archivos.

La seguridad dinámica de la API se entrega por separado en el [ZAP local de backend](../seguridad/backend-zap.html), con 18 operaciones GET y sus resultados del 22 de octubre. Consulta las [fuentes de las pruebas locales](../seguridad/fuentes.html) y el [sustento de seguridad](../seguridad/sustento.html) para revisar alcance, autenticación y límites. Estas operaciones ZAP no se suman a las 81 peticiones/149 comprobaciones de Bruno.

## Importar la colección

1. Descomprime `coleccion-bruno.zip`.
2. En Bruno, selecciona **Open Collection / Abrir colección** y elige la carpeta con `bruno.json`.
3. Abre **1. Integración API** o **2. Flujos principales compartidos (6)**.
4. Ejecuta una carpeta completa y en orden. Las respuestas proporcionan los IDs de las peticiones siguientes.

La URL de QA, los usuarios y los IDs están en **Configuración → Vars**. Para autenticar, el ejecutor del repositorio genera un entorno temporal a partir de `test-results/playwright-usuarios.json`. En Bruno de escritorio puedes usar un entorno privado con `claveAsociado`, `claveAna`, `claveBruno`, `claveCarla` y `claveAdministrador`; el asociado de integración es Ana y ambas claves deben corresponder a ella. Ese entorno no se publica. La sesión y el nonce de cada actor se obtienen automáticamente y se mantienen en memoria.

La colección se ejecutó de nuevo el **22 de octubre** sobre el **QA publicado con 1.10.9**: **81/81 peticiones y 149/149 comprobaciones aprobadas**. Antes de cada login obtiene su formulario, conserva su cookie y envía su nonce. Los GET de preparación del pre-script no son peticiones adicionales definidas en la colección. La consulta de Directorio exige ahora exactamente doce perfiles por página.

## Integración: endpoints del backend

Son **18 peticiones y 26 comprobaciones**. Las rutas REST de la tabla parten de `/wp-json/ascla/v1`.

| Prueba | Petición | Esperado | Para qué sirve |
|---|---|---:|---|
| Iniciar sesión | `POST /wp-login.php`, fuera de REST | 302 | Verifica que WordPress acepta las credenciales. |
| Obtener nonce | `GET /intranet/`, fuera de REST | 200 | Comprueba la sesión y la protección de REST. |
| Identificar al asociado | `GET /bootstrap` | 200 | Confirma que la sesión pertenece a Ana. |
| Acceso anónimo | `GET /profiles` sin sesión | 401 | Protege el directorio privado. |
| Permiso administrativo | `GET /admin/users` como asociado | 403 | Impide administrar usuarios sin el rol requerido. |
| Consultar perfil | `GET /profiles/{id}` | 200 | Obtiene el dato original de Empresa. |
| Empresa vacía | `POST /profiles/me` | 400 | Exige el campo obligatorio en el servidor. |
| Persistencia tras rechazo | `GET /profiles/{id}` | 200 | Confirma que el rechazo no borró la empresa anterior. |
| Directorio paginado | `GET /profiles?page=1` | 200 | Comprueba doce perfiles por página y cálculo de páginas. |
| Rango de fechas inválido | `GET /content/event?after=2099-12-31&before=2020-01-01` | 400 | Rechaza una fecha inicial posterior a la final. |
| Login administrativo | `POST /wp-login.php` | 302 | Obtiene sesión y nonce propios del administrador. |
| Inicio administrativo | `GET /intranet/` como administrador | 302 a `/administracion/` | Abre el panel correspondiente al rol. |
| Exclusión del directorio | `GET /profiles`, recorriendo todas las páginas | 200 | Incluye a Ana y excluye al administrador. |
| Ficha administrativa oculta | `GET /profiles/{administrador}` como asociado | 404 | Evita ofrecer administración como contacto. |
| Conexión hacia administración | `POST /relations` como asociado | 400 | Rechaza una solicitud dirigida al administrador. |
| Conversación hacia administración | `POST /conversation-requests` como asociado | 400 | Rechaza el contacto por esta vía. |
| Conexión desde administración | `POST /relations` como administrador | 403 | Impide que administración participe en networking. |
| Conversación desde administración | `POST /conversation-requests` como administrador | 403 | Aplica la misma restricción a conversaciones. |

Un 400, 401 o 403 esperado es una comprobación aprobada. La colección atraviesa autenticación HTTP, REST, servicios del plugin y la base del WordPress de prueba. La selección completa suma **81 peticiones y 149 comprobaciones**.

<a id="e2e-flujos-completos-de-negocio"></a>

## E2E: los seis flujos compartidos

| Flujo compartido | Bruno (peticiones / comprobaciones) | Playwright |
|---|---:|---|
| Perfil del asociado | 7 / 14 | Mismo flujo desde la interfaz |
| Conexión, chat y bloqueo | 12 / 24 | Mismo flujo desde la interfaz |
| Borrador, publicación e inscripción | 12 / 22 | Mismo flujo desde la interfaz |
| Aforo, lista de espera y notificación | 19 / 37 | Mismo flujo desde la interfaz |
| Suspensión y reactivación | 9 / 18 | Mismo flujo desde la interfaz |
| Consulta al asistente IA | 4 / 8 | Mismo flujo desde la interfaz |


Los seis flujos reúnen **63 peticiones y 123 comprobaciones**. Ana, Bruno y Carla son cuentas dedicadas; Administrador realiza las operaciones restringidas.

Los IDs de referencia proceden de los Word de análisis y plan de pruebas. La [trazabilidad detallada](../trazabilidad.json) distingue escenarios cubiertos, parciales y no incluidos; las relaciones siguientes no acreditan un requisito completo por sí solas.

### 1. Perfil del asociado

Carla inicia sesión → se consultan Empresa y biografía originales → se guarda una biografía válida → se recarga y comprueba persistencia → Empresa vacía se rechaza → se conservan los datos válidos → se restaura la biografía original. Son **7 peticiones/14 comprobaciones**, equivalentes al recorrido Perfil de Playwright.

### 2. Conexión, mensaje y bloqueo

**Plan:** CP-024, CP-025, CP-032, CP-033, CP-035 y CP-036. **Análisis:** RF-017, RF-018, RF-020, RF-026, RF-029, RF-031, RF-034 y RF-035; RN-007, RN-009 y RN-010.

Login de Ana y Bruno → Ana solicita conexión → Bruno acepta → Ana verifica la conexión → Abre conversación → Envía mensaje → Bruno lee ese mensaje → Bruno bloquea a Ana → Envío rechazado con **404** → Bruno desbloquea → La conexión no se restaura y Ana no puede escribir.

**Utilidad:** protege el consentimiento entre asociados y comprueba que el bloqueo se aplica en el backend. Desbloquear no autoriza automáticamente el contacto.

**Dependencias reales:** `/relations` devuelve `solicitudBloqueoId`, usado en `/connections/{id}/respond`; `/conversations` devuelve `conversacionBloqueoId`, usado para enviar y leer; el mensaje devuelve `mensajeBloqueoId`, que se busca junto con el texto exacto.

### 3. Borrador, publicación e inscripción

Administrador guarda un borrador sin descripción ni fechas → el Asociado no puede abrirlo → publicar incompleto se rechaza y conserva el borrador → se publica con título, descripción, fechas y cupo válidos → se reabre y comprueban fechas → Ana se inscribe y confirma persistencia → se cancela el evento propio. Son **12 peticiones/22 comprobaciones**, con los mismos pasos de negocio del tercer recorrido Playwright y una comprobación API adicional de privacidad.

### 4. Evento con cupo y lista de espera

**Plan:** CP-047, CP-049, CP-051, CP-052, CP-053, CP-055, CP-056, CP-059 y CP-061. **Análisis:** RF-047, RF-049 y RF-055 a RF-062; RN-017, RN-018 y RN-020. Ambas herramientas abren la notificación, verifican su lectura y confirman la vacante (CP-057). No se espera la caducidad ni la transferencia al siguiente asociado: CP-058 continúa pendiente.

Login de los participantes → Administrador crea evento con capacidad de 1 → Ana ocupa el cupo → Bruno recibe **409** al intentar confirmar → Bruno entra en lista de espera → Ana cancela → Se ofrece el cupo a Bruno → Se abre la notificación de ese evento y se comprueba su lectura → Bruno confirma la vacante → se conserva un único inscrito → Administrador cancela el evento → Carla recibe **409** al intentar inscribirse.

**Utilidad:** impide superar el aforo, valida la reserva de una oferta y comprueba que un evento cancelado no acepta inscripciones.

**Dependencias reales:** `/content/event` devuelve `eventoCupoId`, utilizado en inscripciones, consulta y cancelación. Se compara el contador de avisos antes y después de liberar el cupo y se busca una notificación que corresponda a ese mismo ID.

### 5. Suspensión y reactivación

**Plan:** CP-005, CP-009 y CP-010, con cobertura parcial. **Análisis:** RF-088 a RF-091, RF-133 y RF-135; RN-023 y RN-036. No se consulta la auditoría ni se contrasta todo el historial de la cuenta.

Login de Carla y Administrador → Carla accede (**200**) → Administrador suspende a Carla → La sesión revocada recibe **403** → Administrador reactiva a Carla → La sesión anterior continúa inválida (**403**) → Carla inicia sesión otra vez → Recupera el acceso (**200**).

**Utilidad:** comprueba que una suspensión retira efectivamente el acceso y que reactivar una cuenta no revive sus sesiones revocadas.

**Dependencias reales:** `idCarla` identifica la misma cuenta en `/admin/member/{id}` y en las verificaciones de identidad. Cada sesión tiene su propia cookie y nonce. El rechazo actual es `rest_cookie_invalid_nonce`.

### 6. Consulta al asistente IA

**Plan:** CP-070 a CP-072, con cobertura parcial. **Análisis:** RF-166 a RF-168; RN-022 y RN-042. El servidor devolvió **Google Gemini · API real** en el trabajo completado. No se acredita OpenAI ni el aislamiento de toda información no autorizada.

Login de Ana → Pregunta en un hilo nuevo → Obtiene el ID del trabajo → Consulta periódicamente su estado → Trabajo `completed` → Verifica respuesta → Consulta el hilo → Comprueba la pregunta y respuesta del mismo trabajo.

**Utilidad:** comprueba el procesamiento asíncrono, la finalización del trabajo y la conservación del contexto de la conversación.

**Dependencias reales:** `/ask` devuelve `trabajoAsistenteId`, usado en `/jobs/{id}`. `hiloAsistente` identifica el hilo que debe contener ese trabajo. La espera dura como máximo 120 segundos y falla ante error o vencimiento. En el lote actual se procesa la cola y se conserva la respuesta del proveedor real Gemini. Se verifica finalización y persistencia de pregunta/respuesta/hilo, sin evaluar exhaustivamente fundamentación ni calidad semántica.

## Regresión PHP adicional del 21 de octubre

La [regresión de borradores](../../docs/evidence/release-1.10.9/drafts-ui109.json) aprobó 76 pruebas PHP dirigidas y 859 aserciones en un WordPress local temporal. Comprueba guardado incompleto en siete tipos de contenido, permisos, validaciones al publicar, eventos propios sin fecha y borradores de foros/temas en una misma vista. Incluye casos de [DraftWorkflowTest.php](../../tests/php/DraftWorkflowTest.php) y regresiones existentes. Son pruebas PHPUnit: no son nuevas peticiones Bruno ni una nueva ejecución de las 149 comprobaciones de esta colección. La revisión no recoge cobertura ni acredita instalación remota.

La [seguridad de la API](../seguridad/backend-zap.html) tiene un análisis activo aislado de 18 operaciones GET, con la fecha y el resultado indicados en su reporte. La [revisión remota](../seguridad/qa-publicado.json) comprueba además permisos, sesiones y cabeceras. Ninguna reemplaza esta colección.

## Ejecución y evidencia

La ejecución publicada del **22 de octubre de 2026** corresponde al **QA compartido https://wordpress.ingsoftware.lat**. Los siete grupos (integración y seis flujos compartidos) aprobaron y la limpieza terminó correctamente. `resultados/` contiene únicamente ese lote. Sus metadatos incluyen la comparación de JavaScript y CSS con el paquete actual; los PHP privados no se descargan. La [regresión reciente](../actualizacion/index.html) aporta la evidencia de interfaz de los últimos cambios administrativos y de borradores.

Para repetir sobre una fixture local preparada:

```bash
node scripts/run-publishable-backend.cjs --fixture test-results/playwright-usuarios-local.json
```

El ejecutor trabaja sobre una copia temporal: no sustituye las variables compartidas por IDs locales. Las cuentas locales son sintéticas y su acceso privado se elimina junto con el entorno al terminar la revisión.

Desde la raíz del repositorio:

```bash
node scripts/run-publishable-backend.cjs
```

Se requiere el archivo privado de acceso descrito en el [README general](../README.md#variables-y-accesos). Bruno CLI se resuelve desde `test-results/bruno-tools/`; para otra instalación, `--cli` debe apuntar a su archivo JavaScript de entrada. El ejecutor prepara únicamente las cuentas dedicadas y al terminar intenta reactivar a Carla, retirar el bloqueo y cancelar el evento creado.

Los reportes nativos y sus fuentes están en `resultados/`. Solo se publica la última ejecución, con sus estados y errores originales. Al generar otra entrega se retiran de esta carpeta los reportes anteriores.

Consulta `procedencia.json` y `manifest.json` en la entrega para revisar fechas, archivos originales y huellas. El commit del ejecutor no identifica por sí solo el ZIP del plugin instalado en el servidor.
