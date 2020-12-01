<?php declare(strict_types=1);

namespace Laraplus\Data;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;

class QueryBuilder extends Builder
{
    protected $model;

    /**
     * Set a model instance.
     *
     * @param Eloquent $model
     *
     * @return $this
     */
    public function setModel(Eloquent $model)
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set the columns to be selected.
     *
     * @param array|mixed $columns
     *
     * @return $this
     * @throws \Exception
     */
    public function select($columns = ['*'])
    {
        parent::select($columns);

        $this->columns = $this->qualifyColumns($this->columns);

        return $this;
    }

    /**
     * Add a new select column to the query.
     *
     * @param array|mixed $column
     *
     * @return $this
     * @throws \Exception
     */
    public function addSelect($column)
    {
        $column = $this->qualifyColumns(is_array($column) ? $column : func_get_args());

        $this->columns = array_merge((array) $this->columns, $column);

        return $this;
    }

    /**
     * Qualify translated columns.
     *
     * @param $columns
     *
     * @return mixed
     * @throws \Exception
     */
    protected function qualifyColumns($columns)
    {
        foreach ($columns as &$column) {
            if (! in_array($column, $this->model->translatableAttributes())) {
                continue;
            }

            $primary = $this->qualifyTranslationColumn($column);
            $fallback = $this->qualifyTranslationColumn($column, true);

            if ($this->model->shouldFallback()) {
                $column = new Expression($this->compileIfNull($primary, $fallback, $column));
            } else {
                $column = $primary;
            }
        }

        return $columns;
    }

    /**
     * Add a where clause to the query.
     *
     * @param string|Closure $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return Builder|QueryBuilder
     *
     * @throws \InvalidArgumentException
     *
     * @throws \Exception
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        // Then we need to check if we are dealing with a translated column and defer
        // to the "whereTranslated" clause in that case. That way the user doesn't
        // need to worry about translated columns and let us handle the details.
        if (in_array($column, $this->model->translatableAttributes())) {
            return $this->whereTranslated($column, $operator, $value, $boolean);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add a where clause to the query and don't modify it for i18n.
     *
     * @param string|Closure $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     * @return Builder|QueryBuilder
     *
     * @throws \InvalidArgumentException
     */
    public function whereOriginal($column, $operator = null, $value = null, $boolean = 'and')
    {
        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add a translation where clause to the query.
     *
     * @param string|Closure $column
     * @param string $operator
     * @param mixed $value
     * @param string $boolean
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function whereTranslated($column, $operator = null, $value = null, $boolean = 'and')
    {
        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            [$value, $operator] = [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if (! in_array(strtolower($operator), $this->operators, true)) {
            [$value, $operator] = [$operator, '='];
        }

        $fallbackColumn = $this->qualifyTranslationColumn($column, true);
        $column = $this->qualifyTranslationColumn($column);

        // Finally we'll check whether we need to consider fallback translations. In
        // that case we need to create a complex "ifnull" clause, otherwise we can
        // just prepend the translation alias and add the where clause normally.
        if (! $this->model->shouldFallback() || $column instanceof Closure) {
            return $this->where($column, $operator, $value, $boolean);
        }

        $condition = $this->compileIfNull($column, $fallbackColumn);

        return $this->whereRaw("$condition $operator ?", [$value], $boolean);
    }

    /**
     * Add a translation or where clause to the query.
     *
     * @param string|array|Closure $column
     * @param string $operator
     * @param mixed $value
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function orWhereTranslated($column, $operator = null, $value = null)
    {
        return $this->whereTranslated($column, $operator, $value, 'or');
    }

    /**
     * Add a full sub-select to the query.
     *
     * @param string $column
     * @param Builder $query
     * @param string $boolean
     *
     * @return $this
     */
    public function whereSubQuery($column, $query, $boolean = 'and')
    {
        [$type, $operator] = ['Sub', 'in'];

        $this->wheres[] = compact('type', 'column', 'operator', 'query', 'boolean');
        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    /**
     * Add an "order by" clause by translated column to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return Builder|QueryBuilder
     *
     * @throws \Exception
     */
    public function orderBy($column, $direction = 'asc')
    {
        if (in_array($column, $this->model->translatableAttributes())) {
            return $this->orderByTranslated($column, $direction);
        }

        return parent::orderBy($column, $direction);
    }

    /**
     * Add an "order by" clause by translated column to the query.
     *
     * @param string $column
     * @param string $direction
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function orderByTranslated($column, $direction = 'asc')
    {
        $fallbackColumn = $this->qualifyTranslationColumn($column, true);
        $column = $this->qualifyTranslationColumn($column);

        if (! $this->model->shouldFallback()) {
            return $this->orderBy($column, $direction);
        }

        $condition = $this->compileIfNull($column, $fallbackColumn);

        return $this->orderByRaw("{$condition} {$direction}");
    }

    /**
     * Qualify translation column.
     *
     * @param $column
     * @param $fallback
     *
     * @return string
     */
    protected function qualifyTranslationColumn($column, $fallback = false)
    {
        $alias = $this->model->getI18nTable();
        $fallback = $fallback ? '_fallback' : '';

        if (Str::contains($column, '.')) {
            [$table, $field] = explode('.', $column);
            $suffix = $this->model->getTranslationTableSuffix();

            return Str::endsWith($alias, $suffix) ?
                "{$table}{$fallback}.{$field}" : "{$table}{$suffix}{$fallback}.{$field}";
        }

        return "{$alias}{$fallback}.{$column}";
    }

    /**
     * Compile if null.
     *
     * @param string $primary
     * @param string $fallback
     * @param string|null $alias
     *
     * @return string
     *
     * @throws \Exception
     */
    public function compileIfNull($primary, $fallback, $alias = null)
    {
        if ($this->grammar instanceof SqlServerGrammar) {
            $ifNull = 'isnull';
        } elseif ($this->grammar instanceof MySqlGrammar || $this->grammar instanceof SQLiteGrammar) {
            $ifNull = 'ifnull';
        } elseif ($this->grammar instanceof PostgresGrammar) {
            $ifNull = 'coalesce';
        } else {
            throw new \Exception('Cannot compile IFNULL statement for grammar '.get_class($this->grammar));
        }

        $primary = $this->grammar->wrap($primary);
        $fallback = $this->grammar->wrap($fallback);
        $alias = $alias ? ' as '.$this->grammar->wrap($alias) : '';

        return "{$ifNull}($primary, $fallback)".$alias;
    }

    /**
     * Get a new instance of the query builder.
     *
     * @return Builder
     */
    public function newQuery()
    {
        $query = parent::newQuery();

        return $query->setModel($this->model);
    }
}
