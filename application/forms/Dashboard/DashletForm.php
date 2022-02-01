<?php
/* Icinga Web 2 | (c) 2013 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Dashboard;

use Icinga\Application\Logger;
use Icinga\Web\Navigation\DashboardHome;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Dashboard\Dashlet;
use Icinga\Web\Widget\Dashboard\Pane;
use ipl\Html\HtmlElement;
use ipl\Web\Common\FormUid;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

/**
 * Form to add an url a dashboard pane
 */
class DashletForm extends CompatForm
{
    use FormUid;

    protected $defaultAttributes = [
        'class' => 'icinga-form icinga-controls',
        'name'  => 'form_dashboard_addurl'
    ];

    /**
     * @var Dashboard
     */
    private $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    public function hasBeenSubmitted()
    {
        return parent::hasBeenSubmitted() || $this->getPressedSubmitElement();
    }

    protected function assemble()
    {
        $panes = [];
        $homes = $this->dashboard->getHomeKeyNameArray();
        $home = Url::fromRequest()->getParam('home', current($homes));
        $populatedHome = $this->getPopulatedValue('home', $home);

        $updateDashlet = Url::fromRequest()->getPath() === Dashboard::BASE_ROUTE . '/update-dashlet';

        if ($home === $populatedHome && $this->getPopulatedValue('create_new_home') !== 'y') {
            $activeHome = $this->dashboard->getActiveHome();
            if ($activeHome && ! empty($home)) {
                $panes = $activeHome->getPaneKeyTitleArray();
            } else {
                $firstHome = $this->dashboard->rewindHomes();
                if ($firstHome) {
                    $this->dashboard->loadDashboards($firstHome->getName());
                    $panes = $firstHome->getPaneKeyTitleArray();
                }
            }
        } elseif ($this->dashboard->hasHome($populatedHome)) {
            $this->dashboard->loadDashboards($populatedHome);

            $panes = $this->dashboard->getActiveHome()->getPaneKeyTitleArray();
        }

        if (! in_array($this->getPopulatedValue('pane'), $panes)) {
            //$this->clearPopulatedValue('pane');
        }

        $this->addElement('hidden', 'org_pane', ['required'     => false]);
        $this->addElement('hidden', 'org_home', ['required'     => false]);
        $this->addElement('hidden', 'org_dashlet', ['required'  => false]);

        $this->addElement('checkbox', 'create_new_home', [
            'required'      => false,
            'class'         => 'autosubmit',
            'disabled'      => empty($homes) ?: null,
            'label'         => t('New Dashboard Home'),
            'description'   => t('Check this box if you want to add the dashboard to a new dashboard home'),
        ]);

        if (empty($homes) || $this->getValue('create_new_home') === 'y') {
            $this->getElement('create_new_home')->addAttributes(['checked' => 'checked']);

            $this->addElement('text', 'home', [
                'required'      => true,
                'label'         => t('Dashboard Home'),
                'description'   => t('Enter a title for the new dashboard home'),
            ]);
        } else {
            $this->addElement('select', 'home', [
                'required'      => true,
                'value'         => $home,
                'multiOptions'  => $homes,
                'class'         => 'autosubmit',
                'label'         => t('Dashboard Home'),
                'description'   => t('Select a home you want to add the pane to'),
            ]);
        }

        $disable = empty($panes) || $this->getPopulatedValue('create_new_home') === 'y';
        $this->addElement('checkbox', 'create_new_pane', [
            'required'      => false,
            'class'         => 'autosubmit',
            'disabled'      => $disable ?: null,
            'label'         => t('New Dashboard'),
            'description'   => t('Check this box if you want to add the dashlet to a new dashboard'),
        ]);

        if ($disable || $this->getValue('create_new_pane') === 'y') {
            $this->getElement('create_new_pane')
                ->getAttributes()
                ->registerAttributeCallback('checked', function () { return true; });

            $this->addElement('text', 'pane', [
                'required'      => true,
                'label'         => t('New Dashboard Title'),
                'description'   => t('Enter a title for the new dashboard'),
            ]);
        } else {
            $this->addElement('select', 'pane', [
                'required'      => true,
                'multiOptions'  => $panes,
                'value'         => reset($panes),
                'label'         => t('Dashboard'),
                'description'   => t('Select a dashboard you want to add the dashlet to'),
            ]);
        }

        $this->addHtml(new HtmlElement('hr'));

        $this->addElement('textarea', 'url', [
            'required'      => $this->getPopulatedValue('submit') ?: false,
            'label'         => t('Url'),
            'description'   => t(
                'Enter url to be loaded in the dashlet. You can paste the full URL, including filters'
            ),
        ]);

        $this->addElement('text', 'dashlet', [
            'required'      => $this->getPopulatedValue('submit') ?: false,
            'label'         => t('Dashlet Title'),
            'description'   => t('Enter a title for the dashlet'),
        ]);

        $url = (string) Url::fromPath(Dashboard::BASE_ROUTE . '/browse')->addParams([
            DashboardHome::HOME_PARAM => $home
        ]);

        $element = $this->createElement('submit', 'submit', ['label' => t('Add to Dashboard')]);
        $this->registerElement($element)->decorate($element);

        $this->addElement('submit', 'btn_browse', [
            'label'             => t('Browse Dashlets'),
            'href'              => $url,
            'formaction'        => $url,
           'data-icinga-modal'  => true
        ]);

        $this->getElement('btn_browse')->setWrapper($element->getWrapper());
        $this->addHtml($this->createUidElement());
    }

    /**
     * Populate form data from config
     *
     * @param Dashlet $dashlet
     */
    public function load(Dashlet $dashlet)
    {
        $home = Url::fromRequest()->getParam('home');
        $this->populate(array(
            'org_home'      => $home,
            'org_pane'      => $dashlet->getPane()->getName(),
            'pane'          => $dashlet->getPane()->getTitle(),
            'org_dashlet'   => $dashlet->getName(),
            'dashlet'       => $dashlet->getTitle(),
            'url'           => $dashlet->getUrl()->getRelativeUrl()
        ));
    }

    protected function onSuccess()
    {
        $conn = DashboardHome::getConn();
        $dashboard = $this->dashboard;

        if ($this->getPopulatedValue('btn_browse')) {
            return;
        }

        if (Url::fromRequest()->getPath() === Dashboard::BASE_ROUTE . '/new-dashlet') {
            $home = new DashboardHome($this->getValue('home'));
            if ($dashboard->hasHome($home->getName())) {
                $home = $dashboard->getHome($home->getName());
            }

            $pane = new Dashboard\Pane($this->getValue('pane'));
            if ($home->hasPane($pane->getName())) {
                $pane = $home->getPane($pane->getName());
            }

            $pane->setHome($home);
            $dashlet = new Dashlet($this->getValue('dashlet'), $this->getValue('url'), $pane);

            $conn->beginTransaction();

            try {
                $dashboard->manageHome($home);
                $home->managePanes($pane);
                $pane->manageDashlets($dashlet);

                $conn->commitTransaction();
            } catch (\Exception $err) {
                Logger::error($err);
                $conn->rollBackTransaction();

                throw $err;
            }

            Notification::success(t('Dashlet created'));
        } else {
            $orgHome = $dashboard->getHome($this->getValue('org_home'));
            $orgPane = $orgHome->getPane($this->getValue('org_pane'));
            $orgDashlet = $orgPane->getDashlet($this->getValue('org_dashlet'));

            $newHome = new DashboardHome($this->getValue('home', $orgHome->getName()));

            $newPane = new Pane($this->getValue('pane'));
            $newPane->setHome($newHome);

            $dashlet = clone $orgDashlet;
            $dashlet
                ->setPane($newPane)
                ->setUrl($this->getValue('url'))
                ->setTitle($this->getValue('dashlet'));

            if ($dashboard->hasHome($newHome->getName())) {
                $home = $dashboard->getHome($newHome->getName());
                $newHome->setUuid($home->getUuid());

                if ($this->getValue('create_new_home') !== 'y') {
                    $newHome->setLabel($home->getLabel());
                }

                $newHome->setActive(true);
                $newHome->loadDashboards();
            }

            if ($newHome->hasPane($newPane->getName())) {
                $pane = $newHome->getPane($newPane->getName());
                $newPane
                    ->setDashlets($pane->getDashlets())
                    ->setUuid($pane->getUuid());

                if ($this->getValue('create_new_pane') !== 'y') {
                    $newPane->setTitle($pane->getTitle());
                }
            }

            if ($newPane->getName() !== $orgPane->getName() && $newPane->hasDashlet($dashlet->getName())) {
                Notification::error(sprintf(
                    t('There is already a dashlet "%s" within this pane.'),
                    $dashlet->getTitle()
                ));

                return;
            }

            $paneDiff = array_filter(array_diff_assoc($newPane->toArray(), $orgPane->toArray()));
            $dashletDiff = array_filter(array_diff_assoc($dashlet->toArray(), $orgDashlet->toArray()), function ($val) {
                    return $val !== null;
                }
            );

            // Prevent meaningless updates when there weren't any changes,
            // e.g. when the user just presses the update button without changing anything
            if ($orgHome->getName() === $newHome->getName() && empty($dashletDiff) && empty($paneDiff)) {
                return;
            }

            $conn->beginTransaction();

            try {
                $dashboard->manageHome($newHome);
                $newHome->managePanes($newPane);
                $newPane->manageDashlets($dashlet, $orgPane);

                $conn->commitTransaction();
            } catch (\Exception $err) {
                Logger::error($err);
                $conn->rollBackTransaction();

                throw $err;
            }

            Notification::success(t('Updated dashlet successfully'));
        }
    }
}
