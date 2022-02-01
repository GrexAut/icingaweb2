<?php

namespace Icinga\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Sql\Expression;

class DashboardOverride extends Model
{
    public function getTableName()
    {
        return 'dashboard_override';
    }

    public function getKeyName()
    {
        return 'dashboard_id';
    }

    public function getColumns()
    {
        return [
            'label',
            'username',
            'disabled',
            'priority'   => new Expression('COALESCE(priority, 0)'),
            'acceptance' => new Expression('COALESCE(COUNT(dashboard_subscribable_dashboard_dashboard_override.dashboard_id), 0)')
        ];
    }

    public function getMetaData()
    {
        return ['priority' => t('Dashboard Priority Order')];
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
        $relations->belongsTo('dashboard', Pane::class);
    }
}
