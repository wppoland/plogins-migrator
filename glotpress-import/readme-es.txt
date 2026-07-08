=== Plogins Migrator - Migration and Backup for WooCommerce ===
Contributors: motylanogha
Tags: backup, migration, clone, restore, wp-cli
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, clone and migrate your whole site to one file, then restore it here or on a new host. URLs and paths are fixed for you. Runs on your server.

== Description ==

Migrator packs your database and everything in `wp-content` into a single file you can download, keep as a backup, and restore, on the same site or on a brand-new install somewhere else. When you restore onto a different address, Migrator rewrites the old URLs and file paths to the new ones for you, so the site just works.

Everything happens on your own server. There is no account to create, no file size sold back to you, and nothing is ever sent to a third-party service. Because it is fully open, you can read exactly what it does: the source lives at https://github.com/wppoland/plogins-migrator, which is also where to file a bug or request a feature.

<strong>How it works</strong>

1. On the site you want to copy, create a backup. Migrator writes your database to a portable SQL dump and streams every file in `wp-content` into one archive next to it.
2. Download that archive (or, on a big site, build it from the command line, see below).
3. On the destination, the same site to roll back, or a fresh WordPress install to move to, restore the archive. Migrator imports the database, puts the files back, and rewrites the source site's web address and paths to this one.

The address rewrite is <strong>safe for serialized data</strong>: Migrator walks the actual data structures rather than doing a blind text replace, so the byte-length counts PHP stores inside serialized options and meta stay correct and nothing breaks.

<strong>A few things worth knowing</strong>

Backups are written to a protected folder (`wp-content/migrator-backups`) that denies direct web access, and the in-browser download is served only to logged-in administrators through an authenticated handler, the files are never exposed at a guessable URL. Each item inside an archive carries a checksum, so a truncated or corrupted backup is caught before it is ever restored over a live site.

Restoring <strong>overwrites</strong> the destination database and files, that is the point of a restore, so it asks for confirmation and is limited to administrators. Migrator never overwrites its own plugin folder during a restore, so it cannot pull the rug out from under itself mid-import.

For large sites where a browser request would time out, every job also runs from WP-CLI, which has no timeout:

`wp migrator export`
`wp migrator import path/to/backup.migrator`

<strong>What's included</strong>

* One-click backup of your database and all of `wp-content` into a single archive
* Restore to the same site, or migrate to a new host with automatic, serialization-safe URL and path rewriting
* Choose what to leave out: media, themes, plugins, cache, spam comments, post revisions, transients, WooCommerce sessions or Action Scheduler tables
* In-browser export with a progress bar and a direct download, resumable so large sites finish across multiple steps, plus drag-and-drop restore
* WP-CLI `export` and `import` commands for sites too large for the browser
* A safety snapshot of your database before every restore, rolled back automatically if anything fails
* Per-item checksums so a corrupt archive is detected, not restored
* Self-hosted: no account, no third-party service, nothing leaves your server

== Installation ==

1. Upload the plugin to `/wp-content/plugins/migrator`, or install it from Plugins → Add New.
2. Activate it. There are no required dependencies.
3. Open <strong>Migrator</strong> in the admin menu to create a backup, or use `wp migrator export` from the command line.

== Frequently Asked Questions ==

= Documentation and links =

* <strong>Documentation</strong> - https://plogins.com/es/plogins-migrator/docs/
* <strong>Plugin page</strong> - https://plogins.com/es/plogins-migrator/
* <strong>Source code</strong> - https://github.com/wppoland/plogins-migrator
* <strong>Bug reports and feature requests</strong> - https://github.com/wppoland/plogins-migrator/issues


= Does restoring delete what is already on the destination? =

Yes. A restore replaces the destination's database and files with the contents of the archive, that is what restoring a backup means. It is limited to administrators and asks for confirmation first. Always keep a separate backup of anything on the destination you want to keep.

= Will my links break when I move to a new domain? =

No. When you restore onto a different address, Migrator rewrites the old site URL and file paths to the new ones, including inside serialized data, so internal links and settings keep working.

= My site is large and the browser export stops. What do I do? =

Use WP-CLI, which has no request time limit: `wp migrator export` to build the archive and `wp migrator import <file>` to restore it.

= Does it send my data anywhere? =

No. Migrator runs entirely on your own server. It creates no account and contacts no external service. Your backups stay in `wp-content/migrator-backups` until you download or delete them.

= Where are my backups stored? =

In `wp-content/migrator-backups`, a folder protected from direct web access. Removing the plugin deletes that folder and its contents.


= Does this plugin work on WordPress Multisite? =

Sí. Este complemento es compatible con WordPress Multisite. Activarlo en red o activarlo en sitios individuales; Cada sitio mantiene su propia configuración y datos.

== Screenshots ==

1. La pantalla Migrator: cree una copia de seguridad con ajustes preestablecidos y opciones de exclusión, junto con la restauración y sus copias de seguridad guardadas.
2. El explorador de tamaño de archivos: escanea el contenido de wp y ve el tamaño de cada carpeta para que puedas omitir lo que no necesitas.

== Changelog ==

= 1.0.1 =
* Primera versión estable.

= 0.3.3 =
* Renombrado a Plogins Migrator para WooCommerce para obtener un nombre de complemento más distintivo.

= 0.3.2 =
* Filas de copia de seguridad guardadas más ordenadas: la fecha y el tamaño encabezan, el nombre largo del archivo es una sola línea silenciada que ya no se ajusta y la fila se redistribuye ordenadamente en pantallas estrechas.

= 0.3.1 =
* Tus copias de seguridad guardadas ahora aparecen dentro de la tarjeta "Restaurar una copia de seguridad", por lo que puedes restaurar una en su lugar sin tener que desplazarte más allá de ella.

= 0.3.0 =
* Nueva sección "Tus copias de seguridad": cada copia de seguridad almacenada en el sitio aparece con su fecha y tamaño, para que puedas descargarla, restaurarla o eliminarla con un solo clic sin salir de la pantalla.
* Restaurar directamente desde una copia de seguridad almacenada (las copias de seguridad gzip se descomprimen automáticamente). El archivo de copia de seguridad se conserva, no se consume.
* Se agregaron ajustes preestablecidos con un solo clic a la pantalla de respaldo: Sitio completo, Solo base de datos y Solo medios configuran las exclusiones correctas para ti.

= 0.2.0 =
* Se agregó un explorador de tamaño de archivo a la pantalla de respaldo: escanee el contenido de wp, vea el tamaño de cada carpeta y el recuento de archivos, y marque las carpetas o archivos grandes para dejarlos fuera de la copia de seguridad. Se basa en las exclusiones de rutas existentes.

= 0.1.0 =
*Primer lanzamiento.
* Copia de seguridad de un archivo de la base de datos (tablas, vistas, activadores y rutinas almacenadas) y todo el "wp-content".
* Restaurar al mismo sitio o migrar a un nuevo host con URL segura para serialización y reescritura de rutas.
* Copia de seguridad selectiva: omita medios, temas, complementos, caché, comentarios de spam, revisiones de publicaciones, transitorios, sesiones de WooCommerce o tablas del Programador de acciones.
* Exportación reanudable en el navegador con una barra de progreso y descarga directa, además de restauración con arrastrar y soltar.
* WP-CLI `exportar` e `importar` para sitios demasiado grandes para el navegador.
* La seguridad es lo primero: una instantánea de la base de datos previa a la importación con reversión automática si falla una restauración, sumas de verificación por elemento y rechazo de importación a través de un prefijo de tabla que no coincide.
