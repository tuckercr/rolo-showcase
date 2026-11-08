<?php

declare(strict_types=1);

namespace Tests\Models;

use App\Models\TagModel;
use Tests\Database\DatabaseTestCase;

/**
 * Tags look trivial but carry three invariants the UI and API both lean on:
 * case-insensitive uniqueness (collation), orphan pruning after every
 * detach, and counts that ignore trashed contacts.
 */
final class TagModelTest extends DatabaseTestCase
{
    private TagModel $tags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tags = new TagModel(self::db());
    }

    public function testFindOrCreateIsCaseInsensitive(): void
    {
        $first = $this->tags->findOrCreate('VIP');
        $second = $this->tags->findOrCreate('vip');

        self::assertSame($first, $second, 'differently-cased spellings must resolve to one tag');
        self::assertSame(1, $this->tagCount());
    }

    public function testSyncReplacesTagsAndPrunesOrphans(): void
    {
        $contactId = $this->insertContact('Tagged Tia');
        $this->tags->syncForContact($contactId, ['conference', 'warm intro']);
        $this->tags->syncForContact($contactId, ['warm intro', 'vip']);

        self::assertSame(['vip', 'warm intro'], $this->tags->namesForContact($contactId));
        self::assertSame(2, $this->tagCount(), 'the detached "conference" tag must be pruned');
    }

    public function testRemoveForContactKeepsTagsSharedWithOthers(): void
    {
        $a = $this->insertContact('Alice');
        $b = $this->insertContact('Bruno');
        $this->tags->addForContact($a, ['shared', 'only-a']);
        $this->tags->addForContact($b, ['shared']);

        $this->tags->removeForContact($a, ['shared', 'only-a']);

        self::assertSame([], $this->tags->namesForContact($a));
        self::assertSame(['shared'], $this->tags->namesForContact($b));
        self::assertSame(1, $this->tagCount(), 'only the now-orphaned tag is pruned');
    }

    public function testAllWithCountsIgnoresTrashedContacts(): void
    {
        $kept = $this->insertContact('Kept Kai');
        $trashed = $this->insertContact('Trashed Tom');
        $this->tags->addForContact($kept, ['vip']);
        $this->tags->addForContact($trashed, ['vip']);
        self::db()->pdo()
            ->prepare('UPDATE contacts SET deleted_at = NOW() WHERE id = ?')
            ->execute([$trashed]);

        $rows = $this->tags->allWithCounts();

        self::assertCount(1, $rows);
        self::assertSame('vip', $rows[0]['name']);
        self::assertSame(1, (int) $rows[0]['contact_count']);
    }

    public function testNamesForContactsGroupsByContact(): void
    {
        $a = $this->insertContact('Alice');
        $b = $this->insertContact('Bruno');
        $this->tags->addForContact($a, ['vip', 'media']);
        $this->tags->addForContact($b, ['media']);

        $grouped = $this->tags->namesForContacts([$a, $b]);

        self::assertSame(['media', 'vip'], $grouped[$a]);
        self::assertSame(['media'], $grouped[$b]);
        self::assertSame([], $this->tags->namesForContacts([]));
    }

    private function insertContact(string $name): int
    {
        $stmt = self::db()->pdo()->prepare(
            "INSERT INTO contacts (name, relationship_status, created_by, updated_by)
             VALUES (:name, 'Conversation Started', 1, 1)"
        );
        $stmt->execute(['name' => $name]);

        return (int) self::db()->pdo()->lastInsertId();
    }

    private function tagCount(): int
    {
        return (int) self::db()->pdo()->query('SELECT COUNT(*) FROM tags')->fetchColumn();
    }
}
