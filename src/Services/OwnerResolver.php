<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UserModel;
use App\Support\Database;
use InvalidArgumentException;

/**
 * Turns the way an agent or a typed command names an owner ('me',
 * 'shared', a user id, a user email) into a user id, or null for shared.
 * One reading of those words for every owned entity: the contact owner
 * filter and task assignment both come through here.
 *
 * Only the WORDS are resolved. Whether the resulting user may be assigned
 * something is the caller's rule (TaskService checks assignability; a
 * read filter needs no such check).
 */
final class OwnerResolver
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param int|null $actorUserId the person behind the request, when known
     * @return int|null a user id, or null for 'shared'
     * @throws InvalidArgumentException with an agent-presentable message
     */
    public function resolve(string $raw, ?int $actorUserId): ?int
    {
        $raw = trim($raw);

        if ($raw === 'shared') {
            return null;
        }

        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        if (strtolower($raw) === 'me') {
            if ($actorUserId === null) {
                throw new InvalidArgumentException(
                    'owner=me needs a per-user token; this token is anonymous.',
                );
            }

            return $actorUserId;
        }

        if (str_contains($raw, '@')) {
            $user = (new UserModel($this->db))->findByEmail(strtolower($raw));

            if ($user === null) {
                throw new InvalidArgumentException('No user with that email.');
            }

            return (int) $user['id'];
        }

        throw new InvalidArgumentException(
            "owner must be 'me', 'shared', a user id, or a user email.",
        );
    }
}
