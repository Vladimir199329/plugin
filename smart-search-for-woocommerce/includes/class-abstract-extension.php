<?php

defined('SE_ABSPATH') || exit;

abstract class SeAbstractExtension
{
    public function __construct()
    {
        if ($this->isActive()) {
            $priority = (int)$this->getPriority();

            foreach ($this->getHooks() as $hook) {
                $fn = str_replace('woocommerce_', '', $hook);
                $fn = str_replace('wp_', '', $fn);
                $fn = lcfirst(implode('', array_map('ucfirst', explode('_', $fn))));
    
                if (method_exists($this, $fn)) {
                    $reflect_method = new ReflectionMethod($this, $fn);
                    add_action($hook, array($this, $fn), $priority, $reflect_method->getNumberOfParameters());
                }
            }
    
            foreach ($this->getFilters() as $filter) {
                $fn = str_replace('woocommerce_', '', $filter);
                $fn = str_replace('wp_', '', $fn);
                $fn = lcfirst(implode('', array_map('ucfirst', explode('_', $fn))));
    
                if (method_exists($this, $fn)) {
                    $reflect_method = new ReflectionMethod($this, $fn);
                    add_filter($filter, array($this, $fn), $priority, $reflect_method->getNumberOfParameters());
                }
            }
        }
    }

    /**
     * Returns hooks & filters priority
     * 
     * @return int
     */
    public function getPriority()
    {
        return 10;
    }

    /**
     * Check if extension is active
     * 
     * @return boolean
     */
    abstract public function isActive();

    /**
     * Returns actions list
     * 
     * @return array
     */
    protected function getHooks()
    {
        return array();
    }

    /**
     * Returns filters list
     * 
     * @return array
     */
    protected function getFilters()
    {
        return array();
    }
}
