<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Web\Navigation\DashboardHome;
use Icinga\Web\Widget\Dashboard;
use ipl\Orm\Query;
use ipl\Web\Common\FormUid;
use ipl\Web\Compat\CompatForm;

class BrowseDashletForm extends CompatForm
{
    use FormUid;

    protected $defaultAttributes = [
        'class' => 'icinga-form icinga-controls',
        'name'  => 'form_browse_module_dashlets'
    ];

    /** @var Dashboard */
    protected $dashboard;

    private $dashlets;

    public function __construct(Dashboard $dashboard, Query $query)
    {
        $this->dashboard = $dashboard;

        $home = $this->dashboard->getHome(DashboardHome::AVAILABLE_DASHLETS);
        $home->setActive();
        $this->dashlets = $home->getModuleDashlets($query);
    }

    protected function assemble()
    {
        $dashlets = [];
        $urls = [];
        foreach ($this->dashlets as $dashlet) {
            $dashlets[$dashlet->getName()] = $dashlet->getTitle();
            $urls[$dashlet->getName()] = $dashlet->getUrl()->getRelativeUrl();
        }

        $this->addElement('hidden', 'create_new_home', ['required'  => false]);
        $this->addElement('hidden', 'create_new_pane', ['required'  => false]);
        $this->addElement('hidden', 'home', ['required'             => false]);
        $this->addElement('hidden', 'pane', ['required'             => false]);

        $dashlets = array_reverse($dashlets);
        $this->addElement('select', 'dashlet', [
            'required'      => true,
            'multiOptions'  => $dashlets,
            'value'         => reset($dashlets),
            'label'         => t('Dashlets'),
            'description'   => t('Select a dashlet you want to create a new one from'),
        ]);

        $this->addElement('hidden', 'url', ['required' => false, 'value' => $urls[$this->getPopulatedValue('dashlet', current($dashlets))]]);
        $this->addElement('submit', 'btn_choose', ['label' => t('Choose Dashlet')]);

        $this->addHtml($this->createUidElement());
    }
}
