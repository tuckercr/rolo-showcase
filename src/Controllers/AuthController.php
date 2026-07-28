<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\GoogleAuthService;
use App\Support\View;
use RuntimeException;

final class AuthController extends Controller
{
    private const ERROR_MESSAGES = [
        'not_allowed' => 'That Google account isn\'t on the allowlist for this app.',
        'google_failed' => 'Google sign-in didn\'t complete — please try again.',
        'not_configured' => 'Google Sign-In isn\'t configured yet.',
    ];

    public function showLogin(): string
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }

        // The dev stub is only offered locally; Google Sign-In appears
        // whenever the OAuth client is configured (production always).
        $devUsers = $this->config->isLocal() ? (new UserModel($this->db))->all() : [];
        $errorKey = (string) ($_GET['error'] ?? '');

        return View::render('auth/login', [
            'isLocal' => $this->config->isLocal(),
            'devUsers' => $devUsers,
            'googleEnabled' => (new GoogleAuthService($this->config))->isConfigured(),
            'error' => self::ERROR_MESSAGES[$errorKey] ?? null,
        ]);
    }

    public function googleRedirect(): string
    {
        $google = new GoogleAuthService($this->config);

        if (!$google->isConfigured()) {
            return $this->redirect('/login?error=not_configured');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        return $this->redirect($google->authUrl($state));
    }

    public function googleCallback(): string
    {
        $expectedState = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);

        $state = (string) ($_GET['state'] ?? '');
        $code = (string) ($_GET['code'] ?? '');

        if (
            !is_string($expectedState)
            || $expectedState === ''
            || !hash_equals($expectedState, $state)
            || $code === ''
        ) {
            return $this->redirect('/login?error=google_failed');
        }

        $google = new GoogleAuthService($this->config);

        try {
            $identity = $google->authenticate($code);
        } catch (RuntimeException) {
            return $this->redirect('/login?error=google_failed');
        }

        // Google proves identity; the allowlist decides authorization.
        if (!$google->isAllowedEmail($identity['email'])) {
            return $this->redirect('/login?error=not_allowed');
        }

        $users = new UserModel($this->db);
        $user = $users->findByGoogleSub($identity['sub']);

        if ($user === null) {
            $user = $users->findByEmail($identity['email']);

            if ($user !== null) {
                // Existing (seeded) user's first Google login: remember the sub.
                $users->setGoogleSub((int) $user['id'], $identity['sub']);
            } else {
                // Allowlisted email with no row yet: create it now.
                $newId = $users->create($identity['name'], $identity['email'], $identity['sub']);
                $user = $users->find($newId);
            }
        }

        $this->auth->loginAs((int) $user['id']);

        return $this->redirect('/');
    }

    public function devLogin(): string
    {
        if (!$this->config->isLocal()) {
            http_response_code(404);
            return View::render('errors/404');
        }

        $this->requireValidCsrf();

        $userId = (int) ($_POST['user_id'] ?? 0);
        $user = (new UserModel($this->db))->find($userId);

        if ($user === null) {
            return $this->redirect('/login');
        }

        $this->auth->loginAs($userId);

        return $this->redirect('/');
    }

    public function logout(): string
    {
        $this->requireValidCsrf();
        $this->auth->logout();

        return $this->redirect('/login');
    }
}
