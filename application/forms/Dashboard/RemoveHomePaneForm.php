<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class RemoveHomePaneForm extends CompatForm
{
    /** @var Dashboard */
    protected $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    protected function assemble()
    {
        $this->addElement('submit', 'btn_remove', ['label' => t('Remove Home')]);
    }

    protected function onSuccess()
    {
        $requestRoute = Url::fromRequest();
        $home = $this->dashboard->getHome($requestRoute->getParam('home'));
        if ($requestRoute->getPath() === Dashboard::BASE_ROUTE . '/remove-home') {
            $this->dashboard->removeHome($home);

            Notification::success(sprintf(t('Removed dashboard home "%s" successfully'), $home->getLabel()));
        } else {
            $pane = $home->getPane($requestRoute->getParam('pane'));
            $home->removePane($pane);

            Notification::success(sprintf(t('Removed dashboard pane "%s" successfully'), $pane->getTitle()));
        }
    }
}
