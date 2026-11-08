<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ContactModel;
use App\Services\PhotoService;
use App\Support\View;
use InvalidArgumentException;
use RuntimeException;

/**
 * Contact profile photos: the session-gated serving route plus upload and
 * remove. Files live outside the web root; this controller is the only way
 * a photo reaches a browser.
 */
final class PhotoController extends Controller
{
    /**
     * @param array<string, string> $vars route parameters
     */
    public function show(array $vars): string
    {
        $contact = (new ContactModel($this->db))->find((int) $vars['id']);
        $photo = $contact['photo'] ?? null;

        if ($photo === null) {
            http_response_code(404);
            return '';
        }

        $path = PhotoService::forApp()->path((string) $photo);

        if (!is_file($path)) {
            http_response_code(404);
            return '';
        }

        // The stored name is random per upload, so it IS the content hash.
        $etag = '"' . basename((string) $photo) . '"';
        header('Cache-Control: private, max-age=86400');
        header('ETag: ' . $etag);

        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);

            return '';
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);

        return '';
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function upload(array $vars): string
    {
        $this->requireValidCsrf();

        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $file = $_FILES['photo'] ?? null;
        $service = PhotoService::forApp();

        try {
            $service->validate(
                (string) ($file['tmp_name'] ?? ''),
                (int) ($file['size'] ?? 0),
                (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            );
            $storedName = $service->store((string) $file['tmp_name']);
        } catch (InvalidArgumentException | RuntimeException) {
            return $this->redirect('/contacts/' . (int) $contact['id'] . '?error=photo');
        }

        $contacts->setPhoto((int) $contact['id'], $storedName, $this->auth->id());

        if (($contact['photo'] ?? null) !== null) {
            $service->delete((string) $contact['photo']);
        }

        return $this->redirect('/contacts/' . (int) $contact['id']);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function delete(array $vars): string
    {
        $this->requireValidCsrf();

        $contacts = new ContactModel($this->db);
        $contact = $contacts->find((int) $vars['id']);

        if ($contact === null) {
            http_response_code(404);
            return View::render('errors/404');
        }

        if (($contact['photo'] ?? null) !== null) {
            PhotoService::forApp()->delete((string) $contact['photo']);
            $contacts->setPhoto((int) $contact['id'], null, $this->auth->id());
        }

        return $this->redirect('/contacts/' . (int) $contact['id']);
    }
}
