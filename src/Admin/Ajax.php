<?php

declare(strict_types=1);

namespace Migrator\Admin;

use Migrator\Contract\HasHooks;
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
    }

    /**
     * Receive one chunk of an uploaded archive and append it to a workspace file.
     * Chunking sidesteps the host's upload_max_filesize on large backups.
     */
    public function importUpload(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'migrator')], 403);
        }

        $id    = isset($_POST['upload_id']) ? sanitize_key(wp_unslash((string) $_POST['upload_id'])) : '';
        $index = isset($_POST['index']) ? absint(wp_unslash((string) $_POST['index'])) : 0;

        if ('' === $id || ! isset($_FILES['chunk']) || ! is_array($_FILES['chunk'])) {
            wp_send_json_error(['message' => __('Bad upload request.', 'migrator')], 400);
        }

        $tmp = isset($_FILES['chunk']['tmp_name']) ? sanitize_text_field(wp_unslash((string) $_FILES['chunk']['tmp_name'])) : '';
        if ('' === $tmp || ! is_uploaded_file($tmp)) {
            wp_send_json_error(['message' => __('Invalid upload.', 'migrator')], 400);
        }

        $dest = $this->uploadPath($id);

        $in  = fopen($tmp, 'rb');
        $out = fopen($dest, 0 === $index ? 'wb' : 'ab');
        if (false === $in || false === $out) {
            wp_send_json_error(['message' => __('Could not store upload.', 'migrator')], 500);
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
            wp_send_json_error(['message' => __('Not allowed.', 'migrator')], 403);
        }

        global $wpdb;

        $id    = isset($_POST['upload_id']) ? sanitize_key(wp_unslash((string) $_POST['upload_id'])) : '';
        $files = isset($_POST['import_files']) && '' !== sanitize_text_field(wp_unslash((string) $_POST['import_files']));
        $path = $this->uploadPath($id);

        if ('' === $id || ! is_file($path)) {
            wp_send_json_error(['message' => __('Uploaded archive not found.', 'migrator')], 404);
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
            wp_send_json_error(['message' => __('Invalid upload id.', 'migrator')], 400);
        }

        return $path;
    }

    public function exportStart(): void
    {
        if (! check_ajax_referer('migrator', 'nonce', false) || ! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'migrator')], 403);
        }

        try {
            $this->export->clear();
            wp_send_json_success($this->shape($this->export->start($this->readExportOptions())));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Read the export exclusion checkboxes from the request.
     */
    private function readExportOptions(): ExportOptions
    {
        $flags = [];
        foreach (ExportOptions::keys() as $key) {
            $flags[$key] = isset($_POST['options'][$key]) // phpcs:ignore WordPress.Security.NonceVerification.Missing
                && '' !== sanitize_text_field(wp_unslash((string) $_POST['options'][$key])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        return ExportOptions::fromArray($flags);
    }

    public function exportStep(): void
    {
        $this->guard();
        try {
            wp_send_json_success($this->shape($this->export->step()));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Stream the finished archive as a download. Authenticated, capability-checked,
     * and restricted to a file inside the workspace.
     */
    public function download(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('migrator_download', 'nonce')) {
            wp_die(esc_html__('Not allowed.', 'migrator'), '', ['response' => 403]);
        }

        $name = isset($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : '';
        $path = $this->workspace->path($name);

        // Confine strictly to the workspace directory.
        $realBase = realpath($this->workspace->path());
        $realPath = realpath($path);
        if ('' === $name || false === $realPath || false === $realBase || ! str_starts_with($realPath, $realBase) || ! is_file($realPath)) {
            wp_die(esc_html__('File not found.', 'migrator'), '', ['response' => 404]);
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
            wp_send_json_error(['message' => __('Security check failed.', 'migrator')], 403);
        }
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'migrator')], 403);
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
