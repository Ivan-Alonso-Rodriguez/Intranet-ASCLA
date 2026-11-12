# Diseño de la intranet ASCLA

## Referencias

- Base: ASCLA Core 1.11.1, última entrega de GitHub/UAT consultada el 6 de noviembre de 2026. Rama `origin/uat`, commit `0ff0d9c654c1dc0a494970df224708fc048ff9e4`. `origin/main` conserva una entrega anterior. Se mantienen el menú lateral oscuro, la cabecera y las superficies claras de esa base, junto con las mejoras locales ya existentes.
- Tipografía: el estilo calculado del [login ITBCP](https://ia-core-dev.itbcpwebservices.com/login) usa Geist. La fuente variable está incluida en `assets/fonts/geist/`, con su licencia SIL OFL del [repositorio oficial de Vercel](https://github.com/vercel/geist-font). No requiere Google Fonts ni otro servidor externo.
- Color: muestreo de los colores vectoriales de `Guias-de-la-comunidad-ASCLA-1.pdf`, contrastado visualmente con su portada y la página de Inicio. Azul oscuro `#063159`, celeste `#74BEFF`, gris `#F6F6F6`, blanco `#FFFFFF` y texto `#2B2B2B`. Las variantes de interacción y modo oscuro derivan de estos colores. El rojo de error `#C6302C` también aparece en la guía.
- Skill utilizada: [frontend-design de skills.sh](https://www.skills.sh/anthropics/skills/frontend-design), ya instalada en este entorno.

## Criterios

- Geist para títulos, texto y formularios; títulos con peso 600, texto de 14 px y etiquetas de 12–13 px.
- Contenido alineado a la izquierda, jerarquía clara y espacios amplios. Los paneles se adaptan al ancho disponible.
- Fondos planos sin degradados. Esquinas de 2–4 px en paneles, diálogos y controles; se conserva la forma circular de los indicadores de carga.
- Las sombras decorativas se eliminan. Los estados de foco mantienen su indicador visible.
- No se modifican rutas, permisos, eventos JavaScript, contratos de API, nombres de campos ni operaciones de los módulos.

## Archivos

`brand.css` centraliza fuente y paleta; `app.css` contiene los estilos base; `executive.css` aplica el aspecto compartido de los módulos y la administración; `login.css` adapta el acceso y la recuperación. `transparency-grid.svg` conserva la cuadrícula del editor de fotografías sin usar degradados CSS.

## Verificación local

La revisión visual usa WordPress local en `http://localhost:8088`, con los archivos del plugin montados desde este proyecto. Se comprobaron los doce módulos de asociados y las once secciones de administración en escritorio, además de ocho vistas de asociados y las estadísticas administrativas a 390 px. Sin errores visibles ni desbordamiento horizontal en las vistas comprobadas. Se verificaron los filtros desplegables, una ficha de asociado, la navegación móvil, la carga local de Geist y el cambio de apariencia claro/oscuro. Ninguna hoja de estilo del plugin contiene degradados.

Esta revisión comprueba presentación y navegación; no equivale a una nueva ejecución de todas las pruebas funcionales de QA/UAT ni a un despliegue remoto.
