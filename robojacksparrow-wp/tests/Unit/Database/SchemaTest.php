<?php

declare(strict_types=1);

namespace RoboJackSparrow\Tests\Unit\Database;

use ReflectionClass;
use RoboJackSparrow\Database\Schema;
use RoboJackSparrow\Tests\TestCase;

final class SchemaTest extends TestCase
{
    public function testTableNamesReturnsExactlySevenPrefixedTables(): void
    {
        $tables = Schema::tableNames();

        $this->assertCount(7, $tables);
        $this->assertSame([
            'wp_rjs_articles',
            'wp_rjs_queue',
            'wp_rjs_logs',
            'wp_rjs_settings',
            'wp_rjs_memory',
            'wp_rjs_llm_health',
            'wp_rjs_sources',
        ], $tables);
    }

    /**
     * @dataProvider tableIndexProvider
     */
    public function testEachCreateTableStatementIsDbDeltaCompatible(int $index): void
    {
        $sql = $this->generateSql()[$index];

        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $sql, 'dbDelta requires exactly two spaces before the PRIMARY KEY column list');
        $this->assertStringContainsString('ENGINE=InnoDB', $sql, 'transactions/row locking (QueueManager) require InnoDB');
        $this->assertStringNotContainsStringIgnoringCase('ENUM', $sql, 'ENUM columns break dbDelta diffing');

        $openParens = substr_count($sql, '(');
        $closeParens = substr_count($sql, ')');
        $this->assertSame($openParens, $closeParens, 'unbalanced parentheses');
    }

    public static function tableIndexProvider(): array
    {
        return array_map(static fn (int $i) => [$i], range(0, 6));
    }

    /**
     * @return string[]
     */
    private function generateSql(): array
    {
        $reflection = new ReflectionClass(Schema::class);

        $charsetMethod = $reflection->getMethod('charsetCollate');
        $charsetMethod->setAccessible(true);
        $charsetCollate = $charsetMethod->invoke(null);

        $definitionsMethod = $reflection->getMethod('definitions');
        $definitionsMethod->setAccessible(true);

        return $definitionsMethod->invoke(null, $charsetCollate);
    }
}
