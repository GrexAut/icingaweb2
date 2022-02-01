<?php

namespace Icinga\Web\Navigation;

use Icinga\Authentication\AdmissionLoader;
use Icinga\Common\Database;
use Icinga\Exception\NotFoundError;
use Icinga\Exception\ProgrammingError;
use Icinga\Model\Dashlet;
use Icinga\User;
use Icinga\Web\Widget\Dashboard\Pane;
use Icinga\Web\Widget\Dashboard;
use ipl\Orm\Query;
use ipl\Sql\Connection;
use ipl\Stdlib\Filter;
use ipl\Web\Url;

/**
 * DashboardHome loads all the panes belonging to the actually selected Home,
 *
 * along with their dashlets.
 */
class DashboardHome extends NavigationItem
{
    use Database;

    const HOME_PARAM = 'home';

    /**
     * Name of the default home
     *
     * @var string
     */
    const DEFAULT_HOME = 'Default Home';

    /**
     * A Home where all collected dashlets provided by modules are
     * being presented in a special view
     *
     * @var string
     */
    const AVAILABLE_DASHLETS = 'Available Dashlets';

    /**
     * A Home where all subscribable dashboards granted to this user's role are  displayed in a dedicated view
     *
     * @var string
     */
    const SUBSCRIBABLE_DASHBOARDS = 'Subscribable Dashboards';

    /**
     * Database table name
     *
     * @var string
     */
    const TABLE = 'dashboard_home';

    /**
     * Non-editable default homes, reserved exclusively for internal use only
     *
     * @var string[]
     */
    const DEFAULT_HOME_ENUMS = [
        self::DEFAULT_HOME,
        self::AVAILABLE_DASHLETS,
        self::SUBSCRIBABLE_DASHBOARDS
    ];

    /**
     * Shared database connection
     *
     * @var Connection
     */
    public static $conn;

    /**
     * An array of @see Pane belongs to this home
     *
     * @var Pane[]
     */
    private $panes = [];

    /**
     * A flag whether this home is disabled
     *
     * Affects only system dashboards
     *
     * @var bool
     */
    private $disabled;

    /**
     * A user this home belongs to
     *
     * @var string
     */
    private $owner;

    /**
     * This home's unique identifier
     *
     * @var int
     */
    private $uuid;

    /**
     * A type of this dashboard
     *
     * @var string
     */
    private $type = Dashboard::PRIVATE_DS;

    /**
     * Get Database connection
     *
     * This is needed because we don't want to always initiate a new DB connection when calling $this->getDb().
     * And as we are using PDO transactions to manage the dashboards, this wouldn't work if $this->getDb()
     * is called over again after a transaction has been initiated
     *
     * @return Connection
     */
    public static function getConn()
    {
        if (self::$conn === null) {
            self::$conn = (new self(self::DEFAULT_HOME))->getDb();
        }

        return self::$conn;
    }

    /**
     * Generate the sha1 hash of the provided string
     *
     * @param string $name
     *
     * @return string
     */
    public static function getSHA1($name)
    {
        return sha1($name, true);
    }

    public function init()
    {
        $this->setUrl(Url::fromPath(Dashboard::BASE_ROUTE . '/home', ['home' => $this->getName()]));
    }

    /**
     * Set whether this home is active
     *
     * DB dashboards are loaded only when this home has been activated
     *
     * @param bool $active
     *
     * @return $this
     */
    public function setActive($active = true)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get whether this home is active
     *
     * @return bool
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set the type of this home
     *
     * @param $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get the type of this home
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set this home's unique identifier
     *
     * @param  int $id
     *
     * @return $this
     */
    public function setUuid($id)
    {
        $this->uuid = (int) $id;

        return $this;
    }

    /**
     * Get this home's identifier
     *
     * @return int
     */
    public function getUuid()
    {
        return $this->uuid;
    }

    /**
     * Set the owner of this widget
     *
     * @param $owner
     *
     * @return $this
     */
    public function setOwner($owner)
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * Get the owner of this widget
     *
     * @return string
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * Get this home's dashboard panes
     *
     * @param bool $skipDisabled Whether to skip disabled panes
     *
     * @return Pane[]
     */
    public function getPanes(bool $skipDisabled = false)
    {
        // As the panes can also be added individually afterwards, it might be the case that order
        // priority gets mixed up, so we have to sort things here before being able to render them
        uasort($this->panes, function (Pane $x, Pane $y) {
            return $x->getPriority() - $y->getPriority();
        });

        return ! $skipDisabled ? $this->panes : array_filter(
            $this->panes, function ($pane) {
                return ! $pane->isDisabled();
            }
        );
    }

    /**
     * Set this home's dashboards
     *
     * @param Pane|Pane[] $panes
     */
    public function setPanes($panes)
    {
        if ($panes instanceof Pane) {
            $panes = [$panes->getName() => $panes];
        }

        $this->panes = $panes;

        return $this;
    }

    /**
     * Return the pane with the provided name
     *
     * @param string $name The name of the pane to return
     *
     * @return Pane
     * @throws ProgrammingError
     */
    public function getPane($name)
    {
        if (! $this->hasPane($name)) {
            throw new ProgrammingError('Trying to retrieve invalid dashboard pane "%s"', $name);
        }

        return $this->panes[$name];
    }

    /**
     * Checks if this home has any panes
     *
     * @return bool
     */
    public function hasPanes()
    {
        return ! empty($this->panes);
    }

    /**
     * Get whether the given pane exist
     *
     * @param string  $pane
     *
     * @return bool
     */
    public function hasPane($pane)
    {
        return array_key_exists($pane, $this->panes);
    }

    /**
     * Add a new pane to this home
     *
     * @param Pane|string $pane
     *
     * @return $this
     */
    public function addPane($pane)
    {
        if (! $pane instanceof Pane) {
            $pane = new Pane($pane);
            $pane->setTitle($pane->getName());
        }

        $pane->setHome($this);
        $this->panes[$pane->getName()] = $pane;

        return $this;
    }

    /**
     * Remove a specific pane form this home
     *
     * @param Pane|string $pane
     *
     * @return $this
     */
    public function removePane($pane)
    {
        $name = $pane instanceof Pane ? $pane->getName() : $pane;
        if (! $this->hasPane($name)) {
            throw new ProgrammingError('Trying to remove invalid dashboard pane "%s"', $name);
        }

        if (! $pane instanceof Pane) {
            $pane = $this->getPane($pane);
        }

        $conn = self::getConn();
        if (! $pane->isOverriding()) {
            $pane->removeDashlets();

            $conn->delete(Pane::TABLE, [
                'id = ?'        => $pane->getUuid(),
                'home_id = ?'   => $this->getUuid()
            ]);
        }

        return $this;
    }

    /**
     * Remove all panes from this home, unless you specified the panes
     *
     * @param Pane[] $panes
     *
     * @return $this
     */
    public function removePanes(array $panes = [])
    {
        if (empty($panes)) {
            $panes = $this->getPanes();
        }

        foreach ($panes as $pane) {
            $this->removePane($pane);
        }

        return $this;
    }

    /**
     * Manage the given pane(s)
     *
     * If you want to move the pane(s) from another to this home, you have to also bypass the origin home param
     *
     * @param Pane|Pane[]    $paneOrPanes
     * @param ?DashboardHome $origin Optional origin home the given pane(s) originates from
     *
     * @return $this
     */
    public function managePanes($paneOrPanes, DashboardHome $origin = null)
    {
        if (! is_array($paneOrPanes)) {
            $paneOrPanes = [$paneOrPanes];
        }

        $user = Dashboard::getUser();
        $conn = self::getConn();
        foreach ($paneOrPanes as $pane) {
            $paneId = self::getSHA1($user->getUsername() . $this->getName() . $pane->getName());
            if (! $pane->isOverriding()) {
                if (! $this->hasPane($pane->getName()) && (! $origin || ! $origin->hasPane($pane->getName()))) {
                    $conn->insert(Pane::TABLE, [
                        'id'        => $paneId,
                        'home_id'   => $this->getUuid(),
                        'name'      => $pane->getName(),
                        'label'     => $pane->getTitle(),
                        'username'  => $user->getUsername()
                    ]);

                    $pane->setUuid($paneId);
                } else {
                    $conn->update(Pane::TABLE, [
                        'id'        => $paneId,
                        'home_id'   => $this->getUuid(),
                        'label'     => $pane->getTitle()
                    ], ['id = ?' => $pane->getUuid()]);

                    $pane->setUuid($paneId);
                    $pane->manageDashlets($pane->getDashlets());
                }
            }
        }

        return $this;
    }

    /**
     * Get an array with pane name=>title format used for combobox
     *
     * @return array
     */
    public function getPaneKeyTitleArray()
    {
        $panes = [];
        foreach ($this->getPanes() as $pane) {
            if ($pane->isDisabled()) {
                continue;
            }

            $panes[$pane->getName()] = $pane->getTitle();
        }

        return $panes;
    }

    /**
     * Move the given dashboard pane up or down in order
     *
     * @param Pane $orgPane
     * @param int  $position
     *
     * @return $this
     */
    public function reorderPane(Pane $orgPane, $position)
    {
        if (! $this->hasPane($orgPane->getName())) {
            throw new NotFoundError('No dashboard pane called "%s" found', $orgPane->getName());
        }

        $conn = self::getConn();
        $panes = array_values($this->getPanes());
        array_splice($panes, array_search($orgPane->getName(), array_keys($this->getPanes())), 1);
        array_splice($panes, $position, 0, [$orgPane]);

        $user = Dashboard::getUser();
        foreach ($panes as $key => $pane) {
            $order = $key + 1;
            if ($pane->getPriority() < 1) {
                $conn->insert('dashboard_order', [
                    'priority'      => $order,
                    'username'      => $user->getUsername(),
                    'dashboard_id'  => $pane->getUuid()
                ]);
            } else {
                $conn->update('dashboard_order', ['priority' => $order], [
                    'username = ?'      => $user->getUsername(),
                    'dashboard_id = ?'  => $pane->getUuid()
                ]);
            }
        }

        return $this;
    }

    public function loadDashboards()
    {
        if (! $this->getActive()) {
            return;
        }

        $panes = \Icinga\Model\Pane::on(self::getConn());
        $panes->filter(Filter::equal('dashboard.home_id', $this->getUuid()));
        $panes->filter(Filter::equal('username', Dashboard::getUser()->getUsername()));

        foreach ($panes as $pane) {
            $newPane = new Pane($pane->name);
            $newPane->fromArray([
                'uuid'  => $pane->id,
                'home'  => $this,
                'title' => t($pane->label)
            ]);

            $dashlets = Dashlet::on(self::getConn());
            $dashlets->getSelectBase()->where(['dashlet.dashboard_id = ?' => $newPane->getUuid()]);

            foreach ($dashlets as $dashlet) {
                $newDashlet = new Dashboard\Dashlet($dashlet->name, $dashlet->url, $newPane);
                $newDashlet->fromArray([
                    'uuid'      => $dashlet->id,
                    'title'     => t($dashlet->label),
                    'priority'  => $dashlet->priority
                ]);

                $newPane->addDashlet($newDashlet);
            }

            $this->panes[$pane->name] = $newPane;
        }
    }

    /**
     * Get all subscribable dashboards the auth user can access to
     *
     * @param Query $query
     *
     * @return \Generator|void
     */
    public function getSubscribableDashboards(Query $query)
    {
        // Skip if this home isn't active or this name doesn't equal the preserved name
        if ($this->getName() !== self::SUBSCRIBABLE_DASHBOARDS || ! $this->getActive()) {
            return;
        }

        $authUser = Dashboard::getUser();
        $admissionLoader = new AdmissionLoader();

        $loadedUsers = [];
        foreach ($query as $dashboard) {
            $user = new User($dashboard->dashboard->username);
            if (! isset($loadedUsers[$user->getUsername()])) {
                $admissionLoader->applyRoles($user);

                $hasRole = false;
                foreach ($user->getRoles() as $role) {
                    if ($authUser->hasAssignedRole($role)) {
                        $hasRole = true;

                        break;
                    }
                }

                $loadedUsers[$user->getUsername()] = $hasRole;
            }

            if (! $loadedUsers[$user->getUsername()]) {
                continue;
            }

            $pane = new Pane($dashboard->dashboard->name);
            $pane->disable((bool) $dashboard->dashboard->dashboard_override->disabled);
            $pane->fromArray([
                'uuid'       => $dashboard->dashboard_id,
                'title'      => $dashboard->dashboard->label,
                'owner'      => $user->getUsername(),
                'acceptance' => $dashboard->dashboard->dashboard_override->acceptance,
                'home'       => $this
            ]);

            yield $pane->getName() => $pane;
        }
    }

    /**
     * @param Query $query
     *
     * @return \Generator
     */
    public function getModuleDashlets(Query $query)
    {
        // Skip if this home isn't active or this name doesn't equal the preserved name
        if ($this->getName() !== self::AVAILABLE_DASHLETS || ! $this->getActive()) {
            return;
        }

        foreach ($query as $moduleDashlet) {
            $dashlet = new Dashboard\Dashlet($moduleDashlet->name, $moduleDashlet->url);
            $dashlet->fromArray([
                'label'         => t($moduleDashlet->label),
                'description'   => t($moduleDashlet->description),
                'priority'      => $moduleDashlet->priority,
                'uuid'          => $moduleDashlet->id
            ]);

            if (($pane = $moduleDashlet->pane)) {
                $dashlet->setPane(new Pane($pane));
            }

            yield $moduleDashlet->module => $dashlet;
        }
    }
}
