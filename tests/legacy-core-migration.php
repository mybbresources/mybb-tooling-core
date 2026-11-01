<?php
declare(strict_types=1);

function legacyCoreSite(string $root,array $keys): array {
    $state=coreSite($root);mkdir($state.'/bin',0700,true);mkdir($root.'/inc/plugins/mybb-tooling/src',0755,true);mkdir($root.'/inc/plugins/mybb-tooling/config',0755,true);
    $contents=[
        'inc/plugins/mybb-tooling/autoload.php'=>'<?php // original combined core loader',
        'inc/plugins/mybb-tooling/src/Manifest.php'=>'<?php // original combined manifest implementation',
        'inc/plugins/mybb-tooling/config/trusted-keys.json'=>json_encode($keys,JSON_THROW_ON_ERROR),
        'inc/plugins/data/mybb-tooling/bin/mybb'=>'<?php // original combined CLI',
    ];
    $record=['version'=>'0.1.0','package_sha256'=>str_repeat('a',64),'active'=>false,'files'=>[]];
    foreach($contents as $path=>$bytes){file_put_contents($root.'/'.$path,$bytes);$record['files'][$path]=['sha256'=>hash('sha256',$bytes),'role'=>'other'];}
    $ledger=['mybb-toolchain'=>$record];file_put_contents($state.'/installed.json',json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    return [$state,$contents];
}

test('legacy combined ownership needs explicit migration and keeps modified files untouched',function()use($tmp){
    $dir=$tmp.'/legacy-dependency-migration';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';[$state,$contents]=legacyCoreSite($root,$keys);
    $manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);$before=file_get_contents($state.'/installed.json');
    throws(fn()=>$manager->install($dir.'/bundles/core.zip',$manifests['core'],true),'owned by another package');
    throws(fn()=>$manager->install($dir.'/bundles/core.zip',$manifests['core'],true,false,true),'refuses force');
    $plan=$manager->install($dir.'/bundles/core.zip',$manifests['core'],false,true,true);
    eq(count($plan['legacy_ownership_migration']),count($contents));eq(file_get_contents($state.'/installed.json'),$before);
    file_put_contents($state.'/bin/mybb','local edit');
    throws(fn()=>$manager->install($dir.'/bundles/core.zip',$manifests['core'],false,false,true),'modified files');
    eq(file_get_contents($state.'/bin/mybb'),'local edit');eq(file_get_contents($state.'/installed.json'),$before);
    file_put_contents($state.'/bin/mybb',$contents['inc/plugins/data/mybb-tooling/bin/mybb']);
    $manager->install($dir.'/bundles/core.zip',$manifests['core'],false,false,true);
    $ledger=json_decode(file_get_contents($state.'/installed.json'),true,512,JSON_THROW_ON_ERROR);
    eq(isset($ledger['mybb-toolchain']),false);eq(array_keys($ledger['mybb-cli']['files']),['inc/plugins/data/mybb-tooling/bin/mybb']);
    eq($ledger['mybb-cli']['migrated_from']['slug'],'mybb-toolchain');
    eq(file_get_contents($state.'/bin/mybb'),$contents['inc/plugins/data/mybb-tooling/bin/mybb']);
    eq(\MyBB\Tooling\CoreRuntime::assertInstalled($root),'0.1.0');
    $manager->install($dir.'/bundles/bootstrap.zip',$manifests['bootstrap']);$manager->install($dir.'/bundles/repository.zip',$manifests['repository']);
    coreBuild([PHP_BINARY,$state.'/bin/mybb','core','status','--path='.$root,'--json']);
    throws(fn()=>$manager->remove('mybb-tooling-core',true),'dependents');
});

test('failed core ownership migration restores the original combined ledger and file set',function()use($tmp){
    $dir=$tmp.'/legacy-dependency-rollback';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';[$state,$contents]=legacyCoreSite($root,$keys);$before=file_get_contents($state.'/installed.json');
    $manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);
    chmod($root.'/inc/plugins/mybb-tooling',0555);
    try{throws(fn()=>$manager->install($dir.'/bundles/core.zip',$manifests['core'],false,false,true),'rollback completed');}
    finally{chmod($root.'/inc/plugins/mybb-tooling',0755);}
    eq(file_get_contents($state.'/installed.json'),$before);
    foreach($contents as $path=>$bytes)eq(file_get_contents($root.'/'.$path),$bytes);
    eq(file_exists($root.'/inc/plugins/mybb_tooling_core.php'),false);
    eq(file_exists($root.'/inc/plugins/mybb-tooling/src/CoreRuntime.php'),false);
});

test('CLI migration previews ownership transfer and retains explicit confirmation arguments',function()use($tmp){
    $dir=$tmp.'/legacy-cli-preview';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';[$state,$contents]=legacyCoreSite($root,$keys);$before=file_get_contents($state.'/installed.json');
    $command=[PHP_BINARY,dirname(__DIR__).'/mybb-cli/bin/mybb','plugin','install',$dir.'/bundles/core.json',$dir.'/bundles/core.zip','--path='.$root,'--migrate-toolchain','--json'];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot preview CLI migration.');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);eq(proc_close($process),0);eq($error,'');
    $preview=json_decode($out,true,512,JSON_THROW_ON_ERROR);eq($preview['status'],'confirmation_required');eq(in_array('--migrate-toolchain',$preview['next_arguments'],true),true);eq(in_array('--yes',$preview['next_arguments'],true),true);
    eq(file_get_contents($state.'/installed.json'),$before);
});

test('interrupted ownership migration recovers atomically and preserves operator edits',function()use($tmp){
    $dir=$tmp.'/legacy-crash';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';[$state,$contents]=legacyCoreSite($root,$keys);$before=file_get_contents($state.'/installed.json');
    $child=$dir.'/interrupt.php';
    file_put_contents($child,'<?php require '.var_export(dirname(__DIR__).'/autoload.php',true).'; $dir=$argv[1]; $root=$argv[2]; $state=$argv[3]; $keys=json_decode(file_get_contents($dir."/keys/trusted-keys.json"),true); $manifest=json_decode(file_get_contents($dir."/bundles/core.json"),true); (new \\MyBB\\Tooling\\PackageManager($root,$state,$keys))->install($dir."/bundles/core.zip",$manifest,false,false,true,static function(){exit(73);});');
    $process=proc_open([PHP_BINARY,$child,$dir,$root,$state],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot interrupt migration.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);eq(proc_close($process),73);eq($error,'');eq($out,'');
    $journals=glob($state.'/transactions/*/core-migration.json');eq(count($journals),1);$journal=$journals[0];$manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);
    eq(file_get_contents($state.'/installed.json'),$before);eq(file_exists($root.'/inc/plugins/mybb_tooling_core.php'),true);
    $changed=$root.'/inc/plugins/mybb-tooling/src/Manifest.php';$newBytes=file_get_contents($changed);file_put_contents($changed,'operator edit');
    $result=$manager->recoverLegacyMigration($journal);eq($result['status'],'rollback_incomplete');eq(file_get_contents($changed),'operator edit');eq(file_exists($root.'/inc/plugins/mybb_tooling_core.php'),true);
    file_put_contents($changed,$newBytes);eq($manager->recoverLegacyMigration($journal,true)['status'],'dry_run');eq(file_exists($root.'/inc/plugins/mybb_tooling_core.php'),true);
    coreBuild([PHP_BINARY,dirname(__DIR__).'/mybb-cli/bin/mybb','core','recover-migration','--path='.$root,'--journal='.$journal,'--yes','--json']);
    eq(file_get_contents($state.'/installed.json'),$before);foreach($contents as $path=>$bytes)eq(file_get_contents($root.'/'.$path),$bytes);
    eq(file_exists($root.'/inc/plugins/mybb_tooling_core.php'),false);eq(file_exists($root.'/inc/plugins/mybb-tooling/src/CoreRuntime.php'),false);
    $installed=$manager->install($dir.'/bundles/core.zip',$manifests['core'],false,false,true);
    throws(fn()=>$manager->recoverLegacyMigration($installed['transaction'].'/core-migration.json'),'committed');
});
