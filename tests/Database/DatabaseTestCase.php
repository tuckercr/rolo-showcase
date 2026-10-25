<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Support\Config;
use App\Support\Database;
use App\Support\Migrator;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that exercise real SQL against a disposable MariaDB
 * database — the dashboard queries (dueBy/goingCold) are the subtlest logic
 * in the app and can't be covered by pure unit tests.
 *
 * Configuration comes from TEST_DB_HOST / TEST_DB_NAME / TEST_DB_USER /
 * TEST_DB_PASSWORD. When TEST_DB_NAME is unset the tests are skipped, so
 * `vendor/bin/phpunit` keeps working on machines without a test database.
 * CI provides a MariaDB service (see .github/workflows/ci.yml). Locally:
 *
 *   TEST_DB_HOST=127.0.0.1 TEST_DB_NAME=test TEST_DB_USER=... \
 *   TEST_DB_PASSWORD=... vendor/bin/phpunit
 *
 * The schema is migrated fresh once per process; every test starts with
 * empty data tables and default stage flags. As a guard against pointing
 * these tests at a real database, the database name must contain "test".
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?Database $database = null;
    private static bool $migrated = false;

    /** Data tables cleared before every test; seed tables (stages,
     *  reminder_rules, users, migrations) are left alone. */
    private const DATA_TABLES = [
        'activity_attachments',
        'activities',
        'contact_tags',
        'tags',
        'opportunities',
        'contacts',
        'organizations',
        'api_proposals',
        'api_audit',
        'daily_summary_log',
    ];

    protected function setUp(): void
    {
        $name = (string) getenv('TEST_DB_NAME');

        if ($name === '') {
            self::markTestSkipped('TEST_DB_NAME is not set — skipping database-backed tests.');
        }

        if (!str_contains(strtolower($name), 'test')) {
            self::fail(sprintf(
                'Refusing to run database tests against "%s": the database name must contain "test".',
                $name,
            ));
        }

        if (!self::$migrated) {
            $this->dropAllTables();
            (new Migrator(self::db()->pdo(), dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            self::$migrated = true;
        }

        $this->truncateDataTables();
        $this->resetStageFlags();
    }

    protected static function db(): Database
    {
        if (self::$database === null) {
            $config = new Config(
                appEnv: 'test',
                appTimezone: new DateTimeZone('America/New_York'),
                dbHost: (string) getenv('TEST_DB_HOST') ?: '127.0.0.1',
                dbName: (string) getenv('TEST_DB_NAME'),
                dbUser: (string) getenv('TEST_DB_USER'),
                dbPassword: (string) getenv('TEST_DB_PASSWORD'),
            );

            self::$database = new Database($config);
        }

        return self::$database;
    }

    private function dropAllTables(): void
    {
        $pdo = self::db()->pdo();
        $tables = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAll(\PDO::FETCH_COLUMN);

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function truncateDataTables(): void
    {
        $pdo = self::db()->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::DATA_TABLES as $table) {
            $pdo->exec('DELETE FROM `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function resetStageFlags(): void
    {
        // Mirror of migrations 0015/0016: only Leads and Closed / Not a Fit
        // start hidden, so a test that toggles flags can't leak into the next.
        self::db()->pdo()->exec(
            "UPDATE stages SET hidden_from_follow_ups = IF(name IN ('Leads', 'Closed / Not a Fit'), 1, 0)"
        );
    }
}
