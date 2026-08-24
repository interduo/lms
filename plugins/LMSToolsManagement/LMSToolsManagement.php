<?php

class LMSToolsManagement extends LMSPlugin
{
    const PLUGIN_NAME = 'LMSToolsManagement';
    const PLUGIN_DESCRIPTION = 'LMSToolsManagement';
    const PLUGIN_DOC_URL = 'https://github.com/interduo/LMSToolsManagement';
    const PLUGIN_AUTHOR = 'Jarosław Kłopotek';
    const PLUGIN_SOFTWARE_VERSION = 'build1';
    const PLUGIN_DIRECTORY = 'LMSToolsManagement';
    const PLUGIN_DIRECTORY_NAME = 'LMSToolsManagement';

    private static $purchases = null;

    public static function getToolsManagementInstance()
    {
        if (empty(self::$toolsmanagement)) {
            self::$toolsmanagement = new ToolsManagement();
        }
        return self::$toolsmanagement;
    }

    public function registerHandlers()
    {
        $this->handlers = array(
            'menu_initialized' => array(
                'class' => 'ToolsManagementInitHandler',
                'method' => 'MenuInit',
            ),
            'smarty_initialized' => array(
                'class' => 'ToolsManagementInitHandler',
                'method' => 'SmartyInit'
            ),
            'modules_dir_initialized' => array(
                'class' => 'ToolsManagementInitHandler',
                'method' => 'ModulesDirInit',
            ),
            'access_table_initialized' => array(
                'class' => 'ToolsManagementInitHandler',
                'method' => 'AccessTableInit'
            ),
        );
    }
}
