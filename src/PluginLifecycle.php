<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;

/** Runs MyBB's plugin callbacks and updates its canonical plugin cache. */
final class PluginLifecycle
{
    /** Returns null when MyBB's active-plugin cache is not available or well formed. */
    public static function activeState(object $cache, string $codename): ?bool
    {
        $cacheData=$cache->read('plugins');
        if(!is_array($cacheData)||!is_array($cacheData['active']??null))return null;
        return isset($cacheData['active'][$codename]);
    }

    /** Refuse file removal unless each managed plugin entry is confirmed inactive. */
    public static function assertInactive(array $record, object $cache, string $guidance = 'deactivate it before uninstalling.'): void
    {
        $codenames=[];
        foreach(($record['files']??[]) as $path=>$file)if(is_array($file)&&($file['role']??'')==='plugin'&&preg_match('#^inc/plugins/([A-Za-z0-9_]+)\.php$#',(string)$path,$match))$codenames[]=$match[1];
        if(!$codenames)return;
        if(($record['active']??false)===true)throw new RuntimeException('Plugin is recorded as active; '.$guidance);
        foreach($codenames as $codename){$active=self::activeState($cache,$codename);if($active===null)throw new RuntimeException('MyBB activation state is unavailable; '.$guidance);if($active)throw new RuntimeException('Plugin is active in MyBB; '.$guidance);}
    }

    public static function setState(string $siteRoot, string $codename, bool $activate, object $cache, object $plugins): array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $codename)) throw new RuntimeException('Plugin file name is not a valid MyBB plugin codename.');
        $root = realpath($siteRoot);
        if ($root === false) throw new RuntimeException('Cannot resolve MyBB site root.');
        $file = $root . '/inc/plugins/' . $codename . '.php';
        if (is_link($file) || !is_file($file)) throw new RuntimeException('Managed plugin file is missing or unsafe.');

        $cacheData = $cache->read('plugins');
        if (!is_array($cacheData)) $cacheData = [];
        $active = $cacheData['active'] ?? [];
        if (!is_array($active)) throw new RuntimeException('MyBB plugin activation cache is unavailable or malformed.');
        $isActive = isset($active[$codename]);
        if ($isActive === $activate) return ['status'=>'unchanged','active'=>$isActive,'installed_now'=>false];

        $action = $activate ? 'activate' : 'deactivate';
        $plugins->run_hooks('admin_config_plugins_' . $action);
        require_once $file;
        $installedNow = false;
        if ($activate) {
            if ($plugins->is_compatible($codename) === false) throw new RuntimeException('Plugin is incompatible with this MyBB version.');
            $installedFunction = $codename . '_is_installed';
            $installed = !function_exists($installedFunction) || $installedFunction() === true;
            $installFunction = $codename . '_install';
            if (!$installed && function_exists($installFunction)) { call_user_func($installFunction); $installedNow = true; }
            $activateFunction = $codename . '_activate';
            if (function_exists($activateFunction)) call_user_func($activateFunction);
            $active[$codename] = $codename;
        } else {
            $deactivateFunction = $codename . '_deactivate';
            if (function_exists($deactivateFunction)) call_user_func($deactivateFunction);
            unset($active[$codename]);
        }
        $cacheData['active'] = $active;
        $cache->update('plugins', $cacheData);
        $plugins->run_hooks('admin_config_plugins_' . $action . '_commit');
        return ['status'=>$action . 'd','active'=>$activate,'installed_now'=>$installedNow];
    }
}
