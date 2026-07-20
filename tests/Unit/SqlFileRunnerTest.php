<?php

namespace Tests\Unit;

use App\Support\SqlFileRunner;
use PHPUnit\Framework\TestCase;

class SqlFileRunnerTest extends TestCase
{
    public function testQuotedSemicolonsAndCommentsDoNotSplitStatements(): void
    {
        $sql = <<<'SQL'
-- heading comment;
CREATE TABLE `example` (
    `value` varchar(255) DEFAULT ';'
); # trailing comment;

/* block comment containing ; */
INSERT INTO `example` (`value`) VALUES ('one;two'), ("three;four");
# final comment;
SQL;

        $statements = SqlFileRunner::splitStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertStringContainsString("DEFAULT ';'", $statements[0]);
        $this->assertStringContainsString("'one;two'", $statements[1]);
        $this->assertStringContainsString('"three;four"', $statements[1]);
    }

    public function testDelimiterBlocksAreReturnedAsExecutableStatementsOnly(): void
    {
        $sql = <<<'SQL'
-- A comment before DELIMITER must not hide the directive.
DELIMITER $$
DROP PROCEDURE IF EXISTS `example` $$
CREATE PROCEDURE `example`()
BEGIN
    SELECT 'first;value';
    /* ; inside a comment */
    SELECT 2;
END $$
DELIMITER ;
CALL `example`();
-- trailing comment without a statement
SQL;

        $statements = SqlFileRunner::splitStatements($sql);

        $this->assertCount(3, $statements);
        $this->assertStringStartsWith('DROP PROCEDURE', $statements[0]);
        $this->assertStringStartsWith('CREATE PROCEDURE', $statements[1]);
        $this->assertStringContainsString("SELECT 'first;value';", $statements[1]);
        $this->assertStringStartsWith('CALL', $statements[2]);
        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('DELIMITER', $statement);
        }
    }

    public function testRepositoryUpdateFileParsesWithoutClientDirectives(): void
    {
        $path = dirname(__DIR__, 2) . '/database/update.sql';
        $statements = SqlFileRunner::statementsFromFile($path);

        $this->assertGreaterThan(100, count($statements));
        $this->assertNotEmpty(array_filter($statements, function (string $statement): bool {
            return strpos($statement, 'CREATE PROCEDURE `path-2022-03-29`') !== false;
        }));
        foreach ($statements as $statement) {
            $this->assertDoesNotMatchRegularExpression('/^\s*DELIMITER\b/i', $statement);
        }
    }
}
