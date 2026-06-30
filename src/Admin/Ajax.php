<?php

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Contract\HasHooks;
use Migrator\Engine\Archive\Compressor;
use Migrator\Engine\Export\ExportOptions;
use Migrator\Engine\Export\ExportPipeline;
use Migrator\Engine\Import\Importer;
use Migrator\Support\Workspace;

defined('ABSPATH') || exit;

// Migrator streams large backup archives (often gigabytes) in chunks. WP_Filesystem
// reads and writes whole files into memory, which would exhaust it, so this file
// uses direct stream functions by necessity.
// phpcs:disable WordPress.WP.AlternativeFunctions

/**
 * AJAX endpoints driving the resumable browser export, plus an authenticated
 * download handler. Every endpoint verifies the nonce and the manage_options
 * capability before doing anything, and the download is confined to the
 * workspace so no arbitrary file can be read.
 */
final class Ajax implements HasHooks
{
    /** Holds an add-on's opaque post-process payload between export start and completion. */
    private const POSTPROCESS_OPTION = 'migrator_export_postprocess';

    /** Whether the current export should be gzip-compressed when it finishes. */
    private const COMPRESS_OPTION = 'migrator_export_compress';

    public function __construct(
        private ExportPipeline $export,
        private Workspace $workspace,
    ) {
    }

    public function registerHooks(): void
    {
        add_action('wp_ajax_migrator_export_start', [$this, 'exportStart']);
        add_action('wp_ajax_migrator_export_step', [$this, 'exportStep']);
        add_action('wp_ajax_migrator_download', [$this, 'download']);
        add_action('wp_ajax_migrator_import_upload', [$this, 'importUpload']);
        add_action('wp_ajax_migrator_import_run', [$this, 'importRun']);
        add_action('wp_ajax_migrator_scan_tree', [$this, 'scanTree']);
        add_action('wp_ajax_migrator_backups_list', [$this, 'backupsList']);
        add_action('wp_ajax_migrator_restore_backup', [$this, 'restoreBackup']);
        add_action('wp_ajax_migrator_delete_backup', [$this, 'deleteBackup']);
    }

    /**
     * List the backups stored locally in the workspace, newest first, with a
     * one-click download URL each. Skips in-progress uploads and temp files.
     */
    public function backupsList(): void
    {
        $this->guard();

        $dir   = $this->workspace->path();
        $found = array_merge(
            glob($dir . '/*.migrator') ?: [],
            glob($dir . '/*.migrator' . Compressor::EXT) ?: [],
        );

        $out = [];
        foreach ($found as $path) {
            $name = basename($path);
            if (str_starts_with($name, 'upload-') || str_starts_with($name, 'restore-')) {
                continue;
            }
            $out[] = [
                'file'        => $name,
                'size'        => (int) filesize($path),
                'date'        => gmdate('Y-m-d H:i', (int) filemtime($path)),
                'compressed'  => str_ends_with($name, Compressor::EXT),
                'downloadUrl' => wp_nonce_url(
                    admin_url('admin-ajax.php?action=migrator_download&file=' . rawurlencode($name)),
                    'migrator_download',
                    'nonce',
                ),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        wp_send_json_success(['backups' => $out]);
    }

    /**
     * Restore a backup already stored in the workspace (decompressing a .gz copy
     * to a temp archive first). The stored backup itself is kept.
     */
    public function restoreBackup(): void
    {
        $this->guard();

        global $wpdb;

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $name  = isset($_POST['file']) ? sanitize_file_name(wp_unslash((string) $_POST['file'])) : '';
        $files = isset($_POST['import_files']) && '' !== sanitize_text_field(wp_unslash((string) $_POST['import_files']));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $path  = $this->confinedBackup($name);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $importPath = $path;
        $tmp        = null;
        if (Compressor::isCompressed($path)) {
            $tmp = $this->workspace->path('restore-' . wp_generate_password(8, false) . '.migrator');
            (new Compressor())->decompress($path, $tmp);
            $importPath = $tmp;
        }

        try {
            $importer = new Importer($this->workspace, $wpdb);
            $result   = $importer->import($importPath, $files);
            if (null !== $tmp) {
                wp_delete_file($tmp);
            }
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            if (null !== $tmp) {
                wp_delete_file($tmp);
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Delete a stored backup.
     */
    public function deleteBackup(): void
    {
        $this->guard();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $name = isset($_POST['file']) ? sanitize_file_name(wp_unslash((string) $_POST['file'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $path = $this->confinedBackup($name);

        wp_delete_file($path);
        wp_send_json_success(['file' => $name]);
    }

    /**
     * Resolve a backup filename to an absolute path, confined to the workspace,
     * or send a 404 and stop.
     */
    private function confinedBackup(string $name): string
    {
        $path     = $this->workspace->path($name);
        $realBase = realpath($this->workspace->path());
        $realPath = realpath($path);
        if ('' === $name || false === $realPath || false === $realBase || ! str_starts_with($realPath, $realBase) || ! is_file($realPath)) {
            wp_send_json_error(['message' => __('Backup not found.', 'plogins-migrator')], 404);
        }

        return $realPath;
    }

    /**
     * Return a size-annotated tree of wp-content so the merchant can see what
     * bloats a backup and tick folders or large files to exclude. Paths are
     * wp-content-relative, matching the exporter's exclude_paths option.
     */
    public function scanTree(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'plogins-migrator')], 403);
        }

        $base = untrailingslashit((string) WP_CONTENT_DIR);
        $tree = (new \Migrator\Engine\Files\TreeScanner())->tree($base, [$this->workspace->path()]);

        wp_send_json_success($tree);
    }

    /**
     * Receive one chunk of an uploaded archive and append it to a workspace file.
     * Chunking sidesteps the host's upload_max_filesize on large backups.
     */
    public function importUpload(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'plogins-migrator')], 403);
        }

        $id    = isset($_POST['upload_id']) ? sanitize_key(wp_unslash((string) $_POST['upload_id'])) : '';
        $index = isset($_POST['index']) ? absint(wp_unslash((string) $_POST['index'])) : 0;

        if ('' === $id || ! isset($_FILES['chunk']) || ! is_array($_FILES['chunk'])) {
            wp_send_json_error(['message' => __('Bad upload request.', 'plogins-migrator')], 400);
        }

        $tmp = isset($_FILES['chunk']['tmp_name']) ? sanitize_text_field(wp_unslash((string) $_FILES['chunk']['tmp_name'])) : '';
        if ('' === $tmp || ! is_uploaded_file($tmp)) {
            wp_send_json_error(['message' => __('Invalid upload.', 'plogins-migrator')], 400);
        }

        $dest = $this->uploadPath($id);

        $in  = fopen($tmp, 'rb');
        $out = fopen($dest, 0 === $index ? 'wb' : 'ab');
        if (false === $in || false === $out) {
            wp_send_json_error(['message' => __('Could not store upload.', 'plogins-migrator')], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        wp_send_json_success(['index' => $index]);
    }

    /**
     * Restore a fully-uploaded archive. Runs the hardened importer, which takes a
     * safety backup and rolls back on failure.
     */
    public function importRun(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'plogins-migrator')], 403);
        }

        global $wpdb;

        $id    = isset($_POST['upload_id']) ? sanitize_key(wp_unslash((string) $_POST['upload_id'])) : '';
        $files = isset($_POST['import_files']) && '' !== sanitize_text_field(wp_unslash((string) $_POST['import_files']));
        $path = $this->uploadPath($id);

        if ('' === $id || ! is_file($path)) {
            wp_send_json_error(['message' => __('Uploaded archive not found.', 'plogins-migrator')], 404);
        }

        // Give the restore as long as the host allows; large sites should use WP-CLI.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $importer = new Importer($this->workspace, $wpdb);
        try {
            $result = $importer->import($path, $files);
            wp_delete_file($path);
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_delete_file($path);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Resolve (and confine to the workspace) the temp file for an upload id.
     */
    private function uploadPath(string $id): string
    {
        $path     = $this->workspace->path('upload-' . $id . '.migrator');
        $realBase = realpath($this->workspace->path());
        $realDir  = realpath(dirname($path));
        if (false === $realBase || false === $realDir || ! str_starts_with($realDir . '/', $realBase . '/')) {
            wp_send_json_error(['message' => __('Invalid upload id.', 'plogins-migrator')], 400);
        }

        return $path;
    }

    public function exportStart(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'plogins-migrator')], 403);
        }

        try {
            $this->export->clear();
            $job = $this->export->start($this->readExportOptions());

            // Let an add-on register post-processing for the finished archive
            // (e.g. encryption). The returned payload is opaque to core and is
            // handed back on the migrator/export_complete action.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
            $postprocess = apply_filters('migrator/postprocess_request', [], wp_unslash($_POST));
            if (is_array($postprocess) && [] !== $postprocess) {
                update_option(self::POSTPROCESS_OPTION, $postprocess, false);
            } else {
                delete_option(self::POSTPROCESS_OPTION);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
            update_option(self::COMPRESS_OPTION, ! empty($_POST['compress']), false);

            wp_send_json_success($this->shape($job));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Read the export exclusion checkboxes from the request.
     */
    private function readExportOptions(): ExportOptions
    {
        // Nonce is verified by the caller (exportStart/exportStep) before this runs.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $flags = [];
        foreach (ExportOptions::keys() as $key) {
            $flags[$key] = isset($_POST['options'][$key])
                && '' !== sanitize_text_field(wp_unslash((string) $_POST['options'][$key]));
        }

        $tables = isset($_POST['options']['exclude_tables']) && is_array($_POST['options']['exclude_tables'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['options']['exclude_tables']))
            : [];
        $paths = isset($_POST['options']['exclude_paths']) && is_array($_POST['options']['exclude_paths'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['options']['exclude_paths']))
            : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $flags['exclude_tables'] = $tables;
        $flags['exclude_paths']  = $paths;

        return ExportOptions::fromArray($flags);
    }

    public function exportStep(): void
    {
        $this->guard();
        try {
            $job = $this->export->step();

            if ('done' === ($job['status'] ?? '')) {
                // Finalising (compress/encrypt) can take a while on a big archive.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
                }

                // Compress first so encryption (which randomises bytes) runs last.
                $job = $this->maybeCompress($job);

                /**
                 * Fires when a browser export has finished writing. Handlers may
                 * post-process the archive (e.g. encrypt it) and update the job's
                 * dest/bytes so the download serves the processed file.
                 *
                 * @param string               $dest        Absolute archive path.
                 * @param array<string, mixed> $postprocess Opaque payload from export start.
                 */
                do_action('migrator/export_complete', (string) ($job['dest'] ?? ''), (array) get_option(self::POSTPROCESS_OPTION, []));
                delete_option(self::POSTPROCESS_OPTION);
                $job = $this->export->current(); // Re-read: a handler may have changed dest/bytes.
            }

            wp_send_json_success($this->shape($job));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Gzip the finished archive when compression was requested, pointing the job
     * (and so the download) at the .gz. Never fails the export.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function maybeCompress(array $job): array
    {
        if (! get_option(self::COMPRESS_OPTION)) {
            return $job;
        }
        delete_option(self::COMPRESS_OPTION);

        $dest = (string) ($job['dest'] ?? '');
        if ('' === $dest || ! is_file($dest)) {
            return $job;
        }

        $gz = $dest . Compressor::EXT;
        try {
            (new Compressor())->compress($dest, $gz);
        } catch (\Throwable $e) {
            return $job; // Leave the plain archive rather than lose the backup.
        }
        wp_delete_file($dest);

        $job['dest']  = $gz;
        $job['bytes'] = (int) filesize($gz);
        update_option(ExportPipeline::JOB_OPTION, $job, false);

        return $job;
    }

    /**
     * Stream the finished archive as a download. Authenticated, capability-checked,
     * and restricted to a file inside the workspace.
     */
    public function download(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('migrator_download', 'nonce')) {
            wp_die(esc_html__('Not allowed.', 'plogins-migrator'), '', ['response' => 403]);
        }

        $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : '';
        $path = $this->workspace->path($name);

        // Confine strictly to the workspace directory.
        $realBase = realpath($this->workspace->path());
        $realPath = realpath($path);
        if ('' === $name || false === $realPath || false === $realBase || ! str_starts_with($realPath, $realBase) || ! is_file($realPath)) {
            wp_die(esc_html__('File not found.', 'plogins-migrator'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($realPath));

        $handle = fopen($realPath, 'rb');
        if (false !== $handle) {
            while (! feof($handle)) {
                echo fread($handle, 1_048_576); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    private function guard(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'plogins-migrator')], 403);
        }
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'plogins-migrator')], 403);
        }
    }

    /**
     * Shape a job for the client: progress + a download URL once finished.
     *
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function shape(array $job): array
    {
        $total   = max(1, (int) ($job['total'] ?? 0));
        $index   = (int) ($job['index'] ?? 0);
        $done    = 'done' === ($job['status'] ?? '');
        $percent = $done ? 100 : (int) floor($index / $total * 100);

        $shaped = [
            'status'  => (string) ($job['status'] ?? ''),
            'index'   => $index,
            'total'   => (int) ($job['total'] ?? 0),
            'percent' => $percent,
            'done'    => $done,
        ];

        if ($done) {
            $file              = basename((string) ($job['dest'] ?? ''));
            $shaped['bytes']   = (int) ($job['bytes'] ?? 0);
            $shaped['size']    = size_format((int) ($job['bytes'] ?? 0));
            $shaped['fileName'] = $file;
            $shaped['download'] = add_query_arg(
                [
                    'action' => 'migrator_download',
                    'file'   => rawurlencode($file),
                    'nonce'  => wp_create_nonce('migrator_download'),
                ],
                admin_url('admin-ajax.php')
            );
        }

        return $shaped;
    }
}
