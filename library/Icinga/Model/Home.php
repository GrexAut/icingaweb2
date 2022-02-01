<?php

namespace Icinga\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class Home extends Model
{
    public function getTableName()
    {
        return 'dashboard_home';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'name',
            'label',
            //'priority'
        ];
    }

    public function getMetaData()
    {
        return [
            'name'      => t('Dashboard Home Name'),
            'label'     => t('Dashboard Home Title'),
            //'priority'  => t('Dashboard Order Priority')
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return 'dashboard_home.name';
    }

    public function createRelations(Relations $relations)
    {
        $relations->hasMany('dashboard', Pane::class)
            ->setForeignKey('home_id');
        $relations->hasMany('dashlet', Dashlet::class);
    }
}
