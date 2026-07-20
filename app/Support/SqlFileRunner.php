<?php

namespace App\Support;

class SqlFileRunner
{
    public static function statementsFromFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("SQL 文件不可读：{$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            throw new \RuntimeException("SQL 文件为空：{$path}");
        }

        return self::splitStatements($contents);
    }

    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        $lines = preg_split('/\R/', $sql);
        $delimiter = ';';
        $buffer = '';
        $statements = [];

        foreach ($lines as $line) {
            if (!self::hasExecutableSql($buffer)
                && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches)) {
                $delimiter = $matches[1];
                $buffer = '';
                continue;
            }

            $buffer .= $line . "\n";
            while (($position = self::findDelimiter($buffer, $delimiter)) !== null) {
                $statement = substr($buffer, 0, $position);
                $buffer = substr($buffer, $position + strlen($delimiter));
                if (self::hasExecutableSql($statement)) {
                    $statements[] = trim($statement);
                }
            }
        }

        if (self::hasExecutableSql($buffer)) {
            $statements[] = trim($buffer);
        }

        return $statements;
    }

    private static function findDelimiter(string $sql, string $delimiter)
    {
        $length = strlen($sql);
        $delimiterLength = strlen($delimiter);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : null;

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $i++;
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '-' && $next === '-') {
                $after = $i + 2 < $length ? $sql[$i + 2] : null;
                if ($after === null || ctype_space($after)) {
                    $lineComment = true;
                    $i++;
                    continue;
                }
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }

            if ($delimiterLength > 0 && substr($sql, $i, $delimiterLength) === $delimiter) {
                return $i;
            }
        }

        return null;
    }

    private static function hasExecutableSql(string $sql): bool
    {
        $length = strlen($sql);
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : null;

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if (ctype_space($char)) {
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '-' && $next === '-') {
                $after = $i + 2 < $length ? $sql[$i + 2] : null;
                if ($after === null || ctype_space($after)) {
                    $lineComment = true;
                    $i++;
                    continue;
                }
            }
            if ($char === '/' && $next === '*') {
                // MySQL version comments and optimizer hints are executable SQL.
                $after = $i + 2 < $length ? $sql[$i + 2] : null;
                if ($after === '!' || $after === '+') {
                    return true;
                }
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                return true;
            }

            return true;
        }

        return false;
    }
}
