<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\TagModel;

final class TagController extends Controller
{
    private const MAX_DESCRIPTION = 255;

    public function index(): string
    {
        $msg = (string) ($_GET['msg'] ?? '');
        $notice = match ($msg) {
            'updated' => 'Tag saved.',
            'merged' => 'Tags merged.',
            'deleted' => 'Tag deleted.',
            default => null,
        };

        return $this->render('tags/index', [
            'pageTitle' => 'Tags',
            'tags' => (new TagModel($this->db))->allWithCounts(),
            'notice' => $notice,
            'error' => (string) ($_GET['error'] ?? ''),
        ]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function update(array $vars): string
    {
        $this->requireValidCsrf();

        $names = TagModel::parseList((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if (count($names) !== 1) {
            return $this->redirect('/tags?error=' . rawurlencode('A tag needs exactly one name.'));
        }

        if (mb_strlen($names[0]) > TagModel::MAX_LENGTH) {
            return $this->redirect('/tags?error=' . rawurlencode(
                sprintf('Tag names can be at most %d characters.', TagModel::MAX_LENGTH)
            ));
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            return $this->redirect('/tags?error=' . rawurlencode(
                sprintf('Descriptions can be at most %d characters.', self::MAX_DESCRIPTION)
            ));
        }

        $outcome = (new TagModel($this->db))->update((int) $vars['id'], $names[0], $description);

        return $this->redirect($outcome === null ? '/tags' : '/tags?msg=' . $outcome);
    }

    /**
     * @param array<string, string> $vars
     */
    public function delete(array $vars): string
    {
        $this->requireValidCsrf();

        $deleted = (new TagModel($this->db))->delete((int) $vars['id']);

        return $this->redirect($deleted ? '/tags?msg=deleted' : '/tags');
    }
}
