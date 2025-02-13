<?php

declare(strict_types=1);

namespace PhpMyAdmin\SqlParser\Tools;

use function array_map;
use function array_merge;
use function array_slice;
use function basename;
use function count;
use function dirname;
use function file;
use function file_put_contents;
use function implode;
use function ksort;
use function preg_match;
use function round;
use function scandir;
use function sort;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_split;
use function strlen;
use function strstr;
use function strtoupper;
use function substr;
use function trim;

use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const SORT_STRING;

/**
 * Used for context generation.
 */
class ContextGenerator
{
    /**
     * Labels and flags that may be used when defining keywords.
     *
     * @var array<string, int>
     */
    public static $labelsFlags = [
        '(R)' => 2, // reserved
        '(D)' => 8, // data type
        '(K)' => 16, // keyword
        '(F)' => 32, // function name
    ];

    /**
     * Documentation links for each context.
     *
     * @var array<string, string>
     */
    public static $links = [
        'MySql50000' => 'https://dev.mysql.com/doc/refman/5.0/en/keywords.html',
        'MySql50100' => 'https://dev.mysql.com/doc/refman/5.1/en/keywords.html',
        'MySql50500' => 'https://dev.mysql.com/doc/refman/5.5/en/keywords.html',
        'MySql50600' => 'https://dev.mysql.com/doc/refman/5.6/en/keywords.html',
        'MySql50700' => 'https://dev.mysql.com/doc/refman/5.7/en/keywords.html',
        'MySql80000' => 'https://dev.mysql.com/doc/refman/8.0/en/keywords.html',
        'MariaDb100000' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100100' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100200' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100300' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100400' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100500' => 'https://mariadb.com/kb/en/reserved-words/',
        'MariaDb100600' => 'https://mariadb.com/kb/en/reserved-words/',
    ];

    /**
     * The template of a context.
     *
     * Parameters:
     *     1 - name
     *     2 - class
     *     3 - link
     *     4 - keywords array
     */
    public const TEMPLATE = <<<'PHP'
<?php

declare(strict_types=1);

namespace PhpMyAdmin\SqlParser\Contexts;

use PhpMyAdmin\SqlParser\Context;
use PhpMyAdmin\SqlParser\Token;

/**
 * Context for %1$s.
 *
 * This class was auto-generated from tools/contexts/*.txt.
 * Use tools/run_generators.sh for update.
 *
 * @see %3$s
 */
class %2$s extends Context
{
    /**
     * List of keywords.
     *
     * The value associated to each keyword represents its flags.
     *
     * @see Token::FLAG_KEYWORD_RESERVED Token::FLAG_KEYWORD_COMPOSED
     *      Token::FLAG_KEYWORD_DATA_TYPE Token::FLAG_KEYWORD_KEY
     *      Token::FLAG_KEYWORD_FUNCTION
     *
     * @var array<string,int>
     * @psalm-var non-empty-array<string,Token::FLAG_KEYWORD_*|int>
     * @phpstan-var non-empty-array<non-empty-string,Token::FLAG_KEYWORD_*|int>
     */
    public static $keywords = [
%4$s    ];
}

PHP;

    /**
     * Sorts an array of words.
     *
     * @param array<int, array<int, array<int, string>>> $arr
     *
     * @return array<int, array<int, array<int, string>>>
     */
    public static function sortWords(array &$arr)
    {
        ksort($arr);
        foreach ($arr as &$wordsByLen) {
            ksort($wordsByLen);
            foreach ($wordsByLen as &$words) {
                sort($words, SORT_STRING);
            }
        }

        return $arr;
    }

    /**
     * Reads a list of words and sorts it by type, length and keyword.
     *
     * @param string[] $files
     *
     * @return array<int, array<int, array<int, string>>>
     */
    public static function readWords(array $files)
    {
        $words = [];
        foreach ($files as $file) {
            $words = array_merge($words, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }

        /** @var array<string, int> $types */
        $types = [];

        for ($i = 0, $count = count($words); $i !== $count; ++$i) {
            $type = 1;
            $value = trim($words[$i]);

            // Reserved, data types, keys, functions, etc. keywords.
            foreach (static::$labelsFlags as $label => $flags) {
                if (strstr($value, $label) === false) {
                    continue;
                }

                $type |= $flags;
                $value = trim(str_replace($label, '', $value));
            }

            // Composed keyword.
            if (strstr($value, ' ') !== false) {
                $type |= 2; // Reserved keyword.
                $type |= 4; // Composed keyword.
            }

            $len = strlen($words[$i]);
            if ($len === 0) {
                continue;
            }

            $value = strtoupper($value);
            if (! isset($types[$value])) {
                $types[$value] = $type;
            } else {
                $types[$value] |= $type;
            }
        }

        $ret = [];
        foreach ($types as $word => $type) {
            $len = strlen($word);
            if (! isset($ret[$type])) {
                $ret[$type] = [];
            }

            if (! isset($ret[$type][$len])) {
                $ret[$type][$len] = [];
            }

            $ret[$type][$len][] = $word;
        }

        return static::sortWords($ret);
    }

    /**
     * Prints an array of a words in PHP format.
     *
     * @param array<int, array<int, array<int, string>>> $words  the list of words to be formatted
     * @param int                                        $spaces the number of spaces that starts every line
     * @param int                                        $line   the length of a line
     */
    public static function printWords($words, $spaces = 8, $line = 140): string
    {
        $typesCount = count($words);
        $ret = '';
        $j = 0;

        foreach ($words as $type => $wordsByType) {
            foreach ($wordsByType as $len => $wordsByLen) {
                $count = round(($line - $spaces) / ($len + 9)); // strlen("'' => 1, ") = 9
                $i = 0;

                foreach ($wordsByLen as $word) {
                    if ($i === 0) {
                        $ret .= str_repeat(' ', $spaces);
                    }

                    $ret .= sprintf('\'%s\' => %s, ', $word, $type);
                    if (++$i !== $count && ++$i <= $count) {
                        continue;
                    }

                    $ret .= "\n";
                    $i = 0;
                }

                if ($i === 0) {
                    continue;
                }

                $ret .= "\n";
            }

            if (++$j >= $typesCount) {
                continue;
            }

            $ret .= "\n";
        }

        // Trim trailing spaces and return.
        return str_replace(" \n", "\n", $ret);
    }

    /**
     * Generates a context's class.
     *
     * @param array<string, string|array<int, array<int, array<int, string>>>> $options the options for this context
     * @psalm-param array{
     *   name: string,
     *   class: string,
     *   link: string,
     *   keywords: array<int, array<int, array<int, string>>>
     * } $options
     */
    public static function generate($options): string
    {
        if (isset($options['keywords'])) {
            $options['keywords'] = static::printWords($options['keywords']);
        }

        return sprintf(self::TEMPLATE, $options['name'], $options['class'], $options['link'], $options['keywords']);
    }

    /**
     * Formats context name.
     *
     * @param string $name name to format
     *
     * @return string
     */
    public static function formatName($name)
    {
        /* Split name and version */
        $parts = [];
        if (preg_match('/([^[0-9]*)([0-9]*)/', $name, $parts) === false) {
            return $name;
        }

        /* Format name */
        $base = $parts[1];
        if ($base === 'MySql') {
            $base = 'MySQL';
        } elseif ($base === 'MariaDb') {
            $base = 'MariaDB';
        }

        /* Parse version to array */
        $versionString = $parts[2];
        if (strlen($versionString) % 2 === 1) {
            $versionString = '0' . $versionString;
        }

        $version = array_map('intval', str_split($versionString, 2));
        /* Remove trailing zero */
        if ($version[count($version) - 1] === 0) {
            $version = array_slice($version, 0, count($version) - 1);
        }

        /* Create name */
        return $base . ' ' . implode('.', $version);
    }

    /**
     * Builds a test.
     *
     * Reads the input file, generates the data and writes it back.
     *
     * @param string $input  the input file
     * @param string $output the output directory
     */
    public static function build($input, $output): void
    {
        /**
         * The directory that contains the input file.
         *
         * Used to include common files.
         *
         * @var string
         */
        $directory = dirname($input) . '/';

        /**
         * The name of the file that contains the context.
         */
        $file = basename($input);

        /**
         * The name of the context.
         *
         * @var string
         */
        $name = substr($file, 0, -4);

        /**
         * The name of the class that defines this context.
         *
         * @var string
         */
        $class = 'Context' . $name;

        /**
         * The formatted name of this context.
         */
        $formattedName = static::formatName($name);

        file_put_contents(
            $output . '/' . $class . '.php',
            static::generate(
                [
                    'name' => $formattedName,
                    'class' => $class,
                    'link' => static::$links[$name],
                    'keywords' => static::readWords(
                        [
                            $directory . '_common.txt',
                            $directory . '_functions' . $file,
                            $directory . $file,
                        ]
                    ),
                ]
            )
        );
    }

    /**
     * Generates recursively all tests preserving the directory structure.
     *
     * @param string $input  the input directory
     * @param string $output the output directory
     */
    public static function buildAll($input, $output): void
    {
        $files = scandir($input);

        foreach ($files as $file) {
            // Skipping current and parent directories.
            // Skipping _functions* and _common.txt files
            if (($file[0] === '.') || ($file[0] === '_')) {
                continue;
            }

            // Skipping README.md
            if ($file === 'README.md') {
                continue;
            }

            // Building the context.
            echo sprintf("Building context for %s...\n", $file);
            static::build($input . '/' . $file, $output);
        }
    }
}
