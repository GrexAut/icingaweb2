<?php

namespace Icinga\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;

class SubscribableDashboard extends Model
{
    public function getTableName()
    {
        return 'dashboard_subscribable';
    }

    public function getKeyName()
    {
        return 'dashboard_id';
    }

    public function getColumns()
    {
        return [ ];
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('dashboard', Pane::class);
    }
}
