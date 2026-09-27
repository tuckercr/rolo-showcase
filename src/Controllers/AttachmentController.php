<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AttachmentModel;
use App\Services\AttachmentService;
use App\Support\View;

final class AttachmentController extends Controller
{
    public function index(): string
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $attachments = new AttachmentModel($this->db);

        $storageDir = AttachmentService::forApp()->dir();
        $freeBytes = disk_free_space(is_dir($storageDir) ? $storageDir : dirname(__DIR__, 2));

        return $this->render('attachments/index', [
            'pageTitle' => 'Files',
            'attachments' => $attachments->searchAll($query),
            'query' => $query,
            'totalBytes' => $attachments->totalBytes(),
            'freeBytes' => $freeBytes === false ? null : (int) $freeBytes,
        ]);
    }

    /**
     * Stream a stored attachment. Session auth is enforced by the front
     * controller (this route is not public); files are always sent as a
     * download so nothing ever renders in the app's origin.
     *
     * @param array<string, string> $vars route parameters
     */
    public function download(array $vars): string
    {
        $attachment = (new AttachmentModel($this->db))->find((int) $vars['id']);

        if ($attachment === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $path = AttachmentService::forApp()->path((string) $attachment['stored_name']);

        if (!is_file($path)) {
            http_response_code(404);
            return View::render('errors/404');
        }

        // Header-safe display name: strip CR/LF and quotes.
        $safeName = str_replace(["\r", "\n", '"'], '', (string) $attachment['original_name']);

        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . (string) $attachment['size_bytes']);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');

        readfile($path);

        return '';
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function delete(array $vars): string
    {
        $this->requireValidCsrf();

        $attachments = new AttachmentModel($this->db);
        $attachment = $attachments->find((int) $vars['id']);

        if ($attachment === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        AttachmentService::forApp()->delete((string) $attachment['stored_name']);
        $attachments->delete((int) $attachment['id']);

        return $this->redirect('/activities/' . (int) $attachment['activity_id'] . '/edit');
    }
}
