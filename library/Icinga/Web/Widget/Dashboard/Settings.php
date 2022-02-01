<?php

namespace Icinga\Web\Widget\Dashboard;

use Icinga\Web\Widget\Dashboard;
use ipl\Web\Compat\CompatForm;

class Settings extends CompatForm
{
    /** @var Dashboard */
    protected $dashboard;

    public function __construct(Dashboard $dashboard)
    {
        $this->dashboard = $dashboard;
    }
}
