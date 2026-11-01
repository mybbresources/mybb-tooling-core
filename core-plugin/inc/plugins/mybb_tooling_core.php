<?php
/** Shared core descriptor. Runtime loading does not depend on MyBB activation. */
if(!defined('IN_MYBB'))die('Direct initialization is not allowed.');
function mybb_tooling_core_info(): array {
    $version='0.1.0';$file=MYBB_ROOT.'inc/plugins/mybb-tooling/version.json';
    if(is_file($file)&&!is_link($file)){$metadata=json_decode((string)file_get_contents($file),true);if(is_string($metadata['version']??null)&&preg_match('/^\d+\.\d+\.\d+$/D',$metadata['version']))$version=$metadata['version'];}
    return ['name'=>'MyBB Tooling Core','description'=>'Shared dependency for the MyBB CLI and repository plugin.','website'=>'https://github.com/mybbresources/mybb-tooling-core','author'=>'MyBBResources','version'=>$version,'compatibility'=>'18*'];
}
function mybb_tooling_core_is_installed(): bool {
    try{$autoload=MYBB_ROOT.'inc/plugins/mybb-tooling/autoload.php';if(!is_file($autoload)||is_link($autoload))return false;require_once $autoload;if(!class_exists('MyBB\\Tooling\\CoreRuntime'))return false;\MyBB\Tooling\CoreRuntime::assertInstalled(MYBB_ROOT);return true;}catch(Throwable){return false;}
}
function mybb_tooling_core_install(): void { if(!mybb_tooling_core_is_installed())throw new RuntimeException('Install the signed mybb-tooling-core package first.'); }
function mybb_tooling_core_activate(): void { mybb_tooling_core_install(); }
function mybb_tooling_core_deactivate(): void { /* Dependencies use the installed runtime, not activation hooks. */ }
function mybb_tooling_core_uninstall(): void {
    $message='Remove dependent packages first, then remove mybb-tooling-core through the package manager so its dependency and ownership checks run.';
    if(function_exists('error'))error($message);
    throw new RuntimeException($message);
}
