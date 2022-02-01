<?php

namespace Icinga\Common;

use Icinga\Application\Icinga;
use Icinga\Authentication\Auth;
use Icinga\Exception\ProgrammingError;
use Icinga\Model\Home;
use Icinga\Model\ModuleDashlet;
use Icinga\User;
use Icinga\Web\HomeMenu;
use Icinga\Web\Navigation\DashboardHome;
use Icinga\Web\Widget\Dashboard;
use Icinga\Web\Widget\Dashboard\Dashlet;
use ipl\Sql\Expression;
use ipl\Stdlib\Filter;
use ipl\Web\Url;

trait DashboardManager
{
    /** @var User */
    private static $user;

    /**
     * A list of @see DashboardHome
     *
     * @var DashboardHome[]
     */
    private $homes = [];

    public function load()
    {
        $this->loadHomesFromMenu();

        foreach ([DashboardHome::AVAILABLE_DASHLETS, DashboardHome::SUBSCRIBABLE_DASHBOARDS] as $name) {
            if (! $this->hasHome($name)) {
                $home = new DashboardHome($name);
                $this->manageHome($home);
            }
        }

        $this->loadDashboards();
    }

    /**
     * Load homes from the navigation menu
     *
     * @return $this
     */
    public function loadHomesFromMenu()
    {
        $menu = new HomeMenu();
        /** @var DashboardHome $home */
        foreach ($menu->getItem('dashboard')->getChildren() as $home) {
            if (! $home instanceof DashboardHome) {
                continue;
            }

            $this->homes[$home->getName()] = $home;
        }

        return $this;
    }

    /**
     * Load dashboard panes belonging to the given or active home being loaded
     *
     * @param ?string $homeName
     */
    public function loadDashboards($homeName = null)
    {
        if ($homeName && $this->hasHome($homeName)) {
            $home = $this->getHome($homeName);
            $this->activateHome($home);
            $home->loadDashboards();

            return;
        }

        $requestUrl = Url::fromRequest();
        $homeParam = $requestUrl->getParam('home');
        if (empty($homeParam) || ! $this->hasHome($homeParam)) {
            $home = $this->rewindHomes();
            if (! $home) {
                return;
            }
        } else {
            $home = $this->getHome($homeParam);
        }

        $this->activateHome($home);
        $home->loadDashboards();
    }

    /**
     * Check whether the given home exists
     *
     * @param string $name
     *
     * @return bool
     */
    public function hasHome($name)
    {
        return array_key_exists($name, $this->getHomes());
    }

    /**
     * Activate the given home and set all the others to inactive
     *
     * @param DashboardHome $home
     *
     * @return $this
     */
    public function activateHome(DashboardHome $home)
    {
        $activeHome = $this->getActiveHome();
        if ($activeHome && $activeHome->getName() !== $home->getName()) {
            $activeHome->setActive(false);
        }

        $home->setActive();

        return $this;
    }

    /**
     * Get the active home that is being loaded
     *
     * @return ?DashboardHome
     */
    public function getActiveHome()
    {
        $active = null;
        foreach ($this->getHomes() as $home) {
            if ($home->getActive()) {
                $active = $home;

                break;
            }
        }

        return $active;
    }

    /**
     * Get a dashboard home by the given name
     *
     * @param string $name
     *
     * @return DashboardHome
     */
    public function getHome($name)
    {
        if (! $this->hasHome($name)) {
            throw new ProgrammingError('Trying to retrieve invalid dashboard home "%s"', $name);
        }

        return $this->homes[$name];
    }

    /**
     * Get this user's all home navigation items
     *
     * @return DashboardHome[]
     */
    public function getHomes()
    {
        return $this->homes;
    }

    /**
     * Reset the current position of the internal homes
     *
     * @return false|DashboardHome
     */
    public function rewindHomes()
    {
        $homes = array_filter($this->getHomes(), function ($home) {
            return $home->getName() !== DashboardHome::AVAILABLE_DASHLETS
                && $home->getName() !== DashboardHome::SUBSCRIBABLE_DASHBOARDS;
        });

        return reset($homes);
    }

    /**
     * Unset the given home if exists from the list
     *
     * @param  string $name
     *
     * @return $this
     */
    public function unsetHome($name)
    {
        if ($this->hasHome($name)) {
            unset($this->homes[$name]);
        }

        return $this;
    }

    /**
     * Remove the given home from the database
     *
     * @param DashboardHome|string $home
     *
     * @return $this
     */
    public function removeHome($home)
    {
        $name = $home instanceof DashboardHome ? $home->getName() : $home;
        if (! $this->hasHome($name)) {
            throw new ProgrammingError('Trying to remove invalid dashboard home "%s"', $name);
        }

        if (! $home instanceof DashboardHome) {
            $home = $this->getHome($home);
        }

        $conn = DashboardHome::getConn();
        if (! in_array($home->getName(), DashboardHome::DEFAULT_HOME_ENUMS, true)) {
            $home->removePanes();
            $conn->delete(DashboardHome::TABLE, ['id = ?' => $home->getUuid()]);
        }

        // Since the navigation menu is not loaded that fast, we need to unset
        // the just deleted home from our list as well
        $this->unsetHome($home->getName());

        return $this;
    }

    /**
     * Manage the given dashboard home
     *
     * @param DashboardHome $home
     *
     * @return $this
     */
    public function manageHome(DashboardHome $home)
    {
        $conn = DashboardHome::getConn();
        if (! $this->hasHome($home->getName()) && ! self::homePersists($home)) {
            $conn->insert(DashboardHome::TABLE, [
                'name'   => $home->getName(),
                'label'  => $home->getLabel()
            ]);

            $home->setUuid($conn->lastInsertId());
        }

        if (
            $this->hasHome($home->getName())
            // Prevent from being updated the non-editable homes
            && ! in_array($home->getName(), DashboardHome::DEFAULT_HOME_ENUMS, true)
        ) {
            $conn->update(DashboardHome::TABLE, ['label' => $home->getLabel()], ['id = ?' => $home->getUuid()]);
        }

        return $this;
    }

    /**
     * Check whether the given home exists in the DB, i.e whether it's not loaded yet
     *
     * @param DashboardHome $home
     *
     * @return bool
     */
    public static function homePersists(DashboardHome $home)
    {
        $query = Home::on(DashboardHome::getConn())
            ->columns('id')
            ->filter(Filter::equal('name', $home->getName()))->execute();

        if ($query->hasResult()) {
            $home->setUuid($query->current()->id);
        }

        return $query->hasResult();
    }

    /**
     * Return an array with home name=>label format used for comboboxes
     *
     * @param bool $onlyShared   Whether to only fetch shared dashboard names
     *
     * @return array
     */
    public function getHomeKeyNameArray(bool $onlyShared = false)
    {
        $list = [];
        foreach ($this->getHomes() as $name => $home) {
            if (
                $onlyShared
                && $home->getType() !== Dashboard::SHARED
                // User is not allowed to add new content directly to this dashboard homes
                || $home->getName() === DashboardHome::AVAILABLE_DASHLETS
                || $home->getName() === DashboardHome::SUBSCRIBABLE_DASHBOARDS
            ) {
                continue;
            }

            $list[$name] = $home->getLabel();
        }

        return $list;
    }


    /**
     * Set this dashboard's user
     *
     * @param User $user
     *
     * @return $this
     */
    public function setUser(User $user)
    {
        self::$user = $user;

        return $this;
    }

    /**
     * Get this dashboard's user
     *
     * @return User
     */
    public static function getUser()
    {
        if (self::$user === null) {
            self::$user = Auth::getInstance()->getUser();
        }

        return self::$user;
    }

    public function deployModuleDashlets()
    {
        $moduleManager = Icinga::app()->getModuleManager();
        foreach ($moduleManager->getLoadedModules() as $module) {
            foreach ($module->getDashboard() as $dashboardPane) {
                foreach ($dashboardPane->getDashlets() as $dashlet) {
                    $identifier = DashboardHome::getSHA1(
                        $module->getName() . $dashboardPane->getName() . $dashlet->getName()
                    );
                    $dashlet->setUuid($identifier);
                    $this->updateOrInsertModuleDashlet($dashlet, $module->getName());
                }
            }

            foreach ($module->getDashlet() as $dashlet) {
                $identifier = DashboardHome::getSHA1($module->getName() . $dashlet->getName());
                $dashlet->setUuid($identifier);
                $this->updateOrInsertModuleDashlet($dashlet, $module->getName());
            }
        }
    }

    /**
     * @param Dashlet $dashlet
     *
     * @return bool
     */
    public function moduleDashletExist(Dashlet $dashlet)
    {
        $query = ModuleDashlet::on(DashboardHome::getConn())->filter(Filter::equal('id', $dashlet->getUuid()));
        $query->getSelectBase()->columns(new Expression('1'));

        return $query->execute()->hasResult();
    }

    /**
     * Insert or update the given module dashlet
     *
     * @param Dashlet $dashlet
     * @param string  $module
     *
     * @return $this
     *
     * @throws \Icinga\Exception\ConfigurationError
     */
    public function updateOrInsertModuleDashlet(Dashlet $dashlet, $module)
    {
        if (! $this->moduleDashletExist($dashlet)) {
            DashboardHome::getConn()->insert('module_dashlet', [
                'id'            => $dashlet->getUuid(),
                'name'          => $dashlet->getName(),
                'label'         => $dashlet->getTitle(),
                'pane'          => $dashlet->getPane() ? $dashlet->getPane()->getName() : null,
                'module'        => $module,
                'url'           => $dashlet->getUrl()->getRelativeUrl(),
                'description'   => $dashlet->getDescription(),
                'priority'      => $dashlet->getPriority()
            ]);
        } else {
            DashboardHome::getConn()->update('module_dashlet', [
                'label'         => $dashlet->getTitle(),
                'url'           => $dashlet->getUrl()->getRelativeUrl(),
                'description'   => $dashlet->getDescription(),
                'priority'      => $dashlet->getPriority()
            ], ['id = ?' => $dashlet->getUuid()]);
        }

        return $this;
    }
}
