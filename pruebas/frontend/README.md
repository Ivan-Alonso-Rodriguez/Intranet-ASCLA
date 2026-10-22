# Frontend: E2E de interfaz con Playwright

El [dashboard de Playwright](dashboard.html) muestra únicamente la ejecución publicada más reciente de los seis flujos principales, con su resultado, videos, capturas y reportes. Los pasos principales usan clics, formularios y navegación reales; las llamadas REST auxiliares solo preparan, verifican identidad y limpian datos propios.

El [ZAP local de páginas](../seguridad/frontend-zap.html), los [controles CSP y de navegador](../seguridad/dashboard.html) y sus [fuentes de ejecución](../seguridad/fuentes.html) también forman parte de la entrega. La [guía de sustento](../seguridad/sustento.html) compara 20/20 controles HTTP/CSP locales con 19/20 en QA, sin mezclar estos controles de seguridad con los seis flujos funcionales de Playwright.

<a id="cinco-flujos-principales"></a>

## Los mismos seis flujos que Bruno

| Flujo compartido | Bruno (peticiones / comprobaciones) | Playwright |
|---|---:|---|
| Perfil del asociado | 7 / 14 | Mismo flujo desde la interfaz |
| Conexión, chat y bloqueo | 12 / 24 | Mismo flujo desde la interfaz |
| Borrador, publicación e inscripción | 12 / 22 | Mismo flujo desde la interfaz |
| Aforo, lista de espera y notificación | 19 / 37 | Mismo flujo desde la interfaz |
| Suspensión y reactivación | 9 / 18 | Mismo flujo desde la interfaz |
| Consulta al asistente IA | 4 / 8 | Mismo flujo desde la interfaz |


### 1. Perfil del asociado — Carla

**Plan:** CP-011 y CP-013, parcialmente. **Análisis:** RF-002, RF-004; RN-001 y RN-031. Se prueban biografía y Empresa, no todos los campos del perfil.

Login → Editar biografía → Guardar perfil → Recargar → Comprobar persistencia → Intentar guardar Empresa vacía → Verificar la validación → Recargar y comprobar que se conservan los datos válidos.

**Utilidad:** comprueba que el formulario guarda correctamente y que un intento inválido no destruye información anterior.

### 2. Conexión, chat y bloqueo — Ana y Bruno

**Plan:** CP-024, CP-025, CP-032, CP-033, CP-035 y una comprobación parcial de CP-038. **Análisis:** RF-017, RF-018, RF-020, RF-029, RF-034 y RF-035.

Login de ambos → Ana solicita conexión → Bruno abre el mensaje de presentación y acepta → Ana abre chat y envía mensaje → Bruno lee ese texto → Bruno bloquea a Ana → La interfaz impide iniciar el chat → Bruno desbloquea → La conexión no se restaura.

**Utilidad:** comprueba el consentimiento y la mensajería desde los controles del usuario. El cierre con bloqueo/desbloqueo permite repetir con estas cuentas sin activar la espera de 30 días propia de eliminar una conexión.

### 3. Evento e inscripción — Administrador y Ana

**Plan:** CP-047, CP-048, CP-049 y CP-051. CP-047 y CP-048 tienen cobertura parcial. **Análisis:** RF-047, RF-048, RF-049 y RF-055; RN-016, RN-017 y RN-036.

Login → Verificar inicio administrativo, logo único y navegación superior → Comprobar que el administrador no aparece como contacto ni dispone de acciones sociales → Crear borrador incompleto → Reabrir → Validar campos obligatorios → Publicar con fecha, hora y cupo → Reabrir y comprobar las fechas → Ana se inscribe → Recargar y comprobar su inscripción → Cancelar el evento de prueba.

**Utilidad:** verifica que la edición, la publicación y la inscripción funcionan desde la interfaz y que sus datos persisten.

### 4. Aforo, lista de espera y notificación — Administrador, Ana y Bruno

**Plan:** CP-047, CP-049, CP-051, CP-052, CP-053, CP-055, CP-056, CP-057, CP-059 y CP-061. **Análisis:** RF-047, RF-049, RF-055 a RF-062, RF-069 y RF-071; RN-017, RN-018 y RN-020.

Administrador publica un evento con un cupo → Ana se inscribe → Bruno ve que solo puede entrar en espera → Bruno obtiene el turno uno → Ana cancela → Bruno recibe la oferta → Abre la notificación del evento → Se comprueba que quedó leída y conduce al evento correcto → Bruno confirma la vacante → Se conserva un único inscrito → Administrador cancela el evento → La interfaz deja de permitir inscripciones.

**Utilidad:** evita sobrepasar el aforo y completa el recorrido de la vacante ofrecida, incluida su notificación. La inscripción nunca se hace automáticamente. Este escenario usa una persona en espera; la prioridad entre varias personas y la caducidad de la oferta requieren otros casos.

### 5. Suspensión y reactivación — Administrador y Carla

**Plan:** CP-005, CP-009 y CP-010, con los límites indicados en la [trazabilidad](../trazabilidad.json). **Análisis:** RF-088 a RF-091 y RF-133; RN-023 y RN-036.

Carla accede y se consulta su biografía → Administrador busca su cuenta y confirma la suspensión → La sesión de Carla pierde acceso → Carla intenta iniciar sesión nuevamente y recibe el rechazo de membresía suspendida → Administrador confirma la reactivación → Carla inicia sesión de nuevo → Recupera el perfil y conserva su biografía.

**Utilidad:** comprueba el efecto real de una suspensión sobre la sesión y los nuevos accesos, y que la reactivación devuelve el acceso sin borrar el dato de perfil observado. No acredita la auditoría ni la conservación de todos los datos históricos. El ejecutor intenta reactivar a Carla incluso si una comprobación falla.

### 6. Consulta al asistente IA — Ana

Ana inicia sesión → abre una conversación nueva → envía la misma pregunta que Bruno → observa el trabajo pendiente con ID real → la interfaz espera la respuesta del proveedor → se comprueba texto y modo → se recarga y conserva el mismo hilo y respuesta. Las llamadas HTTP solo se observan para identificar el trabajo; la pregunta se envía con el formulario real. El modo observado se registra en el resumen de la ejecución.

## Regresión reciente del 22 de octubre en el QA publicado

La [revisión de los cambios recientes](../actualizacion/index.html) aprobó **14 controles**, con **16 videos y 16 capturas**: roles administrativos y sus excepciones; filtros de Usuarios e intereses; Directorio de 4 × 3 y sus botones; tipo de asociado en la cabecera; Foros en lista única; portada y cabecera de Galería; biblioteca integrada; menú y ciclo de Aliados; y borradores recuperables en Hub, Foros, Galería, Centro de Conocimiento, Eventos y Aliados. Los nueve contenidos ficticios propios se retiraron a la papelera. Los perfiles y contenidos ajenos no se modificaron en esta regresión.

La revisión de seguridad autenticada añade cuatro videos y cuatro capturas, con seis controles en 42 vistas de Administrador, Ejecutivo, Moderador y Asociado; no registra errores JavaScript ni infracciones CSP. Los controles HTTP señalan por separado la versión de Apache expuesta por el hosting. Estas selecciones adicionales no se cuentan como nuevos CP únicos ni se suman a los seis flujos principales como si fueran la misma suite.

## Antecedente local de borradores, foros y galerías

El [resumen del 21 de octubre](../../docs/evidence/release-1.10.9/drafts-ui109.json) registra diez controles de navegador de [draft-workflow.cjs](../../tests/draft-workflow.cjs): borradores incompletos recuperables en Hub, Foros, Galería, recursos, Eventos y Aliados; validación al publicar y exclusión de Comunidad; eventos sin fecha en Mis publicaciones; tarjetas de foros en escritorio/móvil; portadas de Galería con clic y teclado. La salida no registra errores JavaScript.

Esta regresión local conserva su fecha, sus fuentes y sus cifras. La [guía de repetición](../../MANUAL_QA_UAT.md#regresion-borradores) describe su fixture. La validación remota actual, enlazada arriba, comprueba los cambios posteriores; Foros utiliza finalmente una sola lista con sus publicaciones.

## Comprobaciones del administrador

En los accesos de los seis flujos se verifica el inicio según el rol y un único logo visible. Las sesiones administrativas abren `/administracion/`, tienen una sola entrada en el menú y muestran las secciones encima del panel. El flujo de publicación también comprueba la redirección desde `/intranet/`, la exclusión del directorio y la ausencia de controles de conexión/chat. Estas comprobaciones forman parte de los seis recorridos, sin añadir un sexto flujo.

## Ejecutar con los usuarios preparados

Desde la raíz del repositorio en PowerShell:

```powershell
.\scripts\playwright-usuarios-prueba.ps1 -Visible
```

Prepara primero `test-results/playwright-usuarios.json` siguiendo el [apartado de accesos](../README.md#variables-y-accesos); ese archivo privado no se distribuye con Git. Necesitas Node.js, las dependencias del repositorio, Microsoft Edge y los binarios compatibles de Playwright. El [ejecutor](../../scripts/run-playwright-test-users.cjs) usa los [recorridos de interfaz](../../tests/acceptance-ui.cjs).

Para una fixture local explícita, usa `-Fixture test-results/playwright-usuarios-local.json`. Este archivo lo genera `tests/shared-test-users-fixture.php` por CLI en un WordPress local ya instalado; no se distribuyen sus contraseñas.

No ejecutes Bruno y Playwright a la vez con las mismas cuentas. Las cuentas se conservan; los eventos propios se cancelan, Carla termina activa y la pareja desbloqueada.

## Evidencias al aprobar y al fallar

Cada ejecución utiliza una carpeta distinta en `test-results/playwright-usuarios-FECHA/`. Por cada recorrido se guardan:

- Un resultado aprobado o fallido, duración y error cuando corresponda.
- Videos de las sesiones, finalizados al cerrar los contextos del navegador.
- Capturas identificadas con `passed` o `failed`.
- Reportes JSON y JUnit, copia de las fuentes y huellas SHA-256.
- Estado de limpieza separado del resultado de las pruebas.

La carpeta `aprobadas/` conserva el lote del **22 de octubre de 2026**, ejecutado en **https://wordpress.ingsoftware.lat**, con Ana, Bruno, Carla y Administrador: **6 recorridos aprobados, 12 videos y 12 capturas**. Se compara el JavaScript y el CSS del despliegue con el paquete local actual. [Abrir la última ejecución](dashboard.html).

De los seis flujos principales se publica solo el último lote seleccionado. Se coloca en `aprobadas/` o `fallidas/` según su resultado; al actualizar se retira la anterior junto con sus videos y reportes. Si la nueva ejecución falla, conserva su diagnóstico y evidencias. Los 14 controles recientes y la seguridad autenticada se consultan por separado en `actualizacion/`, con sus propios resultados y medios.

## Actualizar esta entrega

Tras una nueva ejecución, desde la raíz:

```bash
node scripts/build-qa-delivery.cjs
python3 scripts/verify-qa-delivery.py
```

El constructor publica un único lote en `aprobadas/` o `fallidas/` según su resultado y retira los anteriores de `pruebas/`. Los originales de cada ejecución permanecen en `test-results/`, fuera de la entrega.
