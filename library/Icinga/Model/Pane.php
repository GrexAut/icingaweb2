<?php

namespace Icinga\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Sql\Expression;

class Pane extends Model
{
    public function getTableName()
    {
        return 'dashboard';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'home_id',
            'name',
            'label',
            'username',
            //'priority' => new Expression('COALESCE(dashboard.priority, 0)')
        ];
    }

    public function getMetaData()
    {
        return [
            'home_id'   => t('Dashboard Home Id'),
            'name'      => t('Dashboard Name'),
            'label'     => t('Dashboard Title'),
            'username'  => t('Username'),
            //'priority'  => t('Dashboard Order Priority')
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return 'dashboard.name';
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('home', Home::class)
            ->setCandidateKey('home_id');

        $relations->hasMany('dashboard_override', DashboardOverride::class)
            ->setJoinType('LEFT');
        $relations->hasOne('dashboard_subscribable', SubscribableDashboard::class);
        $relations->hasMany('dashlet', Dashlet::class)
            ->setJoinType('LEFT');
    }
}
