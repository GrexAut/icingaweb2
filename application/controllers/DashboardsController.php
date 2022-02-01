<?php

namespace Icinga\Controllers;

use GuzzleHttp\Psr7\ServerRequest;
use Icinga\Forms\Dashboard\BrowseDashletForm;
use Icinga\Forms\Dashboard\DashletForm;
use Icinga\Forms\Dashboard\HomePaneForm;
use Icinga\Forms\Dashboard\RemoveDashletForm;
use Icinga\Forms\Dashboard\RemoveHomePaneForm;
use Icinga\Model\ModuleDashlet;
use Icinga\Model\SubscribableDashboard;
use Icinga\Web\BaseController;
use Icinga\Web\Control\SearchBar\DashboardSuggestions;
use Icinga\Web\Navigation\DashboardHome;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Tabextension\DashboardSettings;
use ipl\Html\HtmlElement;
use ipl\Sql\Expression;
use ipl\Web\Control\LimitControl;
use ipl\Web\Control\SortControl;
use ipl\Web\Url;

class DashboardsController extends BaseController
{
    public function indexAction()
    {
        $this->createTabs();
        $activeHome = $this->dashboard->getActiveHome();
        if (! $activeHome || ! $activeHome->hasPanes()) {
            $this->getTabs()->add('dashboard', [
                'active'    => true,
                'title'     => $this->translate('Dashboard'),
                'url'       => Url::fromRequest()
            ]);
        } else {
            if (empty($activeHome->getPanes(true))) {
                $this->getTabs()->add('dashboard', [
                    'active'    => true,
                    'title'     => $this->translate('Dashboard'),
                    'url'       => Url::fromRequest()
                ]);
            } else {
                if ($this->getParam('pane')) {
                    $pane = $this->getParam('pane');
                    $this->getTabs()->activate($pane);
                }
            }
        }

        $this->content = $this->dashboard;
    }

    /**
     * Display all the dashboards belongs to a Home set in the 'home' request parameter
     *
     * If no pane is submitted, the default pane is displayed (usually the first one)
     */
    public function homeAction()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        $this->createTabs();
        $activeHome = $this->dashboard->getActiveHome();
        if ($home === DashboardHome::AVAILABLE_DASHLETS || $home === DashboardHome::SUBSCRIBABLE_DASHBOARDS) {
            $this->getTabs()->add($home, [
                'active'    => true,
                'label'     => $home,
                'url'       => Url::fromRequest()
            ]);

            if ($home === DashboardHome::AVAILABLE_DASHLETS) {
                $query = ModuleDashlet::on(DashboardHome::getConn());

                $sortParams = [
                    'module_dashlet.name'       => t('Name'),
                    'module_dashlet.pane'       => t('Dashboard Name'),
                    'module_dashlet.module'     => t('Module Name'),
                    'module_dashlet.priority'   => t('Dashlet Priority')
                ];
            } else {
                $query = SubscribableDashboard::on(DashboardHome::getConn())->with([
                    'dashboard',
                    'dashboard.dashboard_override'
                ]);
                $sortParams = [
                    'dashboard_subscribable.dashboard.name' => t('Name'),
                    'dashboard_subscribable.username'       => t('Owner')
                ];
            }

            $limitControl = $this->createLimitControl();
            $paginationControl = $this->createPaginationControl($query);
            $sortControl = $this->createSortControl($query, $sortParams);
            $searchBar = $this->createSearchBar($query, [
                DashboardHome::HOME_PARAM,
                $limitControl->getLimitParam(),
                $sortControl->getSortParam(),
            ]);

            if ($searchBar->hasBeenSent() && ! $searchBar->isValid()) {
                if ($searchBar->hasBeenSubmitted()) {
                    $filter = $this->getFilter();
                } else {
                    $this->addControl($searchBar);
                    $this->sendMultipartUpdate();
                    return;
                }
            } else {
                $filter = $searchBar->getFilter();
            }

            $query->filter($filter);

            $this->addControl($paginationControl);
            $this->addControl($sortControl);
            $this->addControl($limitControl);
            $this->addControl($searchBar);

            if ($home === DashboardHome::AVAILABLE_DASHLETS) {
                $this->addContent(new Dashboard\ProvideDashlets($activeHome->getModuleDashlets($query)));
            } else {
                $query->getSelectBase()->groupBy('dashboard_subscribable.dashboard_id');
                $this->addContent(new Dashboard\SubscribableDashboards($this->dashboard, $query));
            }
        } else {
            if (! $activeHome || empty($activeHome->getPanes(true))) {
                $this->getTabs()->add($home, [
                    'active'    => true,
                    'title'     => $this->translate($this->getParam(DashboardHome::HOME_PARAM)),
                    'url'       => Url::fromRequest()
                ]);
            }

            if ($activeHome && $this->getParam('pane')) {
                $pane = $this->getParam('pane');
                $this->dashboard->activate($pane);
            }

            $this->content = $this->dashboard;
        }
    }

    public function editHomeAction()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        $this->getTabs()->add('remove-home', [
            'active'    => true,
            'title'     => $this->translate('Update Home'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $homeForm = new HomePaneForm($this->dashboard);
        $homeForm->on(HomePaneForm::ON_SUCCESS, function () use ($home) {
            $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE . '/settings')->addParams([
                DashboardHome::HOME_PARAM => $home
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $homeForm->load($this->dashboard->getActiveHome());
        $this->addContent($homeForm);
    }

    public function removeHomeAction()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        $this->getTabs()->add('remove-home', [
            'active'    => true,
            'label'     => $this->translate('Remove Home'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $homeForm = new RemoveHomePaneForm($this->dashboard);
        $homeForm->populate(['org_name' => $home]);
        $homeForm
            ->setAction((string) Url::fromRequest())
            ->on(RemoveHomePaneForm::ON_SUCCESS, function () {
                $firstHome = $this->dashboard->rewindHomes();
                $urlParam = $firstHome ? [DashboardHome::HOME_PARAM => $firstHome->getName()] : [];

                $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE . '/home')->addParams($urlParam));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $homeForm->prependHtml(HtmlElement::create('h1', null, sprintf(
            t('Please confirm removal of dashboard home "%s"'),
            $home
        )));
        $this->addContent($homeForm);
    }

    public function editPaneAction()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        $pane = $this->params->getRequired('pane');

        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        if (! $this->dashboard->getActiveHome()->hasPane($pane)) {
            $this->httpNotFound(sprintf($this->translate('Pane "%s" not found'), $pane));
        }

        $this->getTabs()->add('update-pane', [
            'active'    => true,
            'title'     => $this->translate('Update Pane'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $paneForm = new HomePaneForm($this->dashboard);
        $paneForm->on(HomePaneForm::ON_SUCCESS, function () use ($paneForm, $home) {
            if ($this->dashboard->hasHome($paneForm->getValue(DashboardHome::HOME_PARAM))) {
                $home = $paneForm->getValue('home');
            }

            $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE)->addParams([DashboardHome::HOME_PARAM => $home]));
        })->handleRequest(ServerRequest::fromGlobals());

        $paneForm->getElement('btn_update')->setLabel(t('Update Pane'));
        $paneForm->load($this->dashboard->getActiveHome()->getPane($pane));
        $this->addContent($paneForm);
    }

    public function removePaneAction()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        $paneParam = $this->params->getRequired('pane');

        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        if (! $this->dashboard->getActiveHome()->hasPane($paneParam)) {
            $this->httpNotFound(sprintf($this->translate('Pane "%s" not found'), $paneParam));
        }

        $this->getTabs()->add('remove-pane', [
            'active'    => true,
            'label'     => $this->translate('Remove Pane'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $paneForm = new RemoveHomePaneForm($this->dashboard);
        $paneForm->populate(['org_name' => $paneParam]);
        $paneForm->on(RemoveHomePaneForm::ON_SUCCESS, function () use ($home) {
            $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE . '/home')->addParams([
                DashboardHome::HOME_PARAM => $home
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $paneForm->getElement('btn_remove')->setLabel(t('Remove Pane'));
        $paneForm->prependHtml(HtmlElement::create('h1', null, sprintf(
            t('Please confirm removal of dashboard pane "%s"'),
            $paneParam
        )));

        $this->addContent($paneForm);
    }

    public function subscribeDashboardAction()
    {

    }

    public function newDashletAction()
    {
        $this->getTabs()->add('new-dashlet', [
            'active'    => true,
            'label'     => $this->translate('New Dashlet'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $dashletForm = new DashletForm($this->dashboard);
        $dashletForm->populate($this->getRequest()->getPost());
        $dashletForm->on(DashletForm::ON_SUCCESS, function () use ($dashletForm) {
            $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE . '/home')->addParams([
                DashboardHome::HOME_PARAM   => $dashletForm->getValue('home'),
                'pane'                      => $dashletForm->getValue('pane')
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        if ($this->getParam('url')) {
            $params = $this->getAllParams();
            $params['url'] = rawurldecode($this->getParam('url'));
            $dashletForm->populate($params);
        }

        $dashletForm->prependHtml(HtmlElement::create('h1', null, t('Add Dashlet To Dashboard')));

        $this->addContent($dashletForm);
    }

    public function editDashletAction()
    {
        $pane = $this->validateDashletParams();
        $dashlet = $pane->getDashlet($this->getParam('dashlet'));

        $this->getTabs()->add('edit-dashlet', [
            'active'    => true,
            'label'     => $this->translate('Update Dashlet'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $dashletForm = new DashletForm($this->dashboard);
        $dashletForm->on(DashletForm::ON_SUCCESS, function () use ($dashletForm, $pane) {
            $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE)->addParams([
                'home'  => $dashletForm->getPopulatedValue('home', $this->getParam(DashboardHome::HOME_PARAM)),
                'pane'  => $dashletForm->getValue('pane', $pane->getName())
            ]));
        })->handleRequest(ServerRequest::fromGlobals());

        $dashletForm->prependHtml(HtmlElement::create('h1', null, t('Edit Dashlet')));

        $dashletForm->load($dashlet);
        $this->addContent($dashletForm);
    }

    public function removeDashletAction()
    {
        $this->validateDashletParams();
        $this->getTabs()->add('remove-dashlet', [
            'active'    => true,
            'label'     => $this->translate('Remove Dashlet'),
            'url'       => Url::fromRequest()
        ])->disableLegacyExtensions();

        $home = $this->getParam(DashboardHome::HOME_PARAM);
        $removeForm = (new RemoveDashletForm($this->dashboard))
            ->on(RemoveDashletForm::ON_SUCCESS, function () use ($home) {
                $this->redirectNow(Url::fromPath(Dashboard::BASE_ROUTE . '/home')->addParams([
                    DashboardHome::HOME_PARAM => $home
                ]));
            })
            ->handleRequest(ServerRequest::fromGlobals());

        $this->addContent($removeForm);
    }

    public function browseAction()
    {
        $this->setTitle(t('Browse Dashlet'));
        $dashlets = ModuleDashlet::on(DashboardHome::getConn());
        $form = new BrowseDashletForm($this->dashboard, $dashlets);
        $form->populate($this->getRequest()->getPost());
        $form->handleRequest(ServerRequest::fromGlobals());

        $this->addContent($form);
    }

    public function settingsAction()
    {
        $this->createTabs();
        $settingsForm = new Dashboard\Settings($this->dashboard);
        $settingsForm->on(Dashboard\Settings::ON_SUCCESS, function () {
            $this->redirectNow(Url::fromRequest());
        })->handleRequest(ServerRequest::fromGlobals());

        //$this->addControl($controlForm);
        $this->addContent($settingsForm);
    }

    public function completeAction()
    {
        if ($this->getParam(DashboardHome::HOME_PARAM) === DashboardHome::SUBSCRIBABLE_DASHBOARDS) {
            $model = SubscribableDashboard::class;
        } else {
            $model = ModuleDashlet::class;
        }

        $suggestions = new DashboardSuggestions();
        $suggestions->setModel($model);
        $suggestions->forRequest(ServerRequest::fromGlobals());
        $this->getDocument()->add($suggestions);
    }

    public function searchEditorAction()
    {
        if ($this->getParam(DashboardHome::HOME_PARAM) === DashboardHome::SUBSCRIBABLE_DASHBOARDS) {
            $query = SubscribableDashboard::on(DashboardHome::getConn());
        } else {
            $query = ModuleDashlet::on(DashboardHome::getConn());
        }

        $editor = $this->createSearchEditor($query, [
            DashboardHome::HOME_PARAM,
            LimitControl::DEFAULT_LIMIT_PARAM,
            SortControl::DEFAULT_SORT_PARAM,
        ]);

        $this->getDocument()->add($editor);
        $this->setTitle(t('Adjust Filter'));
    }

    /**
     * Create tab aggregation
     */
    private function createTabs()
    {
        $homeParam = $this->getParam(DashboardHome::HOME_PARAM);
        if (
            $this->dashboard->hasHome($homeParam)
            && $homeParam !== DashboardHome::AVAILABLE_DASHLETS
            && $homeParam !== DashboardHome::SUBSCRIBABLE_DASHBOARDS
        ) {
            $home = $this->dashboard->getHome($homeParam);
        } else {
            $home = $this->dashboard->rewindHomes();
        }

        $urlParam = [];
        if (! empty($home)) {
            $urlParam = ['home' => $home->getName()];
        }

        return $this->dashboard->getTabs()->extend(new DashboardSettings($urlParam));
    }

    private function validateDashletParams()
    {
        $home = $this->params->getRequired(DashboardHome::HOME_PARAM);
        $pane = $this->params->getRequired('pane');
        $dashlet = $this->params->getRequired('dashlet');

        if (! $this->dashboard->hasHome($home)) {
            $this->httpNotFound(sprintf($this->translate('Home "%s" not found'), $home));
        }

        if (! $this->dashboard->getActiveHome()->hasPane($pane)) {
            $this->httpNotFound(sprintf($this->translate('Pane "%s" not found'), $pane));
        }

        $pane = $this->dashboard->getActiveHome()->getPane($pane);

        if (! $pane->hasDashlet($dashlet)) {
            $this->httpNotFound(sprintf($this->translate('Dashlet "%s" not found'), $dashlet));
        }

        return $pane;
    }
}
