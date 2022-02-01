<?php

namespace Icinga\Web\Control\SearchBar;

use Icinga\Web\Navigation\DashboardHome;
use ipl\Orm\Exception\InvalidColumnException;
use ipl\Orm\Model;
use ipl\Orm\Resolver;
use ipl\Sql\Cursor;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Seq;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Control\SearchBar\Suggestions;
use PDO;

class DashboardSuggestions extends Suggestions
{
    protected $model;

    /**
     * Set the model to show suggestions for
     *
     * @param string|Model $model
     *
     * @return $this
     */
    public function setModel($model)
    {
        if (is_string($model)) {
            $model = new $model();
        }

        $this->model = $model;

        return $this;
    }

    /**
     * Get the model to show suggestions for
     *
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    protected function createQuickSearchFilter($searchTerm)
    {
        $model = $this->getModel();
        $quickFilter = Filter::any();
        if (! $model) {
            return $quickFilter;
        }

        foreach ($model->getSearchColumns() as $column) {
            $where = Filter::equal($model->getTableName() . '.' . $column, $searchTerm);
            $where->metaData()->set('columnLabel', $model->getMetaData()[$column]);
            $quickFilter->add($where);
        }

        return $quickFilter;
    }

    protected function fetchValueSuggestions($column, $searchTerm, Filter\Chain $searchFilter)
    {
        $model = $this->getModel();
        $query = $model::on(DashboardHome::getConn());
        $query->limit(static::DEFAULT_LIMIT);

        if (strpos($column, ' ') !== false) {
            list($path, $_) = Seq::find(
                self::collectFilterColumns($query->getModel(), $query->getResolver()),
                $column,
                false
            );
            if ($path !== null) {
                $column = $path;
            }
        }

        $columnPath = $query->getResolver()->qualifyPath($column, $model->getTableName());
        $inputFilter = Filter::equal($columnPath, $searchTerm);
        $query->columns($columnPath);

        if ($searchFilter instanceof Filter\None) {
            $query->filter($inputFilter);
        } elseif ($searchFilter instanceof Filter\All) {
            $searchFilter->add($inputFilter);
            $searchFilter->metaData()->set('forceOptimization', true);
            $inputFilter->metaData()->set('forceOptimization', false);
        } else {
            $searchFilter = $inputFilter;
        }

        $query->filter($searchFilter);

        try {
            return (new Cursor($query->getDb(), $query->assembleSelect()->distinct()))
                ->setFetchMode(PDO::FETCH_COLUMN);
        } catch (InvalidColumnException $e) {
            throw new SearchException(sprintf(t('"%s" is not a valid column'), $e->getColumn()));
        }
    }

    protected function fetchColumnSuggestions($searchTerm)
    {
        foreach (self::collectFilterColumns($this->getModel()) as $columnName => $columnMeta) {
            yield $columnName => $columnMeta;
        }
    }

    public static function collectFilterColumns(Model $model, Resolver $resolver = null)
    {
        if ($resolver === null) {
            $resolver = new Resolver();
        }

        $metaData = $resolver->getMetaData($model);
        foreach ($metaData as $columnName => $columnMeta) {
            yield $columnName => $columnMeta;
        }
    }
}
