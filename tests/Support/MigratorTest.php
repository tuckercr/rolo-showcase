<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitsOnSemicolons(): void
    {
        $statements = Migrator::splitStatements(
            "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);"
        );

        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            $statements,
        );
    }

    public function testStripsCommentLinesAndBlankStatements(): void
    {
        $sql = <<<SQL
            -- a leading comment
            CREATE TABLE a (id INT);

            -- another comment between statements
            INSERT INTO a (id) VALUES (1);
            SQL;

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE TABLE a (id INT)', $statements[0]);
        $this->assertSame('INSERT INTO a (id) VALUES (1)', $statements[1]);
    }

    public function testKeepsInlineCommentsInsideStatements(): void
    {
        $sql = "CREATE TABLE a (\n    id INT, -- not a full-line comment\n    x INT\n);";

        $statements = Migrator::splitStatements($sql);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('not a full-line comment', $statements[0]);
    }

    public function testEmptyInputYieldsNoStatements(): void
    {
        $this->assertSame([], Migrator::splitStatements("-- only comments\n\n"));
    }
}
