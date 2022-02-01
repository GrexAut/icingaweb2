<?php

namespace Icinga\Web\Widget\Dashboard;

trait OrderWidget
{
    /**
     * The priority order of this widget
     *
     * @var int
     */
    private $order;

    /**
     * Set the priority order of this widget
     *
     * @param int $order
     *
     * @return $this
     */
    public function setPriority(int $order)
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Get the priority order of this widget
     *
     * @return int
     */
    public function getPriority()
    {
        return $this->order;
    }
}
