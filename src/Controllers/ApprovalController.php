<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ProposalModel;
use App\Services\ProposalService;
use App\Support\View;
use Throwable;

/**
 * Human review of agent proposals: approving applies the change (attributed
 * to the approver), rejecting just closes it. Agents poll the outcome via
 * GET /api/v1/proposals/{id}.
 */
final class ApprovalController extends Controller
{
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

        return $this->render('approvals/index', [
            'pageTitle' => 'Approvals',
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

        $proposals = new ProposalModel($this->db);
        $proposal = $proposals->find((int) $vars['id']);

        if ($proposal === null || (string) $proposal['status'] !== 'pending') {
            return $this->redirect('/proposals');
        }

        try {
            (new ProposalService($this->config, $this->db))->apply($proposal, $this->auth->id());
        } catch (Throwable $e) {
            $proposals->decide((int) $proposal['id'], 'rejected', $this->auth->id(), $e->getMessage());

            return $this->redirect('/proposals?apply_error=' . rawurlencode($e->getMessage()));
        }

        $proposals->decide((int) $proposal['id'], 'approved', $this->auth->id());

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
