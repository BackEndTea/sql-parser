<?php

declare(strict_types=1);

namespace PhpMyAdmin\SqlParser\Components;

use PhpMyAdmin\SqlParser\Component;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokensList;

use function array_key_exists;
use function in_array;
use function is_numeric;
use function is_string;

/**
 * Parses an alter operation.
 *
 * @final
 */
class AlterOperation extends Component
{
    /**
     * All database options.
     *
     * @var array<string, int|array<int, int|string>>
     * @psalm-var array<string, (positive-int|array{positive-int, ('var'|'var='|'expr'|'expr=')})>
     */
    public static $DB_OPTIONS = [
        'CHARACTER SET' => [
            1,
            'var',
        ],
        'CHARSET' => [
            1,
            'var',
        ],
        'DEFAULT CHARACTER SET' => [
            1,
            'var',
        ],
        'DEFAULT CHARSET' => [
            1,
            'var',
        ],
        'UPGRADE' => [
            1,
            'var',
        ],
        'COLLATE' => [
            2,
            'var',
        ],
        'DEFAULT COLLATE' => [
            2,
            'var',
        ],
    ];

    /**
     * All table options.
     *
     * @var array<string, int|array<int, int|string>>
     * @psalm-var array<string, (positive-int|array{positive-int, ('var'|'var='|'expr'|'expr=')})>
     */
    public static $TABLE_OPTIONS = [
        'ENGINE' => [
            1,
            'var=',
        ],
        'AUTO_INCREMENT' => [
            1,
            'var=',
        ],
        'AVG_ROW_LENGTH' => [
            1,
            'var',
        ],
        'MAX_ROWS' => [
            1,
            'var',
        ],
        'ROW_FORMAT' => [
            1,
            'var',
        ],
        'COMMENT' => [
            1,
            'var',
        ],
        'ADD' => 1,
        'ALTER' => 1,
        'ANALYZE' => 1,
        'CHANGE' => 1,
        'CHARSET' => 1,
        'CHECK' => 1,
        'COALESCE' => 1,
        'CONVERT' => 1,
        'DEFAULT CHARSET' => 1,
        'DISABLE' => 1,
        'DISCARD' => 1,
        'DROP' => 1,
        'ENABLE' => 1,
        'IMPORT' => 1,
        'MODIFY' => 1,
        'OPTIMIZE' => 1,
        'ORDER' => 1,
        'REBUILD' => 1,
        'REMOVE' => 1,
        'RENAME' => 1,
        'REORGANIZE' => 1,
        'REPAIR' => 1,
        'UPGRADE' => 1,

        'COLUMN' => 2,
        'CONSTRAINT' => 2,
        'DEFAULT' => 2,
        'TO' => 2,
        'BY' => 2,
        'FOREIGN' => 2,
        'FULLTEXT' => 2,
        'KEY' => 2,
        'KEYS' => 2,
        'PARTITION' => 2,
        'PARTITION BY' => 2,
        'PARTITIONING' => 2,
        'PRIMARY KEY' => 2,
        'SPATIAL' => 2,
        'TABLESPACE' => 2,
        'INDEX' => 2,

        'CHARACTER SET' => 3,
    ];

    /**
     * All user options.
     *
     * @var array<string, int|array<int, int|string>>
     * @psalm-var array<string, (positive-int|array{positive-int, ('var'|'var='|'expr'|'expr=')})>
     */
    public static $USER_OPTIONS = [
        'ATTRIBUTE' => [
            1,
            'var',
        ],
        'COMMENT' => [
            1,
            'var',
        ],
        'REQUIRE' => [
            1,
            'var',
        ],
        'BY' => [
            2,
            'expr',
        ],
        'PASSWORD' => [
            2,
            'var',
        ],
        'WITH' => [
            2,
            'var',
        ],

        'ACCOUNT' => 1,
        'DEFAULT' => 1,

        'LOCK' => 2,
        'UNLOCK' => 2,

        'IDENTIFIED' => 3,
    ];

    /**
     * All view options.
     *
     * @var array<string, int|array<int, int|string>>
     * @psalm-var array<string, (positive-int|array{positive-int, ('var'|'var='|'expr'|'expr=')})>
     */
    public static $VIEW_OPTIONS = ['AS' => 1];

    /**
     * Options of this operation.
     *
     * @var OptionsArray
     */
    public $options;

    /**
     * The altered field.
     *
     * @var Expression|string|null
     */
    public $field;

    /**
     * The partitions.
     *
     * @var Component[]|ArrayObj|null
     */
    public $partitions;

    /**
     * Unparsed tokens.
     *
     * @var Token[]|string
     */
    public $unknown = [];

    /**
     * @param OptionsArray              $options    options of alter operation
     * @param Expression|string|null    $field      altered field
     * @param Component[]|ArrayObj|null $partitions partitions definition found in the operation
     * @param Token[]                   $unknown    unparsed tokens found at the end of operation
     */
    public function __construct(
        $options = null,
        $field = null,
        $partitions = null,
        $unknown = []
    ) {
        $this->partitions = $partitions;
        $this->options = $options;
        $this->field = $field;
        $this->unknown = $unknown;
    }

    /**
     * @param Parser               $parser  the parser that serves as context
     * @param TokensList           $list    the list of tokens that are being parsed
     * @param array<string, mixed> $options parameters for parsing
     *
     * @return AlterOperation
     */
    public static function parse(Parser $parser, TokensList $list, array $options = [])
    {
        $ret = new static();

        /**
         * Counts brackets.
         *
         * @var int
         */
        $brackets = 0;

        /**
         * The state of the parser.
         *
         * Below are the states of the parser.
         *
         *      0 ---------------------[ options ]---------------------> 1
         *
         *      1 ----------------------[ field ]----------------------> 2
         *
         *      1 -------------[ PARTITION / PARTITION BY ]------------> 3
         *
         *      2 -------------------------[ , ]-----------------------> 0
         *
         * @var int
         */
        $state = 0;

        /**
         * partition state.
         *
         * @var int
         */
        $partitionState = 0;

        for (; $list->idx < $list->count; ++$list->idx) {
            /**
             * Token parsed at this moment.
             */
            $token = $list->tokens[$list->idx];

            // End of statement.
            if ($token->type === Token::TYPE_DELIMITER) {
                break;
            }

            // Skipping comments.
            if ($token->type === Token::TYPE_COMMENT) {
                continue;
            }

            // Skipping whitespaces.
            if ($token->type === Token::TYPE_WHITESPACE) {
                if ($state === 2) {
                    // When parsing the unknown part, the whitespaces are
                    // included to not break anything.
                    $ret->unknown[] = $token;
                    continue;
                }
            }

            if ($state === 0) {
                $ret->options = OptionsArray::parse($parser, $list, $options);

                if ($ret->options->has('AS')) {
                    for (; $list->idx < $list->count; ++$list->idx) {
                        if ($list->tokens[$list->idx]->type === Token::TYPE_DELIMITER) {
                            break;
                        }

                        $ret->unknown[] = $list->tokens[$list->idx];
                    }

                    break;
                }

                $state = 1;
                if ($ret->options->has('PARTITION') || $token->value === 'PARTITION BY') {
                    $state = 3;
                    $list->getPrevious(); // in order to check whether it's partition or partition by.
                }
            } elseif ($state === 1) {
                $ret->field = Expression::parse(
                    $parser,
                    $list,
                    [
                        'breakOnAlias' => true,
                        'parseField' => 'column',
                    ]
                );
                if ($ret->field === null) {
                    // No field was read. We go back one token so the next
                    // iteration will parse the same token, but in state 2.
                    --$list->idx;
                }

                $state = 2;
            } elseif ($state === 2) {
                if (is_string($token->value) || is_numeric($token->value)) {
                    $arrayKey = $token->value;
                } else {
                    $arrayKey = $token->token;
                }

                if ($token->type === Token::TYPE_OPERATOR) {
                    if ($token->value === '(') {
                        ++$brackets;
                    } elseif ($token->value === ')') {
                        --$brackets;
                    } elseif (($token->value === ',') && ($brackets === 0)) {
                        break;
                    }
                } elseif (! self::checkIfTokenQuotedSymbol($token)) {
                    if (! empty(Parser::$STATEMENT_PARSERS[$token->value])) {
                        $list->idx++; // Ignore the current token
                        $nextToken = $list->getNext();

                        if ($token->value === 'SET' && $nextToken !== null && $nextToken->value === '(') {
                            // To avoid adding the tokens between the SET() parentheses to the unknown tokens
                            $list->getNextOfTypeAndValue(Token::TYPE_OPERATOR, ')');
                        } elseif ($token->value === 'SET' && $nextToken !== null && $nextToken->value === 'DEFAULT') {
                            // to avoid adding the `DEFAULT` token to the unknown tokens.
                            ++$list->idx;
                        } else {
                            // We have reached the end of ALTER operation and suddenly found
                            // a start to new statement, but have not find a delimiter between them
                            $parser->error(
                                'A new statement was found, but no delimiter between it and the previous one.',
                                $token
                            );
                            break;
                        }
                    } elseif (
                        (array_key_exists($arrayKey, self::$DB_OPTIONS)
                        || array_key_exists($arrayKey, self::$TABLE_OPTIONS))
                        && ! self::checkIfColumnDefinitionKeyword($arrayKey)
                    ) {
                        // This alter operation has finished, which means a comma
                        // was missing before start of new alter operation
                        $parser->error('Missing comma before start of a new alter operation.', $token);
                        break;
                    }
                }

                $ret->unknown[] = $token;
            } elseif ($state === 3) {
                if ($partitionState === 0) {
                        $list->idx++; // Ignore the current token
                        $nextToken = $list->getNext();
                    if (
                        ($token->type === Token::TYPE_KEYWORD)
                        && (($token->keyword === 'PARTITION BY')
                        || ($token->keyword === 'PARTITION' && $nextToken && $nextToken->value !== '('))
                    ) {
                        $partitionState = 1;
                    } elseif (($token->type === Token::TYPE_KEYWORD) && ($token->keyword === 'PARTITION')) {
                        $partitionState = 2;
                    }

                    --$list->idx; // to decrease the idx by one, because the last getNext returned and increased it.

                    // reverting the effect of the getNext
                    $list->getPrevious();
                    $list->getPrevious();

                    ++$list->idx; // to index the idx by one, because the last getPrevious returned and decreased it.
                } elseif ($partitionState === 1) {
                    // Building the expression used for partitioning.
                    if (empty($ret->field)) {
                        $ret->field = '';
                    }

                    $ret->field .= $token->type === Token::TYPE_WHITESPACE ? ' ' : $token->token;
                } elseif ($partitionState === 2) {
                    $ret->partitions = ArrayObj::parse(
                        $parser,
                        $list,
                        ['type' => PartitionDefinition::class]
                    );
                }
            }
        }

        if ($ret->options->isEmpty()) {
            $parser->error('Unrecognized alter operation.', $list->tokens[$list->idx]);
        }

        --$list->idx;

        return $ret;
    }

    /**
     * @param AlterOperation       $component the component to be built
     * @param array<string, mixed> $options   parameters for building
     *
     * @return string
     */
    public static function build($component, array $options = [])
    {
        $ret = $component->options . ' ';
        if (isset($component->field) && ($component->field !== '')) {
            $ret .= $component->field . ' ';
        }

        $ret .= TokensList::build($component->unknown);

        if (isset($component->partitions)) {
            $ret .= PartitionDefinition::build($component->partitions);
        }

        return $ret;
    }

    /**
     * Check if token's value is one of the common keywords
     * between column and table alteration
     *
     * @param string $tokenValue Value of current token
     *
     * @return bool
     */
    private static function checkIfColumnDefinitionKeyword($tokenValue)
    {
        $commonOptions = [
            'AUTO_INCREMENT',
            'COMMENT',
            'DEFAULT',
            'CHARACTER SET',
            'COLLATE',
            'PRIMARY',
            'UNIQUE',
            'PRIMARY KEY',
            'UNIQUE KEY',
        ];

        // Since these options can be used for
        // both table as well as a specific column in the table
        return in_array($tokenValue, $commonOptions);
    }

    /**
     * Check if token is symbol and quoted with backtick
     *
     * @param Token $token token to check
     *
     * @return bool
     */
    private static function checkIfTokenQuotedSymbol($token)
    {
        return $token->type === Token::TYPE_SYMBOL && $token->flags === Token::FLAG_SYMBOL_BACKTICK;
    }
}
