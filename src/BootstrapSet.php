<?php
declare(strict_types=1);
namespace MyBB\Tooling;
use RuntimeException;

final class BootstrapSet
{
    public const PACKAGES = ['core'=>'mybb-tooling-core','bootstrap'=>'mybb-cli','repository'=>'mybbresources-repository'];
    public static function validate(array $manifests): void
    {
        foreach(self::PACKAGES as $prefix=>$slug){
            $manifest=$manifests[$prefix]??null;
            if(!is_array($manifest))throw new RuntimeException('Missing signed bootstrap package: '.$prefix);
            Manifest::validate($manifest);
            if($manifest['schema_version']!==2||$manifest['slug']!==$slug)throw new RuntimeException('Bootstrap package identity or dependency schema does not match '.$slug);
        }
        if($manifests['core']['requirements']['packages']!==[])throw new RuntimeException('Bootstrap core cannot depend on another package.');
        foreach(['bootstrap','repository'] as $prefix){
            $dependencies=$manifests[$prefix]['requirements']['packages'];
            if(array_keys($dependencies)!==['mybb-tooling-core']||!Compatibility::satisfies($manifests['core']['version'],$dependencies['mybb-tooling-core']))throw new RuntimeException('Bootstrap requires a compatible separately owned core package.');
        }
    }
}
