<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;

/** The installed shared runtime is a package dependency, independent of activation. */
final class CoreRuntime
{
    public const PACKAGE = 'mybb-tooling-core';
    public const REQUIREMENT = '>=0.1.0 <1.0.0';

    /** Stable runtime entrypoints for this compatible core series. */
    public const CLASSES = ['Manifest','Compatibility','PackageManager','CatalogException','CatalogClient','OfficialMyBBRelease','OfficialMyBBArchive','DatabaseTools','PluginInventory','PluginLifecycle','OfficialMyBBUpgrade','CatalogPagination','RepositoryMetadataCache','TextTable','CoreRuntime','BootstrapSet','CoreMigrationRecovery'];
    public static function assertDestinationOwner(string $slug,string $path): void
    {
        $path=strtolower($path);$core=str_starts_with($path,'inc/plugins/mybb-tooling/')||$path==='inc/plugins/mybb_tooling_core.php';
        if($core&&$slug!==self::PACKAGE)throw new RuntimeException('Core runtime paths are owned only by mybb-tooling-core');
        if($slug===self::PACKAGE&&!$core)throw new RuntimeException('The core package may own only its runtime and descriptor.');
        $cli='inc/plugins/data/mybb-tooling/bin/mybb';
        if(str_starts_with($path,'inc/plugins/data/mybb-tooling/')&&!($slug==='mybb-cli'&&$path===$cli))throw new RuntimeException('Package state paths cannot be owned by a plugin package');
        if($slug==='mybb-cli'&&$path!==$cli)throw new RuntimeException('The CLI package owns only its executable.');
    }
    public static function validateManifest(array $manifest): void
    {
        if(!in_array($manifest['slug'],[self::PACKAGE,'mybb-cli','mybbresources-repository'],true))return;
        if($manifest['schema_version']!==2)throw new RuntimeException('Core, CLI and repository packages require dependency-aware manifest v2.');
        if($manifest['slug']!==self::PACKAGE){if(!isset($manifest['requirements']['packages'][self::PACKAGE]))throw new RuntimeException('This package must declare its separate core dependency.');return;}
        if($manifest['requirements']['packages']!==[])throw new RuntimeException('The standalone core cannot depend on another package.');
        $files=array_fill_keys(array_column($manifest['files'],'destination'),true);
        $required=['inc/plugins/mybb_tooling_core.php','inc/plugins/mybb-tooling/autoload.php','inc/plugins/mybb-tooling/version.json','inc/plugins/mybb-tooling/config/trusted-keys.json'];
        foreach(self::CLASSES as $class)$required[]='inc/plugins/mybb-tooling/src/'.$class.'.php';
        foreach($required as $path)if(!isset($files[$path]))throw new RuntimeException('Core package is missing a required runtime entrypoint: '.$path);
    }

    public static function assertInstalled(string $siteRoot,string $range=self::REQUIREMENT): string
    {
        $root=realpath($siteRoot);
        if($root===false)throw new RuntimeException('Cannot resolve the site for its core dependency.');
        $relative='inc/plugins/data/mybb-tooling/installed.json';
        $ledgerPath=self::path($root,$relative);
        if(!is_file($ledgerPath))throw new RuntimeException('Install the separate mybb-tooling-core package first.');
        $ledger=json_decode((string)file_get_contents($ledgerPath),true,64,JSON_THROW_ON_ERROR);
        $record=$ledger[self::PACKAGE]??null;
        if(!is_array($record)||!is_string($record['version']??null)||!Compatibility::satisfies($record['version'],$range))throw new RuntimeException('Missing or incompatible mybb-tooling-core; requires '.$range.'.');
        foreach(['inc/plugins/mybb-tooling/autoload.php','inc/plugins/mybb-tooling/version.json','inc/plugins/mybb-tooling/src/CoreRuntime.php','inc/plugins/mybb-tooling/config/trusted-keys.json'] as $required)if(!isset($record['files'][$required]['sha256']))throw new RuntimeException('Core dependency ownership record is incomplete.');
        foreach($record['files'] as $path=>$file){
            $full=self::path($root,$path);
            if(!is_file($full)||!is_string($file['sha256']??null)||!hash_equals($file['sha256'],hash_file('sha256',$full)))throw new RuntimeException('Core dependency is missing or modified: '.$path);
        }
        $metadata=json_decode((string)file_get_contents(self::path($root,'inc/plugins/mybb-tooling/version.json')),true,16,JSON_THROW_ON_ERROR);
        if(($metadata['version']??null)!==$record['version'])throw new RuntimeException('Core version metadata does not match its installed record.');
        return $record['version'];
    }

    private static function path(string $root,string $relative): string
    {
        Manifest::validateRelativePath($relative);$cursor=$root;
        foreach(explode('/',$relative) as $part){$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('Core dependency path cannot traverse a symlink.');}
        return $cursor;
    }
}
