<?php

namespace Icinga\Less;

use Less_Environment;
use Less_Tree_Ruleset;
use Less_Tree_RulesetCall;

/**
 * Use the environment where the light mode was defined to evaluate the call
 */
class LightModeCall extends Less_Tree_RulesetCall
{
    /** @var LightMode */
    protected $lightMode;

    /**
     * @param Less_Tree_RulesetCall $c
     *
     * @return static
     */
    public static function fromRulesetCall(Less_Tree_RulesetCall $c)
    {
        return new static($c->variable);
    }

    /**
     * @return LightMode
     */
    public function getLightMode()
    {
        return $this->lightMode;
    }

    /**
     * @param LightMode $lightMode
     *
     * @return $this
     */
    public function setLightMode(LightMode $lightMode)
    {
        $this->lightMode = $lightMode;

        return $this;
    }


    /**
     * @param Less_Environment $env
     *
     * @return Less_Tree_Ruleset
     */
    public function compile($env)
    {
        return parent::compile(
            $env->copyEvalEnv(array_merge($env->frames, $this->getLightMode()->getEnv($this->variable)->frames))
        );
    }
}
