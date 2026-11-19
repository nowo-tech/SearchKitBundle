<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Test\QueryCountingMiddleware;

use function getenv;
use function is_string;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The test database of the entity search tests: SQLite in memory by default, or the server of
 * SEARCH_KIT_TEST_DATABASE_URL (mysql://, mariadb://, postgresql://) — the CI matrix runs MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16. One connection per test process (test code only).
 */
final class NoteDatabase
{
    public const string TABLE = 'sk_note';

    public const string ENV = 'SEARCH_KIT_TEST_DATABASE_URL';

    private static ?self $instance = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (test process only)

    private ?EntityManager $entityManager = null;

    private function __construct(
        public readonly Connection $connection,
        public readonly QueryCounter $counter,
    ) {
    }

    public static function get(): self
    {
        if (!self::$instance instanceof self) {
            $counter       = new QueryCounter();
            $configuration = new Configuration();
            $configuration->setMiddlewares([new QueryCountingMiddleware($counter)]);
            $connection     = DriverManager::getConnection(self::parameters(), $configuration);
            self::$instance = new self($connection, $counter); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
            self::$instance->createSchema();
        }

        return self::$instance;
    }

    /**
     * The driver name of the current database (pdo_sqlite, pdo_mysql, pdo_pgsql).
     */
    public static function driver(): string
    {
        return (string) (self::parameters()['driver'] ?? 'pdo_sqlite');
    }

    public function entityManager(): EntityManager
    {
        if (!$this->entityManager instanceof EntityManager) {
            $config = ORMSetup::createAttributeMetadataConfig([__DIR__], true);
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-search-kit-bundle/proxies');
            $config->setProxyNamespace('NowoSearchKitTestProxies');
            if (PHP_VERSION_ID >= 80400) {
                $config->enableNativeLazyObjects(true);
            }
            $this->entityManager = new EntityManager($this->connection, $config);
        }

        return $this->entityManager;
    }

    public function insert(string $tenant, string $title, bool $archived = false, bool $hidden = false, string $owner = 'alice', ?string $body = null): int
    {
        $this->connection->insert(self::TABLE, [
            'tenant'   => $tenant,
            'title'    => $title,
            'owner'    => $owner,
            'archived' => $archived,
            'hidden'   => $hidden,
            'body'     => $body,
        ], [
            'archived' => ParameterType::BOOLEAN,
            'hidden'   => ParameterType::BOOLEAN,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function createSchema(): void
    {
        $schema = new Schema();
        $table  = $schema->createTable(self::TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('tenant', 'string', ['length' => 64]);
        $table->addColumn('title', 'string', ['length' => 255]);
        $table->addColumn('owner', 'string', ['length' => 64]);
        $table->addColumn('archived', 'boolean');
        $table->addColumn('hidden', 'boolean');
        $table->addColumn('body', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $manager = $this->connection->createSchemaManager();
        if ($manager->tablesExist([self::TABLE])) {
            $manager->dropTable(self::TABLE);
        }
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameters(): array
    {
        $url = getenv(self::ENV);
        if (!is_string($url) || $url === '') {
            return ['driver' => 'pdo_sqlite', 'memory' => true];
        }

        return (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($url);
    }
}
