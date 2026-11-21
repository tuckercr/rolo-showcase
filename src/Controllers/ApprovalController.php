<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProposalModel;
use App\Services\ProposalService;
use App\Support\View;

/**
 * Human review of agent proposals: approving applies the change (attributed
 * to the approver), rejecting just closes it. Agents poll the outcome via
 * GET /api/v1/proposals/{id}.
 */
final class ApprovalController extends Controller
{
    private const BULK_RESULT_KEY = 'approve_all_result';

    public function index(): string
    {
        $proposals = new ProposalModel($this->db);
        $service = new ProposalService($this->config, $this->db);

        $pending = $proposals->pending();

        // Human-readable card per proposal, diffed against live data.
        $cards = array_map(
            static fn(array $p): array => $p + ['view' => $service->describe($p)],
            $pending,
        );

        // Flag proposals whose contact has OTHER pending proposals too, so
        // out-of-order approvals are a visible choice, not a surprise.
        $contactCounts = [];

        foreach ($cards as $card) {
            $contactId = $card['view']['contact']['id'] ?? null;

            if ($contactId !== null) {
                $contactCounts[$contactId] = ($contactCounts[$contactId] ?? 0) + 1;
            }
        }

        foreach ($cards as $i => $card) {
            $contactId = $card['view']['contact']['id'] ?? null;
            $cards[$i]['view']['also_pending'] = $contactId !== null && $contactCounts[$contactId] > 1;
        }

        $bulkResult = $_SESSION[self::BULK_RESULT_KEY] ?? null;
        unset($_SESSION[self::BULK_RESULT_KEY]);

        return $this->render('approvals/index', [
            'pageTitle' => 'Approvals',
            'bulkResult' => is_array($bulkResult) ? $bulkResult : null,
            'pendingBreakdown' => ProposalService::breakdown($pending),
            'pendingThroughId' => $pending === [] ? 0 : (int) end($pending)['id'],
            'pending' => $cards,
            'decided' => $proposals->recentlyDecided(),
            'applyError' => trim((string) ($_GET['apply_error'] ?? '')) ?: null,
        ]);
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function approve(array $vars): string
    {
        $this->requireValidCsrf();

        $result = (new ProposalService($this->config, $this->db))
            ->approve((int) $vars['id'], $this->auth->id());

        if ($result['outcome'] === 'failed') {
            return $this->redirect('/proposals?apply_error=' . rawurlencode((string) $result['error']));
        }

        return $this->redirect('/proposals');
    }

    /**
     * Approve everything that was on the page when the button was pressed,
     * oldest first. through_id is the newest proposal that page showed, so
     * one that arrived since is not approved unseen. The outcome rides the
     * session to the next page load, once: a list of failures does not fit
     * in a query string.
     */
    public function approveAll(): string
    {
        $this->requireValidCsrf();

        $throughId = (int) ($_POST['through_id'] ?? 0);

        if ($throughId > 0) {
            // A long queue is many small writes; give it room rather than
            // stopping half way. Stopping would still be safe (each proposal
            // is decided on its own), just incomplete.
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $_SESSION[self::BULK_RESULT_KEY] = (new ProposalService($this->config, $this->db))
                ->approveAllPending($this->auth->id(), $throughId);
        }

        return $this->redirect('/proposals');
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function reject(array $vars): string
    {
        $this->requireValidCsrf();

        (new ProposalModel($this->db))->decide((int) $vars['id'], 'rejected', $this->auth->id());

        return $this->redirect('/proposals');
    }
}
