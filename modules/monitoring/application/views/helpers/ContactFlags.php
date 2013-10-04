<?php

class Zend_View_Helper_ContactFlags extends Zend_View_Helper_Abstract
{

    /**
     * Get the human readable flag name for the given contact notification option
     *
     * @param string $tableName the name of the option table
     */
    public function getNotificationOptionName($tableName) {
        $exploded = explode('_', $tableName);
        $name = end($exploded);
        return ucfirst($name);
    }

    /**
     * Build all active notification options to a readable string
     *
     * @param object $contact   The contact retrieved from a backend
     * @param string $type      Whether to display the flags for 'host' or 'service'
     * @param string $glue      The symbol to use to concatenate the flag names
     *
     * @return string   A string that contains a human readable list of active options
     */
    public function contactFlags($contact, $type, $glue = ', ')
    {
        $out = array();
        foreach ($contact as $key => $value) {
            if (preg_match('/^contact_notify_' . $type . '_.*/', $key) && $value == True) {
                $option = $this->getNotificationOptionName($key);
                if (strtolower($option) != 'timeperiod') {
                    array_push($out, $option);
                }
            }
        }
        return implode($glue, $out);
    }
}

