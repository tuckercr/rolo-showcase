<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\UserModel;

/**
 * Session-backed authentication state. Google Sign-In (or the local dev stub)
 * decides WHO the user is; this class only tracks the logged-in user id in
 * the session and loads the row on demand.
 */
final class Auth
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private bool $userLoaded = false;

    public function __construct(private readonly UserModel $users)
    {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if (!$this->userLoaded) {
            $userId = $_SESSION['user_id'] ?? null;
            $this->user = is_int($userId) ? $this->users->find($userId) : null;
            $this->userLoaded = true;
        }

        return $this->user;
    }

    public function id(): int
    {
        $user = $this->user();

        if ($user === null) {
            throw new \RuntimeException('No authenticated user.');
        }

        return (int) $user['id'];
    }

    public function loginAs(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $this->userLoaded = false;
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
        $this->user = null;
        $this->userLoaded = true;
    }
}
