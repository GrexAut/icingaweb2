<?php

namespace Icinga\Web;

use Icinga\Authentication\Auth;
use Icinga\Model\Home;
use Icinga\Web\Navigation\DashboardHome;

/**
 * The entrypoint for dashboard homes
 */
class HomeMenu extends Menu
{
    protected $user;

    public function __construct()
    {
        parent::__construct();

        $this->user = Auth::getInstance()->getUser();
        $this->initHomes();
    }

    /**
     * Set up the dashboard home navigation items
     *
     * Loads currently logged-in user specific dashboards, shared and public homes from the DB
     *
     * @return void
     */
    public function initHomes()
    {
        $menuItem = $this->getItem('dashboard');
        $homes = Home::on(DashboardHome::getConn());//->utilize('dashboard');
        //$homes->getSelectBase()->groupBy('dashboard_home.id');

        foreach ($homes as $home) {
            $dashboardHome = new DashboardHome($home->name, [
                'label' => $home->label,
                'user'  => $this->user->getUsername(),
                'uuid'  => $home->id
            ]);

            $menuItem->addChild($dashboardHome);
        }
    }
}
