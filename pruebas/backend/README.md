# Backend: integración y E2E con Bruno

Abre el [Bruno Run Dashboard](dashboard.html) para revisar resultados, peticiones, comprobaciones y reportes HTML/JSON/JUnit. La [colección ZIP](coleccion-bruno.zip) y la [carpeta importable](coleccion-bruno) contienen los mismos archivos.

## Importar la colección

1. Descomprime `coleccion-bruno.zip`.
2. En Bruno, selecciona **Open Collection / Abrir colección** y elige la carpeta con `bruno.json`.
3. Abre **1. Integración API** o **2. Flujos principales (4)**.
4. Ejecuta una carpeta completa y en orden. Las respuestas proporcionan los IDs de las peticiones siguientes.

La URL de QA, los usuarios y los IDs están en **Configuración → Vars**. Para autenticar, el ejecutor del repositorio genera un entorno temporal a partir de `test-results/playwright-usuarios.json`. En Bruno de escritorio puedes usar un entorno privado con `claveAsociado`, `claveAna`, `claveBruno`, `claveCarla` y `claveAdministrador`; el asociado de integración es Ana y ambas claves deben corresponder a ella. Ese entorno no se publica. La sesión y el nonce de cada actor se obtienen automáticamente y se mantienen en memoria.

La colección se actualizó el **19 de octubre** para el acceso protegido del paquete 1.10.9 vigente: antes de cada login obtiene su formulario, conserva su cookie y envía su nonce. Los GET de preparación del pre-script no son peticiones adicionales definidas en la colección. Usa el plugin actualizado; no elimines esta protección para ejecutar las pruebas. Los reportes publicados conservan su ejecución del 16 de octubre y no certifican esta modificación posterior de la colección.

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
| Directorio paginado | `GET /profiles?page=1` | 200 | Comprueba tamaño de página y cálculo de páginas. |
| Rango de fechas inválido | `GET /content/event?after=2099-12-31&before=2020-01-01` | 400 | Rechaza una fecha inicial posterior a la final. |
| Login administrativo | `POST /wp-login.php` | 302 | Obtiene sesión y nonce propios del administrador. |
| Inicio administrativo | `GET /intranet/` como administrador | 302 a `/administracion/` | Abre el panel correspondiente al rol. |
| Exclusión del directorio | `GET /profiles`, recorriendo todas las páginas | 200 | Incluye a Ana y excluye al administrador. |
| Ficha administrativa oculta | `GET /profiles/{administrador}` como asociado | 404 | Evita ofrecer administración como contacto. |
| Conexión hacia administración | `POST /relations` como asociado | 400 | Rechaza una solicitud dirigida al administrador. |
| Conversación hacia administración | `POST /conversation-requests` como asociado | 400 | Rechaza el contacto por esta vía. |
| Conexión desde administración | `POST /relations` como administrador | 403 | Impide que administración participe en networking. |
| Conversación desde administración | `POST /conversation-requests` como administrador | 403 | Aplica la misma restricción a conversaciones. |

Un 400, 401 o 403 esperado es una comprobación aprobada. La colección atraviesa autenticación HTTP, REST, servicios del plugin y la base del WordPress de prueba. La selección completa suma **58 peticiones y 105 comprobaciones**.

## E2E: flujos completos de negocio

Los cuatro flujos reúnen **40 peticiones y 79 comprobaciones**. Ana, Bruno y Carla son cuentas dedicadas; Administrador realiza las operaciones restringidas.

Los IDs de referencia proceden de los Word de análisis y plan de pruebas. La [trazabilidad detallada](../trazabilidad.json) distingue escenarios cubiertos, parciales y no incluidos; las relaciones siguientes no acreditan un requisito completo por sí solas.

### 1. Conexión, mensaje y bloqueo

**Plan:** CP-024, CP-025, CP-032, CP-033, CP-035 y CP-036. **Análisis:** RF-017, RF-018, RF-020, RF-026, RF-029, RF-031, RF-034 y RF-035; RN-007, RN-009 y RN-010.

Login de Ana y Bruno → Ana solicita conexión → Bruno acepta → Ana verifica la conexión → Abre conversación → Envía mensaje → Bruno lee ese mensaje → Bruno bloquea a Ana → Envío rechazado con **404** → Bruno desbloquea → La conexión no se restaura y Ana no puede escribir.

**Utilidad:** protege el consentimiento entre asociados y comprueba que el bloqueo se aplica en el backend. Desbloquear no autoriza automáticamente el contacto.

**Dependencias reales:** `/relations` devuelve `solicitudBloqueoId`, usado en `/connections/{id}/respond`; `/conversations` devuelve `conversacionBloqueoId`, usado para enviar y leer; el mensaje devuelve `mensajeBloqueoId`, que se busca junto con el texto exacto.

### 2. Evento con cupo y lista de espera

**Plan:** CP-047, CP-049, CP-051, CP-052, CP-053, CP-055, CP-056, CP-059 y CP-061. **Análisis:** RF-047, RF-049 y RF-055 a RF-062; RN-017, RN-018 y RN-020. Esta colección no acepta la oferta (CP-057) ni espera su caducidad (CP-058). La aceptación de la vacante se comprueba en el [cuarto flujo de Playwright](../frontend/README.md); CP-058 continúa pendiente.

Login de los participantes → Administrador crea evento con capacidad de 1 → Ana ocupa el cupo → Bruno recibe **409** al intentar confirmar → Bruno entra en lista de espera → Ana cancela → Se ofrece el cupo a Bruno → Se comprueba la notificación de ese evento → Administrador cancela el evento → Carla recibe **409** al intentar inscribirse.

**Utilidad:** impide superar el aforo, valida la reserva de una oferta y comprueba que un evento cancelado no acepta inscripciones.

**Dependencias reales:** `/content/event` devuelve `eventoCupoId`, utilizado en inscripciones, consulta y cancelación. Se compara el contador de avisos antes y después de liberar el cupo y se busca una notificación que corresponda a ese mismo ID.

### 3. Suspensión y reactivación

**Plan:** CP-005, CP-009 y CP-010, con cobertura parcial. **Análisis:** RF-088 a RF-091, RF-133 y RF-135; RN-023 y RN-036. No se consulta la auditoría ni se contrasta todo el historial de la cuenta.

Login de Carla y Administrador → Carla accede (**200**) → Administrador suspende a Carla → La sesión revocada recibe **403** → Administrador reactiva a Carla → La sesión anterior continúa inválida (**403**) → Carla inicia sesión otra vez → Recupera el acceso (**200**).

**Utilidad:** comprueba que una suspensión retira efectivamente el acceso y que reactivar una cuenta no revive sus sesiones revocadas.

**Dependencias reales:** `idCarla` identifica la misma cuenta en `/admin/member/{id}` y en las verificaciones de identidad. Cada sesión tiene su propia cookie y nonce. El rechazo actual es `rest_cookie_invalid_nonce`.

### 4. Consulta al asistente IA

**Plan:** CP-070 a CP-072, con cobertura parcial. **Análisis:** RF-166 a RF-168; RN-022 y RN-042. La ejecución actual usa el proveedor mock local. No se acredita OpenAI ni el aislamiento de información no autorizada.

Login de Ana → Pregunta en un hilo nuevo → Obtiene el ID del trabajo → Consulta periódicamente su estado → Trabajo `completed` → Verifica respuesta → Consulta el hilo → Comprueba la pregunta y respuesta del mismo trabajo.

**Utilidad:** comprueba el procesamiento asíncrono, la finalización del trabajo y la conservación del contexto de la conversación.

**Dependencias reales:** `/ask` devuelve `trabajoAsistenteId`, usado en `/jobs/{id}`. `hiloAsistente` identifica el hilo que debe contener ese trabajo. La espera dura como máximo 120 segundos y falla ante error o vencimiento. El modo de IA se conserva según lo devuelto por el servidor. En el lote actual se procesa la cola real con respuestas del proveedor mock local; no acredita una API externa.

## Regresión PHP adicional del 21 de octubre

La [regresión de borradores](../../docs/evidence/release-1.10.9/drafts-ui109.json) aprobó 76 pruebas PHP dirigidas y 859 aserciones en un WordPress local temporal. Comprueba guardado incompleto en siete tipos de contenido, permisos, validaciones al publicar, eventos propios sin fecha y borradores de foros/temas en una misma vista. Incluye casos de [DraftWorkflowTest.php](../../tests/php/DraftWorkflowTest.php) y regresiones existentes. Son pruebas PHPUnit: no son nuevas peticiones Bruno ni una nueva ejecución de las 105 comprobaciones de esta colección. La revisión no recoge cobertura ni acredita instalación remota.

La [seguridad de la API](../seguridad/backend-zap.html) tiene su propio análisis local del 19 de octubre, limitado a 18 operaciones GET; tampoco reemplaza esta colección.

## Ejecución y evidencia

La ejecución publicada del **16 de octubre de 2026** corresponde al **WordPress local aislado con el código de esa fecha**, no a una instalación nueva en el QA publicado. Los cinco grupos aprobaron y la limpieza terminó correctamente. `resultados/` contiene únicamente el lote más reciente. El ZIP importable mantiene la URL y las identidades del QA compartido; para aprobar sus nuevas comprobaciones allí debe estar instalado el plugin actualizado.

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
