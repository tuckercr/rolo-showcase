<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ProposalModel;
use App\Services\ProposalService;
use App\Support\ApiSerializer;
use InvalidArgumentException;

/**
 * Agent write endpoints (piece 2). Nothing here writes to the CRM: every
 * call queues a PROPOSAL that a human approves or rejects at /proposals.
 * Requires the write token.
 */
final class ProposalApiController extends ApiController
{
    public function proposeCreateContact(): string
    {
        return $this->propose('create_contact', $this->jsonBody());
    }

    public function proposeCreateOrganization(): string
    {
        return $this->propose('create_organization', $this->jsonBody());
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function proposeUpdateContact(array $vars): string
    {
        return $this->propose('update_contact', ['contact_id' => (int) $vars['id']] + $this->jsonBody());
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function proposeTagChanges(array $vars): string
    {
        return $this->propose('change_tags', ['contact_id' => (int) $vars['id']] + $this->jsonBody());
    }

    /**
     * @param array<string, string> $vars route parameters
     */
    public function proposeLogActivity(array $vars): string
    {
        return $this->propose('log_activity', ['contact_id' => (int) $vars['id']] + $this->jsonBody());
    }

    /**
     * Poll a proposal's fate (read token is enough).
     *
     * @param array<string, string> $vars route parameters
     */
    public function show(array $vars): string
    {
        $this->requireToken('read');

        $proposal = (new ProposalModel($this->db))->find((int) $vars['id']);

        if ($proposal === null) {
            return $this->respondError(404, 'not_found', 'No proposal with that id.');
        }

        return $this->respond(['data' => ApiSerializer::proposal($proposal)]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function propose(string $action, array $payload): string
    {
        $this->requireToken('write');

        try {
            [$proposal, $isNew] = (new ProposalService($this->config, $this->db))->queue(
                (string) $this->tokenName,
                $action,
                $payload,
                isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? (string) $_SERVER['HTTP_IDEMPOTENCY_KEY'] : null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->respondError(400, 'invalid_payload', $e->getMessage());
        }

        return $this->respond(['data' => ApiSerializer::proposal($proposal)], $isNew ? 202 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $body = json_decode((string) file_get_contents('php://input'), true);

        return is_array($body) ? $body : [];
    }
}
