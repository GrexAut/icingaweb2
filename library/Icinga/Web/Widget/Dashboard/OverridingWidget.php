<?php

namespace Icinga\Web\Widget\Dashboard;

interface OverridingWidget
{
    /**
     * Set whether this widget overrides another widget
     *
     * @param  bool $override
     *
     * @return $this
     */
    public function override(bool $override);

    /**
     * Get whether this widget overrides another widget
     *
     * @return bool
     */
    public function isOverriding();
}
