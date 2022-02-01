<?php

namespace Icinga\Web\Widget\Dashboard;

use Icinga\Web\Widget\Dashboard;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Orm\Query;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;

/**
 * Dashboard widget which are capable of being subscribed i.e for which subscription can be made.
 */
class SubscribableDashboards extends BaseHtmlElement
{
    /** @var Dashboard */
    protected $dashboard;

    /** @var \Generator */
    protected $panes;

    protected $tag = 'table';

    protected $defaultAttributes = [
        'class'            => 'common-table table-row-selectable',
        'data-base-target' => '_next',
    ];

    public function __construct(Dashboard $dashboard, Query $query)
    {
        $this->dashboard = $dashboard;
        $this->panes = $dashboard->getActiveHome()->getSubscribableDashboards($query);
    }

    protected function assembleHeader()
    {
        $thead = HtmlElement::create('thead');
        $theadRow = HtmlElement::create('tr');

        $theadRow->addHtml(HtmlElement::create('th', null, t('Name')));
        $theadRow->addHtml(HtmlElement::create('th', null, t('Owner')));
        $theadRow->addHtml(HtmlElement::create('th', null, t('Acceptance')));
        $thead->addHtml($theadRow);

        return $thead;
    }

    protected function assembleBody()
    {
        $tbody = new HtmlElement('tbody');
        foreach ($this->panes as $pane) {
            $row = HtmlElement::create('tr');

            $row->addHtml(HtmlElement::create('td', null, $pane->getName()));
            $row->addHtml(HtmlElement::create('td', null, $pane->getOwner()));
            $row->addHtml(HtmlElement::create('td', null, $pane->getAcceptance()));
            $row->addHtml(HtmlElement::create('td', null, [
                new Link('Subscribe Dashboard', Url::fromPath(Dashboard::BASE_ROUTE . '/subscribe-dashboard')),
                new Icon('rocket')
            ]));

            $tbody->add($row);
        }

        return $tbody;
    }

    protected function assemble()
    {
        $this->addHtml($this->assembleHeader());
        $this->addHtml($this->assembleBody());
    }
}