<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use ipl\Html\HtmlElement;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class RemoveDashletForm extends CompatForm
{
    /** @var Dashboard */
    protected $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    protected function assemble()
    {
        $this->addHtml(HtmlElement::create('h1', null, sprintf(
            t('Please confirm removal of dashlet "%s"'),
            Url::fromRequest()->getParam('dashlet')
        )));

        $this->addElement('submit', 'remove_dashlet', ['label' => t('Remove Dashlet')]);
    }

    protected function onSuccess()
    {
        $requestRoute = Url::fromRequest();
        $dashboard = $this->dashboard;
        $home = $dashboard->getHome($requestRoute->getParam('home'));
        $pane = $home->getPane($requestRoute->getParam('pane'));

        $dashlet = $requestRoute->getParam('dashlet');
        $pane->removeDashlet($dashlet);

        Notification::success(sprintf(t('Removed dashlet "%s" successfully'), $dashlet));
    }
}
