<?php
class ToolsManagementInitHandler
{
    public function SmartyInit(Smarty $hook_data): Smarty
    {
        $template_dirs = $hook_data->getTemplateDir();
	$plugin_templates = PLUGINS_DIR . DIRECTORY_SEPARATOR . LMSToolsManagement::PLUGIN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'templates';
        array_unshift($template_dirs, $plugin_templates);
        $hook_data->setTemplateDir($template_dirs);
        return $hook_data;
    }

    public function ModulesDirInit(array $hook_data = array())
    {
        $plugin_modules = PLUGINS_DIR . DIRECTORY_SEPARATOR . LMSToolsManagement::PLUGIN_DIRECTORY_NAME . DIRECTORY_SEPARATOR . 'modules';
        array_unshift($hook_data, $plugin_modules);
        return $hook_data;
    }

    public function MenuInit(array $hook_data = array())
    {
        $menu_toolsmanagement = array(
            'ToolsManagement' => array(
                'name' => trans('ToolsManagement'),
                'css' => 'fas fa-administration',
                'tip' => trans('ToolsManagement management'),
                'accesskey' => 'e',
                'prio' => 10,
                'submenu' => array(
                    'toolsmanagement' => array(
                        'name' => trans('List'),
                        'link' => '?m=tmlist',
                        'tip' => trans('ToolsManagement'),
                        'prio' => 10,
                    ),
                ),
            ),
        );
        $menu_keys = array_keys($hook_data);
        $i = array_search('documentation', $menu_keys);

        return $hook_data = array_merge(
            array_slice($hook_data, 0, $i, true),
            $menu_toolsmanagement,
            array_slice($hook_data, $i, null, true)
        );
    }

    public function accessTableInit()
    {
        $access = AccessRights::getInstance();

        $permission = new Permission(
            'toolsmanagement',
            '[TM] Podgląd listy narzędzi',
            '^tm(list|view).*$',
            null,
            array('toolsmanagement' => array('ToolsManagement'))
        );

        $access->insertPermission($permission, AccessRights::FIRST_FORBIDDEN_PERMISSION);
    }
}
