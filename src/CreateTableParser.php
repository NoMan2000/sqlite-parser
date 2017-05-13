<?php

namespace Maghead\SqliteParser;

use Exception;
use stdClass;



/**
 * SQLite Parser for parsing table column definitions:.
 *
 *  CREATE TABLE {identifier} ( columndef, columndef, ... );
 *
 *
 * The syntax follows the official documentation below:
 *
 *   http://www.sqlite.org/lang_createtable.html
 *
 * Unsupported:
 *
 * - Create table .. AS SELECT ...
 * - Foreign key clause actions
 */
class CreateTableParser extends BaseParser
{
    public static $intTypes = ['INT', 'INTEGER', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT', 'BIG INT', 'INT2', 'INT8'];
    public static $textTypes = ['CHARACTER', 'VARCHAR', 'VARYING CHARACTER', 'NCHAR', 'NATIVE CHARACTER', 'NVARCHAR', 'TEXT', 'BLOB', 'BINARY'];
    public static $numericTypes = ['NUMERIC', 'DECIMAL', 'BOOLEAN', 'DATE', 'DATETIME', 'TIMESTAMP'];


    public function parse($input, $offset = 0)
    {
        $this->str    = $input;
        $this->strlen = strlen($input);
        $this->p = $offset;

        $tableDef = new Table;

        $this->ignoreSpaces();

        $this->expectKeyword(['CREATE']);

        $this->ignoreSpaces();

        if ($this->tryParseKeyword(['TEMPORARY', 'TEMP'])) {
            $tableDef->temporary = true;
        }

        $this->ignoreSpaces();

        $this->expectKeyword(['TABLE']);

        $this->ignoreSpaces();

        if ($this->tryParseKeyword(['IF'])) {
            $this->expectKeyword(['NOT']);
            $this->expectKeyword(['EXISTS']);
            $tableDef->ifNotExists = true;
        }

        $tableName = $this->tryParseIdentifier();

        $tableDef->tableName = $tableName->val;

        $this->ignoreSpaces();
        $this->expect('(');
        $this->parseColumns($tableDef);
        $this->expect(')');

        return $tableDef;
    }

    protected function parseColumns(Table $tableDef)
    {
        // Parse columns
        while (!$this->metEnd()) {
            $this->ignoreSpaces();

            if ($this->looksLikeTableConstraint()) {
                break;
            }

            $identifier = $this->tryParseIdentifier();
            if (!$identifier) {
                break;
            }

            $column = new Column;
            $column->name = $identifier->val;

            $this->ignoreSpaces();
            $typeName = $this->parseTypeName();

            $this->ignoreSpaces();
            $column->type = $typeName->val;
            $precision = $this->tryParseTypePrecision();
            if ($precision && $precision->val) {
                if (count($precision->val) == 2) {
                    $column->length = $precision->val[0];
                    $column->decimals = $precision->val[1];
                } elseif (count($precision->val) == 1) {
                    $column->length = $precision->val[0];
                }
            }

            if (in_array(strtoupper($column->type), self::$intTypes)) {
                $column->unsigned = $this->consume('unsigned', 'unsigned');
            }

            while ($constraintToken = $this->tryParseColumnConstraint()) {

                if ($constraintToken->val == 'PRIMARY') {
                    $this->tryParseKeyword(['KEY']);

                    $column->primary = true;

                    if ($orderingToken = $this->tryParseKeyword(['ASC', 'DESC'])) {
                        $column->ordering = $orderingToken->val;
                    }

                    if ($this->tryParseKeyword(['AUTOINCREMENT'])) {
                        $column->autoIncrement = true;
                    }

                } else if ($constraintToken->val == 'UNIQUE') {

                    $column->unique = true;

                } else if ($constraintToken->val == 'NOT NULL') {

                    $column->notNull = true;

                } else if ($constraintToken->val == 'NULL') {

                    $column->notNull = false;

                } else if ($constraintToken->val == 'DEFAULT') {

                    // parse scalar
                    if ($scalarToken = $this->tryParseScalar()) {
                        $column->default = $scalarToken->val;
                    } elseif ($literal = $this->tryParseKeyword(['CURRENT_TIME', 'CURRENT_DATE', 'CURRENT_TIMESTAMP'], 'literal')) {
                        $column->default = $literal;
                    } elseif ($null = $this->tryParseKeyword(['NULL'])) {
                        $column->default = null;
                    } elseif ($null = $this->tryParseKeyword(['TRUE'])) {
                        $column->default = true;
                    } elseif ($null = $this->tryParseKeyword(['FALSE'])) {
                        $column->default = false;
                    } else {
                        throw new Exception("Can't parse literal: ".$this->currentWindow());
                    }

                } else if ($constraintToken->val == 'COLLATE') {

                    $collateName = $this->tryParseKeyword(['BINARY','NOCASE', 'RTRIM'], 'literal');
                    $column->collate = $collateName->val;

                } else if ($constraintToken->val == 'REFERENCES') {

                    $column->references = $this->parseReferenceClause();

                }
                $this->ignoreSpaces();
            }

            $tableDef->columns[] = $column;
            $this->ignoreSpaces();
            if ($this->metComma()) {
                $this->skipComma();
                $this->ignoreSpaces();
            }
        } // end of column parsing

        if ($tableConstraints = $this->tryParseTableConstraints()) {
            $tableDef->constraints = $tableConstraints;
        }
        $this->ignoreSpaces();
        return $tableDef;
    }

    protected function parseReferenceClause()
    {
        $tableNameToken = $this->tryParseIdentifier();

        $this->expect('(');
        $columnNames = $this->parseColumnNames();
        $this->expect(')');

        $actions = [];
        if ($this->tryParseKeyword(['ON'])) {
            while ($onToken = $this->tryParseKeyword(['DELETE', 'UPDATE'])) {
                $on = $onToken->val;
                $actionToken = $this->tryParseKeyword(['SET NULL', 'SET DEFAULT', 'CASCADE', 'RESTRICT', 'NO ACTION']);
                $actions[$on] = $actionToken->val;
            }
        }
        return (object) [
            'table' => $tableNameToken->val,
            'columns' => $columnNames,
            'actions' => $actions,
        ];
    }

    
    protected function looksLikeTableConstraint()
    {
        return $this->test(['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK']);
    }

    protected function parseColumnNames()
    {
        $columnNames = [];
        while ($identifier = $this->tryParseIdentifier()) {
            $columnNames[] = $identifier->val;
            $this->ignoreSpaces();
            if ($this->metComma()) {
                $this->skipComma();
                $this->ignoreSpaces();
            } else {
                break;
            }
        }

        return $columnNames;
    }

    protected function tryParseTableConstraints()
    {
        $tableConstraints = null;

        while (!$this->metEnd()) {
            $this->ignoreSpaces();
            $tableConstraint = new Constraint;

            if ($this->tryParseKeyword(['CONSTRAINT'])) {
                $this->ignoreSpaces();
                $constraintName = $this->tryParseIdentifier();
                if (!$constraintName) {
                    throw new Exception('Expect constraint name');
                }
                $tableConstraint->name = $constraintName->val;
            }

            $this->ignoreSpaces();
            $tableConstraintKeyword = $this->tryParseKeyword(['PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN']);

            if (!$tableConstraintKeyword) {
                if (isset($tableConstraint->name)) {
                    throw new Exception('Expect constraint type');
                }
            }

            if (in_array($tableConstraintKeyword->val, ['PRIMARY', 'FOREIGN'])) {
                $this->ignoreSpaces();
                $this->tryParseKeyword(['KEY']);
            }

            $this->ignoreSpaces();

            if ($tableConstraintKeyword->val == 'PRIMARY') {
                if ($indexColumns = $this->tryParseIndexColumns()) {
                    $tableConstraint->primaryKey = $indexColumns;
                }
            } else if ($tableConstraintKeyword->val == 'UNIQUE') {
                if ($indexColumns = $this->tryParseIndexColumns()) {
                    $tableConstraint->unique = $indexColumns;
                }
            } else if ($tableConstraintKeyword->val == 'FOREIGN') {

                $foreignKey = new stdClass;

                $this->expect('(');
                $foreignKey->columns = $this->parseColumnNames();
                $this->expect(')');

                $this->expectKeyword(['REFERENCES']);

                $foreignKey->references = $this->parseReferenceClause();

                $tableConstraint->foreignKey = $foreignKey;
            }
            $tableConstraints[] = $tableConstraint;
        }

        return $tableConstraints;
    }

    protected function tryParseColumnConstraint()
    {
        return $this->tryParseKeyword(['PRIMARY', 'UNIQUE', 'NOT NULL', 'NULL', 'DEFAULT', 'COLLATE', 'REFERENCES'], 'constraint');
    }


    protected function tryParseIndexColumns()
    {
        $this->expect('(');
        $this->ignoreSpaces();
        $indexColumns = [];
        while ($columnName = $this->tryParseIdentifier()) {
            $indexColumn = new stdClass();
            $indexColumn->name = $columnName->val;

            if ($this->tryParseKeyword(['COLLATE'])) {
                $this->ignoreSpaces();
                if ($collationName = $this->tryParseIdentifier()) {
                    $indexColumn->collationName = $collationName->val;
                }
            }

            if ($ordering = $this->tryParseKeyword(['ASC', 'DESC'])) {
                $indexColumn->ordering = $ordering->val;
            }

            $this->ignoreSpaces();
            if ($this->metComma()) {
                $this->skipComma();
            }
            $indexColumns[] = $indexColumn;
            $this->ignoreSpaces();
        }
        $this->expect(')');
        return $indexColumns;
    }

    protected function tryParseTypePrecision()
    {
        $c = $this->cur();
        if ($c == '(') {
            if (preg_match('/\( \s* (\d+) \s* , \s* (\d+) \s* \)/x', $this->str, $matches, 0, $this->p)) {
                $this->p += strlen($matches[0]);

                return new Token('precision', [intval($matches[1]), intval($matches[2])]);
            } elseif (preg_match('/\(  \s* (\d+) \s* \)/x', $this->str, $matches, 0, $this->p)) {
                $this->p += strlen($matches[0]);

                return new Token('precision', [intval($matches[1])]);

            } else {

                throw new Exception('Invalid precision syntax');
            }
        }
    }

    protected function sortKeywordsByLen(array &$keywords)
    {
        usort($keywords, function ($a, $b) {
            $al = strlen($a);
            $bl = strlen($b);
            if ($al == $bl) {
                return 0;
            } elseif ($al > $bl) {
                return -1;
            } else {
                return 1;
            }
        });
    }



    protected function parseTypeName()
    {
        $blobTypes = ['BLOB', 'NONE'];
        $realTypes = ['REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT'];
        $allTypes = array_merge(self::$intTypes, self::$textTypes, $blobTypes, $realTypes, self::$numericTypes);
        $this->sortKeywordsByLen($allTypes);
        foreach ($allTypes as $typeName) {
            // Matched
            if (($p2 = stripos($this->str, $typeName, $this->p)) !== false && $p2 == $this->p) {
                $this->p += strlen($typeName);

                return new Token('type-name', $typeName);
            }
        }
        throw new Exception('Expecting type-name');
    }

    protected function tryParseIdentifier()
    {
        $this->ignoreSpaces();
        if ($this->str[$this->p] == '`') {
            ++$this->p;
            // find the quote pair position
            $p2 = strpos($this->str, '`', $this->p);
            if ($p2 === false) {
                throw new Exception('Expecting identifier quote (`): '.$this->currentWindow());
            }
            $token = substr($this->str, $this->p, $p2 - $this->p);
            $this->p = $p2 + 1;

            return new Token('identifier', $token);
        }

        if (preg_match('/^(\w+)/', substr($this->str, $this->p), $matches)) {
            $this->p += strlen($matches[0]);

            return new Token('identifier', $matches[1]);
        }
    }

    protected function tryParseScalar()
    {
        $this->ignoreSpaces();

        if ($this->advance("'")) {

            $p = $this->p;

            while (!$this->metEnd()) {
                if ($this->advance("'")) {
                    break;
                }
                $this->advance("\\"); // skip
                $this->advance();
            }

            return new Token('string', substr($this->str, $p, ($this->p - 1) - $p));

        } else if (preg_match('/-?\d+(\.\d+)?/x', substr($this->str, $this->p), $matches)) {

            $this->p += strlen($matches[0]);

            if (isset($matches[1])) {
                return new Token('double', doubleval($matches[0]));
            }
            return new Token('int', intval($matches[0]));

        }
    }


}
