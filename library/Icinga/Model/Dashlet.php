<?php

namespace Icinga\Model;

use ipl\Orm\Model;
use ipl\Orm\Relations;
use ipl\Sql\Expression;

class Dashlet extends Model
{
    public function getTableName()
    {
        return 'dashlet';
    }

    public function getKeyName()
    {
        return 'id';
    }

    public function getColumns()
    {
        return [
            'dashboard_id',
            'name',
            'label',
            'url',
            'priority' => new Expression('COALESCE(dashlet.priority, 0)')
        ];
    }

    public function getMetaData()
    {
        return [
            'dashboard_id'  => t('Dashboard Id'),
            'name'          => t('Dashlet Name'),
            'label'         => t('Dashlet Title'),
            'url'           => t('Dashlet Url'),
            'priority'      => t('Dashlet Order Priority')
        ];
    }

    public function getSearchColumns()
    {
        return ['name'];
    }

    public function getDefaultSort()
    {
        return 'dashlet.priority';
    }

    public function createRelations(Relations $relations)
    {
        $relations->belongsTo('dashboard', Pane::class);
        $relations->belongsTo('home', Home::class);
    }
}
