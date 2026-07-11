=== Plogins Migrator - Site Migration and Backup ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Haz copias de seguridad, clona y migra todo tu sitio a un solo archivo y luego restáuralo aquí o en un nuevo alojamiento. Las URL y las rutas se corrigen por ti. Se ejecuta en tu servidor.

== Description ==

Migrator empaqueta tu base de datos y todo lo que hay en `wp-content` en un solo archivo que puedes descargar, conservar como copia de seguridad y restaurar, en el mismo sitio o en una instalación nueva en otro lugar. Cuando restauras en una dirección distinta, Migrator reescribe por ti las URL y las rutas de archivo antiguas por las nuevas, así que el sitio simplemente funciona.

Todo ocurre en tu propio servidor. No hay cuenta que crear, no te venden de vuelta espacio de archivo y nunca se envía nada a un servicio de terceros. Como el proyecto es totalmente abierto, puedes leer exactamente qué hace: el código fuente está en https://github.com/wppoland/plogins-migrator, donde también puedes informar de un error o pedir una función.

<strong>Cómo funciona</strong>

1. En el sitio que quieras copiar, crea una copia de seguridad. Migrator escribe tu base de datos en un volcado SQL portable y transmite cada archivo de `wp-content` a un archivo junto a él.
2. Descarga ese archivo (o, en un sitio grande, créalo desde la línea de comandos; ver más abajo).
3. En el destino, el mismo sitio para volver atrás o una instalación nueva de WordPress a la que te mudas, restaura el archivo. Migrator importa la base de datos, devuelve los archivos a su sitio y reescribe la dirección web y las rutas del sitio de origen por las de aquí.

La reescritura de la dirección es <strong>segura para datos serializados</strong>: Migrator recorre las estructuras de datos reales en lugar de hacer un reemplazo de texto a ciegas, así que los recuentos de longitud en bytes que PHP guarda dentro de opciones y meta serializadas siguen siendo correctos y nada se rompe.

<strong>Algunas cosas que conviene saber</strong>

Las copias de seguridad se escriben en una carpeta protegida (`wp-content/migrator-backups`) que deniega el acceso web directo, y la descarga en el navegador solo se sirve a administradores con sesión iniciada mediante un controlador autenticado: los archivos nunca quedan expuestos en una URL fácil de adivinar. Cada elemento dentro de un archivo lleva una suma de comprobación, así que una copia truncada o corrupta se detecta antes de restaurarla sobre un sitio en vivo.

Restaurar <strong>sobrescribe</strong> la base de datos y los archivos de destino; de eso se trata una restauración, por eso pide confirmación y está limitado a administradores. Migrator nunca sobrescribe su propia carpeta del plugin durante una restauración, así que no puede quitarse el suelo de debajo a mitad de la importación.

En sitios grandes donde una petición del navegador agotaría el tiempo, cada trabajo también se ejecuta desde WP-CLI, que no tiene límite de tiempo:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

<strong>Qué incluye</strong>

* Copia de seguridad con un clic de tu base de datos y de todo `wp-content` en un solo archivo
* Restauración en el mismo sitio o migración a un nuevo alojamiento con reescritura automática y segura para la serialización de URL y rutas
* Elige qué excluir: medios, temas, plugins, caché, comentarios spam, revisiones de entradas, transitorios, sesiones de WooCommerce o tablas de Action Scheduler
* Exportación en el navegador con barra de progreso y descarga directa, reanudable para que los sitios grandes terminen en varios pasos, además de restauración por arrastrar y soltar
* Comandos WP-CLI `export` e `import` para sitios demasiado grandes para el navegador
* Una instantánea de seguridad de tu base de datos antes de cada restauración, revertida automáticamente si algo falla
* Sumas de comprobación por elemento para que un archivo corrupto se detecte y no se restaure
* Autoalojado: sin cuenta, sin servicio de terceros, nada sale de tu servidor

== Installation ==

1. Sube el plugin a `/wp-content/plugins/migrator` o instálalo desde Plugins → Añadir nuevo.
2. Actívalo. No hay dependencias obligatorias.
3. Abre <strong>Migrator</strong> en el menú de administración para crear una copia de seguridad o usa `wp migrator export` desde la línea de comandos.

== Frequently Asked Questions ==

= Documentation and links =

* <strong>Documentación</strong> - https://plogins.com/es/plogins-migrator/docs/
* <strong>Página del plugin</strong> - https://plogins.com/es/plogins-migrator/
* <strong>Código fuente</strong> - https://github.com/wppoland/plogins-migrator
* <strong>Informes de errores y peticiones de funciones</strong> - https://github.com/wppoland/plogins-migrator/issues


= Does restoring delete what is already on the destination? =

Sí. Una restauración sustituye la base de datos y los archivos de destino por el contenido del archivo; eso es lo que significa restaurar una copia de seguridad. Está limitada a administradores y pide confirmación primero. Conserva siempre una copia de seguridad aparte de todo lo que quieras mantener en el destino.

= Will my links break when I move to a new domain? =

No. Cuando restauras en una dirección distinta, Migrator reescribe la URL antigua del sitio y las rutas de archivo por las nuevas, también dentro de datos serializados, así que los enlaces internos y los ajustes siguen funcionando.

= My site is large and the browser export stops. What do I do? =

Usa WP-CLI, que no tiene límite de tiempo de petición: `wp migrator export` para crear el archivo y `wp migrator import <file>` para restaurarlo.

= Does it send my data anywhere? =

No. Migrator se ejecuta por completo en tu propio servidor. No crea ninguna cuenta ni contacta con ningún servicio externo. Tus copias de seguridad permanecen en `wp-content/migrator-backups` hasta que las descargues o las elimines.

= Where are my backups stored? =

En `wp-content/migrator-backups`, una carpeta protegida contra el acceso web directo. Al eliminar el plugin se borra esa carpeta y su contenido.


= Does this plugin work on WordPress Multisite? =

Sí. Este plugin es compatible con WordPress Multisite. Actívalo para toda la red o en sitios concretos; cada sitio conserva sus propios ajustes y datos.

== Screenshots ==

1. La pantalla de Migrator: crea una copia de seguridad con ajustes preestablecidos y opciones de exclusión, junto a la restauración y tus copias guardadas.
2. El explorador de tamaño de archivos: escanea `wp-content` y ve el tamaño de cada carpeta para excluir lo que no necesites.

== Translations ==

Plogins Migrator incluye traducciones al polaco, al alemán y al español para la interfaz del plugin. El dominio de texto es `plogins-migrator`, por lo que los paquetes de idioma de WordPress.org también pueden sustituir o ampliar estas traducciones incluidas.

== Changelog ==

= 1.0.2 =
* Se añadieron traducciones incluidas al polaco, al alemán y al español para la interfaz del plugin.

= 1.0.1 =
* Primera versión estable.

= 0.3.3 =
* Renombrado a Plogins Migrator for WooCommerce para un nombre de plugin más distintivo.

= 0.3.2 =
* Filas de copias guardadas más ordenadas: la fecha y el tamaño van primero, el nombre largo del archivo es una sola línea atenuada que ya no se envuelve y la fila se reacomoda con limpieza en pantallas estrechas.

= 0.3.1 =
* Tus copias guardadas aparecen ahora dentro de la tarjeta «Restaurar una copia de seguridad», así que puedes restaurar una en su sitio sin desplazarte más allá.

= 0.3.0 =
* Nueva sección «Tus copias de seguridad»: cada copia almacenada en el sitio se lista con su fecha y tamaño, para que puedas descargarla, restaurarla o eliminarla con un clic sin salir de la pantalla.
* Restauración directa desde una copia guardada (las copias gzip se descomprimen automáticamente). El archivo de copia se conserva, no se consume.
* Ajustes preestablecidos con un clic en la pantalla de copia de seguridad: Sitio completo, Solo base de datos y Solo medios configuran las exclusiones correctas por ti.

= 0.2.0 =
* Explorador de tamaño de archivos en la pantalla de copia de seguridad: escanea `wp-content`, ve el tamaño y el recuento de archivos de cada carpeta y marca carpetas o archivos grandes para excluirlos de la copia. Se apoya en las exclusiones de ruta existentes.

= 0.1.0 =
* Primer lanzamiento.
* Copia de seguridad en un solo archivo de la base de datos (tablas, vistas, desencadenadores y rutinas almacenadas) y de todo `wp-content`.
* Restauración en el mismo sitio o migración a un nuevo alojamiento con reescritura segura para la serialización de URL y rutas.
* Copia de seguridad selectiva: excluye medios, temas, plugins, caché, comentarios spam, revisiones de entradas, transitorios, sesiones de WooCommerce o tablas de Action Scheduler.
* Exportación reanudable en el navegador con barra de progreso y descarga directa, además de restauración por arrastrar y soltar.
* Comandos WP-CLI `export` e `import` para sitios demasiado grandes para el navegador.
* Seguridad primero: instantánea de la base de datos antes de la importación con reversión automática si falla una restauración, sumas de comprobación por elemento y rechazo de importación con un prefijo de tabla que no coincide.
