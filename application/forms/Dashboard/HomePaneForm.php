<?php

namespace Icinga\Forms\Dashboard;

use Icinga\Application\Logger;
use Icinga\Web\Navigation\DashboardHome;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Dashboard\Pane;
use ipl\Web\Compat\CompatForm;
use ipl\Web\Url;

class HomePaneForm extends CompatForm
{
    /** @var Dashboard */
    protected $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }

    /**
     * Populate form data from config
     *
     * @param Pane|DashboardHome  $paneOrHome
     */
    public function load($paneOrHome)
    {
        if (Url::fromRequest()->getPath() === Dashboard::BASE_ROUTE . '/edit-home') {
            $title = $paneOrHome->getLabel();
        } else {
            $title = $paneOrHome->getTitle();
        }

        $this->populate([
            'org_title' => $title,
            'title'     => $title,
            'org_name'  => $paneOrHome->getName()
        ]);
    }

    protected function assemble()
    {
        $requestUrl = Url::fromRequest();
        $activeHome = $this->dashboard->getActiveHome();
        $populated = $this->getPopulatedValue('home', $activeHome->getName());

        $this->addElement('hidden', 'org_name', ['required' => false]);
        $this->addElement('hidden', 'org_title', ['required' => false]);

        $titleDesc = t('Edit the title of this dashboard home');
        if ($requestUrl->getPath() === Dashboard::BASE_ROUTE . '/edit-pane') {
            $dashboardHomes = $this->dashboard->getHomeKeyNameArray();
            $titleDesc = t('Edit the title of this dashboard pane');

            $this->addElement('checkbox', 'create_new_home', [
                'required'      => false,
                'class'         => 'autosubmit',
                'disabled'      => empty($dashboardHomes) ?: null,
                'label'         => t('New Dashboard Home'),
                'description'   => t('Check this box if you want to move the pane to a new dashboard home.'),
            ]);

            if (empty($dashboardHomes) || $this->getPopulatedValue('create_new_home') === 'y') {
                $this->getElement('create_new_home')->addAttributes(['checked' => 'checked']);

                $this->addElement('text', 'home', [
                    'required'      => true,
                    'label'         => t('Dashboard Home'),
                    'description'   => t('Enter a title for the new dashboard home you want to move this dashboard to.'),
                ]);
            } else {
                $this->addElement('select', 'home', [
                    'required'      => true,
                    'class'         => 'autosubmit',
                    'value'         => $populated,
                    'multiOptions'  => $dashboardHomes,
                    'label'         => t('Move to home'),
                    'description'   => t('Select a dashboard home you want to move this dashboard to'),
                ]);
            }
        }

        $this->addElement('text', 'title', [
            'required'      => true,
            'label'         => t('Title'),
            'description'   => $titleDesc
        ]);

        $this->addElement('submit', 'btn_update', ['label' => t('Update Home')]);
    }

    protected function onSuccess()
    {
        if (Url::fromRequest()->getPath() === Dashboard::BASE_ROUTE . '/edit-pane') {
            $orgHome = $this->dashboard->getActiveHome();
            $conn = DashboardHome::getConn();
            $conn->beginTransaction();

            try {
                if ($this->getPopulatedValue('create_new_home') === 'y') {
                    $home = new DashboardHome($this->getValue('home'));

                    $this->dashboard->manageHome($home);
                } else {
                    $home = $this->dashboard->getHome($this->getValue('home'));
                }

                $pane = $orgHome->getPane($this->getValue('org_name'));
                $pane->setTitle($this->getValue('title'));

                if ($orgHome->getName() !== $home->getName() && $this->getPopulatedValue('create_new_home') !== 'y') {
                    $home
                        ->setActive(true)
                        ->loadDashboards();
                    if ($home->hasPane($this->getValue('org_name'))) {
                        Notification::error(sprintf(t('Dashboard "%s" already exist within this home'), $pane->getName()));

                        return;
                    }

                    $pane->setHome($home);
                }

                $home->managePanes($pane, $orgHome);

                $conn->commitTransaction();
            } catch (\Exception $err) {
                Logger::error($err);
                $conn->rollBackTransaction();
            }

            Notification::success(sprintf(t('Updated dashboard pane "%s" successfully'), $pane->getTitle()));
        } else {
            $home = $this->dashboard->getHome($this->getValue('org_name'));
            $orgLabel = $home->getLabel();
            $home->setLabel($this->getValue('title'));

            $this->dashboard->manageHome($home);

            Notification::success(sprintf(
                t('Renamed dashboard home from "%s" to "%s" successfully'),
                $orgLabel,
                $home->getLabel()
            ));
        }
    }
}
