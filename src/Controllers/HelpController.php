<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Static help page: how to connect AI assistants to Rolo over MCP, what
 * they can do, and how the approval gate protects the data. Deliberately
 * contains no tokens; the connector link is a credential and travels by
 * direct message, never on a web page.
 */
final class HelpController extends Controller
{
    public function index(): string
    {
        $appUrl = $this->config->appUrl !== ''
            ? $this->config->appUrl
            : 'https://crm.example.com';

        return $this->render('help/index', [
            'pageTitle' => 'Help',
            'appUrl' => $appUrl,
            'appHost' => (string) parse_url($appUrl, PHP_URL_HOST),
        ]);
    }
}
