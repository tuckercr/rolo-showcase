<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\ProposalModel;
use Tests\Database\DatabaseTestCase;

final class ProposalModelTest extends DatabaseTestCase
{
    private function createProposal(): int
    {
        return (new ProposalModel(self::db()))->create(
            'mcp-test',
            'create_organization',
            ['name' => 'Acme Co'],
            'Create organization Acme Co',
            null,
        );
    }

    public function testCreateRecordsTheProposerAndPendingNamesThem(): void
    {
        $proposals = new ProposalModel(self::db());

        // Seeded users: 1 = Colin, 2 = Dana (migration 0002).
        $proposals->create('user:2', 'create_organization', ['name' => 'A'], 'By Dana', null, 2);
        $proposals->create('write', 'create_organization', ['name' => 'B'], 'Anonymous', null);

        $pending = $proposals->pending();

        self::assertSame('Dana', $pending[0]['proposed_by_name']);
        self::assertNull($pending[1]['proposed_by'], 'legacy shared tokens stay anonymous');
        self::assertNull($pending[1]['proposed_by_name']);
    }

    public function testDecideIsACompareAndSetOnlyOneCallerWins(): void
    {
        $proposals = new ProposalModel(self::db());
        $id = $this->createProposal();

        // Two concurrent Approve taps: exactly one wins; the loser's call
        // reports failure so it never runs apply().
        self::assertTrue($proposals->decide($id, 'approved', 1));
        self::assertFalse($proposals->decide($id, 'approved', 2));
        self::assertFalse($proposals->decide($id, 'rejected', 2));

        $row = (array) $proposals->find($id);
        self::assertSame('approved', $row['status']);
        self::assertSame(1, (int) $row['reviewed_by']);
    }

    public function testApplyFailureWalksApprovedBackToRejected(): void
    {
        $proposals = new ProposalModel(self::db());
        $id = $this->createProposal();

        self::assertTrue($proposals->decide($id, 'approved', 1));
        // The apply-failed path: the winner records the error.
        self::assertTrue($proposals->decide($id, 'rejected', 1, 'boom', fromStatus: 'approved'));

        $row = (array) $proposals->find($id);
        self::assertSame('rejected', $row['status']);
        self::assertSame('boom', $row['error']);
    }
}
