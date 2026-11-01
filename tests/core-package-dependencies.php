<?php
declare(strict_types=1);

function coreBuild(array $command): void {
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start core package fixture.');fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0)throw new RuntimeException('Core package fixture failed: '.$out.$error);
}
function coreBundle(string $directory,string $version='0.1.0',?string $keyDirectory=null,?string $trust=null,string $keyId='core-test'): array {
    $root=dirname(__DIR__);$keyDirectory??=$directory.'/keys';
    if(!is_dir($directory))mkdir($directory,0700,true);
    if(!is_file($keyDirectory.'/ed25519-private.key'))coreBuild([PHP_BINARY,$root.'/tools/catalog-keygen.php',$keyId,$keyDirectory]);
    $trust??=$keyDirectory.'/trusted-keys.json';
    coreBuild([PHP_BINARY,$root.'/tools/build-bootstrap.php',$version,$keyId,$keyDirectory.'/ed25519-private.key',$trust,$directory.'/bundles']);
    $manifests=[];foreach(\MyBB\Tooling\BootstrapSet::PACKAGES as $prefix=>$slug)$manifests[$prefix]=json_decode(file_get_contents($directory.'/bundles/'.$prefix.'.json'),true,512,JSON_THROW_ON_ERROR);
    return [$manifests,json_decode(file_get_contents($trust),true,512,JSON_THROW_ON_ERROR)];
}
function coreSite(string $root): string {
    mkdir($root.'/inc',0700,true);file_put_contents($root.'/inc/config.php','<?php');file_put_contents($root.'/inc/config.default.php','<?php');file_put_contents($root.'/inc/class_core.php','<?php $version="1.8.41";');file_put_contents($root.'/global.php','<?php');return $root.'/inc/plugins/data/mybb-tooling';
}
function installCoreBundle(\MyBB\Tooling\PackageManager $manager,string $directory,array $manifests,?callable $onWrite=null): void {
    foreach(\MyBB\Tooling\BootstrapSet::PACKAGES as $prefix=>$slug)$manager->installBootstrap($directory.'/bundles/'.$prefix.'.zip',$manifests[$prefix],$onWrite);
}

test('signed bootstrap installs one core, separate CLI and repository, with journaled ownership',function()use($tmp){
    $dir=$tmp.'/separate-core';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';$state=coreSite($root);$writes=[];
    $manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);
    installCoreBundle($manager,$dir,$manifests,static function(string $path,string $digest)use(&$writes):void{$writes[$path]=$digest;});
    $ledger=json_decode(file_get_contents($state.'/installed.json'),true,512,JSON_THROW_ON_ERROR);
    eq(array_keys($ledger),['mybb-tooling-core','mybb-cli','mybbresources-repository']);
    eq(array_keys($ledger['mybb-cli']['files']),['inc/plugins/data/mybb-tooling/bin/mybb']);
    eq(count($ledger['mybbresources-repository']['files']),2);
    eq(isset($ledger['mybb-tooling-core']['files']['inc/plugins/mybb-tooling/config/trusted-keys.json']),true);
    eq(isset($ledger['mybb-tooling-core']['files']['inc/plugins/data/mybb-tooling/bin/mybb']),false);
    eq($ledger['mybb-cli']['dependencies'],['mybb-tooling-core'=>'>=0.1.0 <1.0.0']);
    eq($writes[$state.'/installed.json'],hash_file('sha256',$state.'/installed.json'));
    eq(\MyBB\Tooling\CoreRuntime::assertInstalled($root),'0.1.0');
    coreBuild([PHP_BINARY,$state.'/bin/mybb','core','status','--path='.$root,'--json']);
    throws(fn()=>$manager->remove('mybb-tooling-core',true,true),'dependents');
    throws(fn()=>\MyBB\Tooling\CoreRuntime::assertInstalled($root,'^0.2'),'incompatible');
    file_put_contents($root.'/inc/plugins/mybb-tooling/version.json','modified');
    throws(fn()=>\MyBB\Tooling\CoreRuntime::assertInstalled($root),'modified');
});

test('dependencies block missing or modified core and incompatible core updates before file changes',function()use($tmp){
    $dir=$tmp.'/dependency-guards';[$manifests,$keys]=coreBundle($dir);$root=$dir.'/site';$state=coreSite($root);$manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);
    throws(fn()=>$manager->install($dir.'/bundles/bootstrap.zip',$manifests['bootstrap'],false,true),'dependency');
    eq(file_exists($state),false);
    $manager->installBootstrap($dir.'/bundles/core.zip',$manifests['core']);
    installCoreBundle($manager,$dir,$manifests);
    $before=file_get_contents($state.'/installed.json');$beforeCore=hash_file('sha256',$root.'/inc/plugins/mybb-tooling/autoload.php');
    [$future]=coreBundle($dir.'/future','1.0.0',$dir.'/keys');
    throws(fn()=>$manager->install($dir.'/future/bundles/core.zip',$future['core']),'would break installed dependent');
    eq(file_get_contents($state.'/installed.json'),$before);eq(hash_file('sha256',$root.'/inc/plugins/mybb-tooling/autoload.php'),$beforeCore);
    file_put_contents($root.'/inc/plugins/mybb-tooling/autoload.php','modified');
    throws(fn()=>$manager->install($dir.'/bundles/repository.zip',$manifests['repository']),'missing or modified');
    eq(file_get_contents($state.'/installed.json'),$before);
});

test('dependency schema and reserved ownership fail closed even with force',function()use($tmp){
    [$zip,$manifest,$keys]=package();$manifest['requirements']['packages']=['mybb-tooling-core'=>'>=0.1.0'];
    throws(fn()=>\MyBB\Tooling\Manifest::validate($manifest),'schema v2');
    $manifest['schema_version']=2;\MyBB\Tooling\Manifest::validate($manifest);
    $manifest['requirements']['packages']=[$manifest['slug']=>'>=0.1.0'];
    throws(fn()=>\MyBB\Tooling\Manifest::validate($manifest),'self-referencing');unlink($zip);
    foreach(['inc/plugins/mybb-tooling/autoload.php','inc/plugins/data/mybb-tooling/installed.json','inc/plugins/data/mybb-tooling/bin/mybb'] as $index=>$path){
        $root=$tmp.'/reserved-'.$index;$state=coreSite($root);[$archive,$m,$trusted]=package(destination:$path);
        throws(fn()=>(new \MyBB\Tooling\PackageManager($root,$state,$trusted))->install($archive,$m,true),'owned');
        eq(file_exists($root.'/'.$path),false);unlink($archive);
    }
});

test('core overlap rotation updates the shared ring without replacing the CLI',function()use($tmp){
    $dir=$tmp.'/core-rotation';[$first,$old]=coreBundle($dir);$root=$dir.'/site';$state=coreSite($root);$manager=new \MyBB\Tooling\PackageManager($root,$state,$old);installCoreBundle($manager,$dir,$first);
    $cliBefore=hash_file('sha256',$state.'/bin/mybb');
    coreBuild([PHP_BINARY,dirname(__DIR__).'/tools/catalog-keygen.php','incoming-key',$dir.'/incoming']);
    $incoming=json_decode(file_get_contents($dir.'/incoming/trusted-keys.json'),true,512,JSON_THROW_ON_ERROR);
    file_put_contents($dir.'/overlap.json',json_encode($old+$incoming,JSON_THROW_ON_ERROR));
    [$next]=coreBundle($dir.'/next','0.2.0',$dir.'/keys',$dir.'/overlap.json');
    $manager->install($dir.'/next/bundles/core.zip',$next['core']);
    eq(hash_file('sha256',$state.'/bin/mybb'),$cliBefore);
    $ring=json_decode(file_get_contents($root.'/inc/plugins/mybb-tooling/config/trusted-keys.json'),true,512,JSON_THROW_ON_ERROR);
    eq($ring,$old+$incoming);eq(\MyBB\Tooling\CoreRuntime::assertInstalled($root),'0.2.0');
    [$plugin,$manifest]=package(destination:'inc/plugins/rotated.php',slug:'rotated-plugin');$manifest['package']['key_id']='incoming-key';
    $secret=base64_decode(trim(file_get_contents($dir.'/incoming/ed25519-private.key')),true);
    $manifest['package']['signature']=base64_encode(sodium_crypto_sign_detached(\MyBB\Tooling\Manifest::unsignedPayload($manifest),$secret));
    $result=(new \MyBB\Tooling\PackageManager($root,$state,$ring))->install($plugin,$manifest);
    eq($result['status'],'installed');unlink($plugin);
});

test('dependency cycles and empty dependency ownership are rejected before writes',function()use($tmp){
    $root=$tmp.'/dependency-cycle';$state=coreSite($root);mkdir($state,0700,true);
    [$zip,$m,$keys]=package(slug:'sample-plugin');$m['schema_version']=2;$m['requirements']['packages']=['other-plugin'=>'>=0.1.0'];
    $pair=sodium_crypto_sign_keypair();$keys=['test-key'=>['public_key'=>base64_encode(sodium_crypto_sign_publickey($pair))]];
    $m['package']['signature']=base64_encode(sodium_crypto_sign_detached(\MyBB\Tooling\Manifest::unsignedPayload($m),sodium_crypto_sign_secretkey($pair)));
    $path='inc/plugins/other.php';file_put_contents($root.'/'.$path,'<?php');
    $records=['other-plugin'=>['version'=>'0.1.0','dependencies'=>['sample-plugin'=>'>=0.1.0'],'files'=>[$path=>['sha256'=>hash_file('sha256',$root.'/'.$path),'role'=>'plugin']]]];
    file_put_contents($state.'/installed.json',json_encode($records));$manager=new \MyBB\Tooling\PackageManager($root,$state,$keys);
    throws(fn()=>$manager->install($zip,$m,false,true),'Cyclic');
    $records['other-plugin']['files']=[];file_put_contents($state.'/installed.json',json_encode($records));
    throws(fn()=>$manager->install($zip,$m,false,true),'dependency');eq(file_exists($root.'/inc/plugins/sample.php'),false);unlink($zip);
});
