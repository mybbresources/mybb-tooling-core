<?php
/**
 * Plugin Name: MyBB Resources Repository
 * Description: Safe catalog browser and package manager for MyBB Admin CP.
 * Version: 0.1.5
 * License: MIT
 */
if (!defined('IN_MYBB')) { die('Direct initialization of this file is not allowed.'); }

function mybbresources_repository_info(): array { return ['name'=>'MyBB Resources Repository','description'=>'Browse and manage plugins from plugins.mybbresources.com.','website'=>'https://plugins.mybbresources.com','author'=>'MyBB Resources','authorsite'=>'https://plugins.mybbresources.com','version'=>'0.1.5','compatibility'=>'18*']; }
function mybbresources_repository_is_installed(): bool { return file_exists(MYBB_ROOT.'inc/plugins/data/mybbresources-repository-installed'); }
function mybbresources_repository_install(): void {
    $dir=MYBB_ROOT.'inc/plugins/data'; if(!is_dir($dir))@mkdir($dir,0750,true);
    $deny=$dir.'/.htaccess'; if(!file_exists($deny))file_put_contents($deny,"Require all denied\n");
    $index=$dir.'/index.html';if(!file_exists($index))file_put_contents($index,'');
    file_put_contents($dir.'/mybbresources-repository-installed','0.1.0');
}
function mybbresources_repository_uninstall(): void { /* Keep the package ledger; removing the management UI must not orphan its files. */ @unlink(MYBB_ROOT.'inc/plugins/data/mybbresources-repository-installed'); }
function mybbresources_repository_activate(): void { }
function mybbresources_repository_deactivate(): void { }
/** Apply MyBB's activate/deactivate callback and cache lifecycle to a verified plugin file. */
function mybbresources_repository_set_plugin_state(string $siteRoot, string $codename, bool $activate, object $cache, object $plugins): array {
    if (!class_exists('MyBB\\Tooling\\PluginLifecycle')) {
        $autoload = MYBB_ROOT . 'inc/plugins/mybb-tooling/autoload.php';
        if (!is_file($autoload)) throw new RuntimeException('Shared plugin lifecycle library is unavailable.');
        require_once $autoload;
    }
    return \MyBB\Tooling\PluginLifecycle::setState($siteRoot, $codename, $activate, $cache, $plugins);
}
function mybbresources_repository_admin_menu(array &$sub_menu): void {
    $sub_menu[]=['id'=>'mybbresources_repository','title'=>'Plugin Repository','link'=>'index.php?module=config-mybbresources_repository'];
}
function mybbresources_repository_admin_action_handler(array &$actions): void {
    $actions['mybbresources_repository']=['active'=>'mybbresources_repository','file'=>'mybbresources_repository.php'];
}
function mybbresources_repository_admin_permissions(array &$admin_permissions): void {
    $admin_permissions['mybbresources_repository']='Can manage the MyBB Resources Plugin Repository';
}
global $plugins;
if (isset($plugins) && is_object($plugins) && method_exists($plugins, 'add_hook')) {
    $plugins->add_hook('admin_config_menu', 'mybbresources_repository_admin_menu');
    $plugins->add_hook('admin_config_action_handler', 'mybbresources_repository_admin_action_handler');
    $plugins->add_hook('admin_config_permissions', 'mybbresources_repository_admin_permissions');
}
