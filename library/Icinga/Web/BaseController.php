<?php

namespace Icinga\Web;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Web\Control\SearchBar\DashboardSuggestions;
use Icinga\Web\Widget\Dashboard;
use ipl\Html\Html;
use ipl\Orm\Common\SortUtil;
use ipl\Orm\Query;
use ipl\Stdlib\Contract\Paginatable;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Seq;
use ipl\Web\Compat\CompatController;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\PaginationControl;
use ipl\Web\Control\SearchBar;
use ipl\Web\Control\SearchEditor;
use ipl\Web\Control\SortControl;
use ipl\Web\Filter\QueryString;
use ipl\Web\Url;

class BaseController extends CompatController
{
    /** @var Dashboard */
    protected $dashboard;

    /** @var Filter\Rule Filter from query string parameters */
    private $filter;

    public function init()
    {
        parent::init();

        $this->dashboard = new Dashboard();
        $this->dashboard->setUser($this->Auth()->getUser());
        $this->dashboard->setTabs($this->getTabs());
        $this->dashboard->load();
    }

    /**
     * Get the filter created from query string parameters
     *
     * @return Filter\Rule
     */
    public function getFilter(): Filter\Rule
    {
        if ($this->filter === null) {
            $this->filter = QueryString::parse((string) $this->params);
        }

        return $this->filter;
    }

    /**
     * Create and return the LimitControl
     *
     * This automatically shifts the limit URL parameter from {@link $params}.
     *
     * @return LimitControl
     */
    public function createLimitControl(): LimitControl
    {
        $limitControl = new LimitControl(Url::fromRequest());
        $limitControl->setDefaultLimit($this->getPageSize(null));

        $this->params->shift($limitControl->getLimitParam());

        return $limitControl;
    }

    /**
     * Create and return the PaginationControl
     *
     * This automatically shifts the pagination URL parameters from {@link $params}.
     *
     * @return PaginationControl
     */
    public function createPaginationControl(Paginatable $paginatable): PaginationControl
    {
        $paginationControl = new PaginationControl($paginatable, Url::fromRequest());
        $paginationControl->setDefaultPageSize($this->getPageSize(null));
        $paginationControl->setAttribute('id', $this->getRequest()->protectId('pagination-control'));

        $this->params->shift($paginationControl->getPageParam());
        $this->params->shift($paginationControl->getPageSizeParam());

        return $paginationControl->apply();
    }

    /**
     * Create and return the SortControl
     *
     * This automatically shifts the sort URL parameter from {@link $params}.
     *
     * @param Query $query
     * @param array $columns Possible sort columns as sort string-label pairs
     *
     * @return SortControl
     */
    public function createSortControl(Query $query, array $columns): SortControl
    {
        $default = (array) $query->getModel()->getDefaultSort();
        $normalized = [];
        foreach ($columns as $key => $value) {
            $normalized[SortUtil::normalizeSortSpec($key)] = $value;
        }
        $sortControl = (new SortControl(Url::fromRequest()))
            ->setColumns($normalized);

        if (! empty($default)) {
            $sortControl->setDefault(SortUtil::normalizeSortSpec($default));
        }

        $sort = $sortControl->getSort();

        if (! empty($sort)) {
            $query->orderBy(SortUtil::createOrderBy($sort));
        }

        $this->params->shift($sortControl->getSortParam());

        return $sortControl;
    }

    public function createSearchBar(Query $query, array $preserveParams = null)
    {
        $requestUrl = Url::fromRequest();
        $redirectUrl = $preserveParams !== null
            ? $requestUrl->onlyWith($preserveParams)
            : (clone $requestUrl)->setParams([]);

        $filter = QueryString::fromString((string) $this->params->without('home'))
            ->on(QueryString::ON_CONDITION, function (Filter\Condition $condition) use ($query) {
                $this->enrichFilterCondition($condition, $query);
            })
            ->parse();

        $searchBar = new SearchBar();
        $searchBar->setFilter($filter);
        $searchBar->setAction($redirectUrl->getAbsoluteUrl());
        $searchBar->setIdProtector([$this->getRequest(), 'protectId']);

        if (method_exists($this, 'completeAction')) {
            $searchBar->setSuggestionUrl(Url::fromPath(
                $this->getRequest()->getControllerName() . '/complete',
                ['home' => $redirectUrl->getParam('home'), '_disableLayout' => true, 'showCompact' => true]
            ));
        }

        if (method_exists($this, 'searchEditorAction')) {
            $searchBar->setEditorUrl(Url::fromPath(
                $this->getRequest()->getControllerName() . '/search-editor'
            )->setParams($redirectUrl->getParams()));
        }

        $metaData = iterator_to_array(
            DashboardSuggestions::collectFilterColumns($query->getModel(), $query->getResolver())
        );
        $columnValidator = function (SearchBar\ValidatedColumn $column) use ($query, $metaData) {
            $columnPath = $column->getSearchValue();
            if (strpos($columnPath, '.') === false) {
                $columnPath = $query->getResolver()->qualifyPath($columnPath, $query->getModel()->getTableName());
            }

            if (! isset($metaData[$columnPath])) {
                list($columnPath, $columnLabel) = Seq::find($metaData, $column->getSearchValue(), false);
                if ($columnPath === null) {
                    $column->setMessage(t('Is not a valid column'));
                } else {
                    $column->setSearchValue($columnPath);
                    $column->setLabel($columnLabel);
                }
            } else {
                $column->setLabel($metaData[$columnPath]);
            }
        };

        $searchBar->on(SearchBar::ON_ADD, $columnValidator)
            ->on(SearchBar::ON_INSERT, $columnValidator)
            ->on(SearchBar::ON_SAVE, $columnValidator)
            ->on(SearchBar::ON_SENT, function (SearchBar $form) use ($redirectUrl) {
                $existingParams = $redirectUrl->getParams();
                $redirectUrl->setQueryString(QueryString::render($form->getFilter()));
                foreach ($existingParams->toArray(false) as $name => $value) {
                    if (is_int($name)) {
                        $name = $value;
                        $value = true;
                    }

                    $redirectUrl->getParams()->addEncoded($name, $value);
                }

                $form->setRedirectUrl($redirectUrl);
            })->on(SearchBar::ON_SUCCESS, function (SearchBar $form) {
                $this->getResponse()->redirectAndExit($form->getRedirectUrl());
            })->handleRequest(ServerRequest::fromGlobals());

        Html::tag('div', ['class' => 'filter'])->wrap($searchBar);

        return $searchBar;
    }

    public function createSearchEditor(Query $query, array $preserveParams = null)
    {
        $requestUrl = Url::fromRequest();
        $redirectUrl = Url::fromPath($this->getRequest()->getControllerName());
        if (! empty($preserveParams)) {
            $redirectUrl->setParams($requestUrl->onlyWith($preserveParams)->getParams());
        }

        $editor = new SearchEditor();
        $editor->setQueryString((string) $this->params->without($preserveParams));
        $editor->setAction($requestUrl->getAbsoluteUrl());

        if (method_exists($this, 'completeAction')) {
            $editor->setSuggestionUrl(Url::fromPath(
                $this->getRequest()->getControllerName() . '/complete',
                ['home' => $redirectUrl->getParam('home'), '_disableLayout' => true, 'showCompact' => true]
            ));
        }

        $editor->getParser()->on(QueryString::ON_CONDITION, function (Filter\Condition $condition) use ($query) {
            if ($condition->getColumn()) {
                $this->enrichFilterCondition($condition, $query);
            }
        });

        $metaData = iterator_to_array(
            DashboardSuggestions::collectFilterColumns($query->getModel(), $query->getResolver())
        );
        $editor->on(SearchEditor::ON_VALIDATE_COLUMN, function (Filter\Condition $condition) use ($query, $metaData) {
            $column = $condition->getColumn();
            if (! isset($metaData[$column])) {
                $path = Seq::findKey($metaData, $condition->metaData()->get('columnLabel', $column), false);
                if ($path === null) {
                    throw new SearchBar\SearchException(t('Is not a valid column'));
                } else {
                    $condition->setColumn($path);
                }
            }
        })->on(SearchEditor::ON_SUCCESS, function (SearchEditor $form) use ($redirectUrl) {
            $existingParams = $redirectUrl->getParams();
            $redirectUrl->setQueryString(QueryString::render($form->getFilter()));
            foreach ($existingParams->toArray(false) as $name => $value) {
                if (is_int($name)) {
                    $name = $value;
                    $value = true;
                }

                $redirectUrl->getParams()->addEncoded($name, $value);
            }

            $this->getResponse()
                ->setHeader('X-Icinga-Container', '_self')
                ->redirectAndExit($redirectUrl);
        })->handleRequest(ServerRequest::fromGlobals());

        return $editor;
    }

    protected function enrichFilterCondition(Filter\Condition $condition, Query $query)
    {
        $path = $condition->getColumn();
        if (strpos($path, '.') === false) {
            $path = $query->getResolver()->qualifyPath($path, $query->getModel()->getTableName());
            $condition->setColumn($path);
        }

        $label = Seq::findValue(
            DashboardSuggestions::collectFilterColumns($query->getModel(), $query->getResolver()),
            $path
        );
        if ($label !== null) {
            $condition->metaData()->set('columnLabel', $label);
        }
    }
}
