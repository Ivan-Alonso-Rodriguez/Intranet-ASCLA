# ASCLA Core — Intranet 2.0

Plugin WordPress portable para una comunidad profesional privada. Incluye perfiles, directorio, networking determinístico, Hub, foros, mensajería, eventos, círculos de conversación, recursos, asistente con fuentes, galerías, aliados, contacto y moderación.

Toda la funcionalidad propia está en `wp-content/plugins/ascla-core/`. Elementor es opcional. No se modifica WordPress Core ni se necesita un tema específico. El plugin conserva sus datos al desactivarse o desinstalarse.

## Acceso ASCLA

La versión 1.0.4 incorpora inicio de sesión y recuperación con logo, colores y textos de ASCLA, adaptados a móvil. Abre `/intranet/` para acceder: se conserva la sección solicitada después del login y el cierre de sesión vuelve al formulario. Se utiliza la autenticación nativa de WordPress; no se cambian las cuentas ni las contraseñas. [Cambios y validación del acceso](docs/LOGIN_UPDATE.md).

## Requisitos

- WordPress 6.6 o posterior; PHP 8.2 o posterior con `mbstring`, `openssl`, `fileinfo` y extensiones habituales de WordPress.
- MySQL 8 o MariaDB 10.6+ con tablas InnoDB, permisos para `CREATE/ALTER TABLE` y disponibilidad de `GET_LOCK`.
- HTTPS en staging y producción. El desarrollo incluido se publica únicamente en `127.0.0.1:8088`.
- Ninguna dependencia PHP/Node en producción. Python 3 genera el ZIP; Docker, PHPUnit y Playwright sólo se usan para desarrollar/probar.

## Generar e instalar

```text
python scripts/build.py
```

Genera `dist/ascla-core.zip` y su SHA-256. El ZIP contiene una única carpeta `ascla-core/`, con archivos de ejecución; no incluye secretos, pruebas, dependencias de desarrollo ni documentos de referencia.

En WordPress: **Plugins → Añadir plugin → Subir plugin → elegir ZIP → Instalar → Activar**. Se crean doce páginas faltantes, roles, permisos, taxonomías y tablas versionadas. El instalador no sobrescribe páginas ajenas ni duplica las páginas que ya creó.

Accede a **ASCLA → Configuración** para elegir modos y políticas. Crea asociados en **Usuarios → Añadir nuevo**, con rol **Asociado ASCLA** o **Moderador ASCLA**. Los asociados trabajan desde el frontend; no necesitan `wp-admin` para sus actividades.

## Desarrollo local

Copia `.env.example` a `.env` y asigna valores aleatorios distintos de al menos 12 caracteres. Nunca versiones `.env`.

```text
docker compose up -d
docker compose run --rm cli sh -c 'wp core install --url=http://localhost:8088 --title="ASCLA Comunidad" --admin_user=ascla.admin --admin_email=admin@example.invalid --admin_password="$ASCLA_ADMIN_PASSWORD" --skip-email'
docker compose run --rm cli wp eval-file /opt/ascla-scripts/install-local.php
docker compose run --rm cli wp ascla seed
```

La sintaxis de comillas del primer comando de instalación es compatible con PowerShell y shells POSIX. No reemplaces la variable por una contraseña dentro de un archivo versionado. Para un sitio ya instalado omite `wp core install`. Abre `http://localhost:8088/intranet/`. Usuario demo: `demo.asociado`; contraseña: valor local de `ASCLA_DEMO_PASSWORD`. Administrador local: `ascla.admin`; contraseña: `ASCLA_ADMIN_PASSWORD`.

También puedes crear la demo desde **ASCLA → Configuración → Preparar datos de demostración**, introduciendo una contraseña. Se generan 18 asociados y 9 empresas ficticias. Las siguientes ejecuciones conservan ediciones y contraseñas existentes. No hay borrado automático de datos demo.

## Integraciones

- **IA:** por defecto `DEMO MODE`, extractivo y sin gasto. Para API real configura clave y un modelo habilitado en tu cuenta. Se usa OpenAI Responses con `store=false`; todo contenido multimedia queda en borrador. La arquitectura permite implementar otra `AIProviderInterface`.
- **YouTube:** introduce una URL válida en el recurso. Los videos se embeben; no se descargan ni almacenan completos. Puedes suministrar transcripción autorizada o conectar OAuth con permisos sobre los subtítulos. Sin permisos, se informa el error; en modo mock se identifica explícitamente la transcripción ficticia.
- **Google Calendar:** los enlaces e ICS no necesitan credenciales. Configura Client ID/Secret y URI de redirección para OAuth; cada asociado conecta su calendario desde Perfil. Guardar, actualizar y quitar un evento requiere pulsar la acción correspondiente.
- **LinkedIn/X:** curaduría demo con filtrado de promoción comercial. Los adaptadores reales declaran explícitamente que requieren aprobación/implementación según los permisos oficiales obtenidos. No hay scraping ni publicación externa automática.

Los secretos se cifran con AES-256-GCM y una clave derivada de las salts de WordPress, en opciones sin autoload. Rotar las salts obliga a reconectar las integraciones. Opcionalmente, un administrador de infraestructura puede usar constantes `ASCLA_AI_KEY` y `ASCLA_GOOGLE_CLIENT_SECRET`; no es necesario editar PHP para la instalación normal.

## Calidad y operación

Las pruebas están en `tests/`; deben ejecutarse exclusivamente contra WordPress local desechable. Los resultados y límites se documentan en `docs/QUALITY_REPORT.md`. Las configuraciones de herramientas no sustituyen sus resultados medidos.

El entorno Docker incluye un servicio `cron` que ejecuta los eventos pendientes cada diez segundos. Se desactivan las actualizaciones automáticas sólo en los contenedores de pruebas para conservar una versión reproducible; mantén el sitio real actualizado mediante su procedimiento de operación.

Jobs: WordPress cron ejecuta `ascla_jobs` y la revisión diaria del mes. Con WP-CLI puede usarse `wp ascla jobs` o `wp cron event run --due-now`. Los errores se ven en **ASCLA → IA y trabajos** y admiten reintento. Una solicitud HTTP no ejecuta el procesamiento multimedia directamente.

Si faltan páginas, reactiva el plugin o ejecuta `wp ascla migrate`. Si aparecen respuestas antiguas o sesiones mezcladas, excluye todas las páginas ASCLA y `/wp-json/ascla/v1/` del caché y purga la caché. Si falla una integración, comprueba modo, permisos, expiración y conectividad; nunca pegues tokens en logs o capturas. Si la demo ya existe, cambiar el password del formulario no reinicia contraseñas: usa la recuperación de WordPress.

Resultados verificados: [informe de calidad](docs/QUALITY_REPORT.md) y [evidencias](docs/evidence/).

Documentación: `docs/IMPLEMENTATION_PLAN.md`, `docs/ARCHITECTURE.md`, `docs/DATA_MODEL.md`, `docs/INSTALL.md`, `docs/DEPLOY_SITEGROUND.md`, `docs/EXTERNAL_INTEGRATIONS.md`, `docs/SECURITY.md`, `docs/DEMO.md`.
