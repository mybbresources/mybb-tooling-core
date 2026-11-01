#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use MyBB\Tooling\Manifest;

if ($argc < 6) {
    fwrite(STDERR,"Usage: php build-bootstrap.php VERSION KEY_ID PRIVATE_KEY_FILE TRUSTED_KEYS_JSON OUTPUT_DIRECTORY\n");
    exit(2);
}
[, $version, $keyId, $privateFile, $publicFile, $output] = $argv;
if (!preg_match('/^\d+\.\d+\.\d+$/',$version) || !preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/',$keyId)) throw new RuntimeException('Invalid toolchain version or key ID');
if (!is_file($privateFile) || !is_file($publicFile)) throw new RuntimeException('Private signing key and public trust map are required');
$secret=base64_decode(trim((string)file_get_contents($privateFile)),true);
$keys=json_decode((string)file_get_contents($publicFile),true,512,JSON_THROW_ON_ERROR);
if (!$secret || strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || !isset($keys[$keyId])) throw new RuntimeException('Invalid key material');
$derived=base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
if (!hash_equals((string)($keys[$keyId]['public_key']??''),$derived)) throw new RuntimeException('Private key does not match selected trusted public key');
if (!is_dir($output) && !mkdir($output,0755,true)) throw new RuntimeException('Cannot create output directory');

$packages=[
    'toolchain'=>[
        'slug'=>'mybb-toolchain',
        'name'=>'MyBB CLI Toolchain',
        'description'=>'Shared package manager, trusted key ring, and MyBB CLI.',
        'files'=>[['mybb-cli/bin/mybb','inc/plugins/data/mybb-tooling/bin/mybb','other']],
    ],
    'repository'=>[
        'slug'=>'mybbresources-repository',
        'name'=>'MyBB Resources Repository',
        'description'=>'Browse and manage catalog plugins in the MyBB Admin CP.',
        'files'=>[
            ['mybb-plugin-repository/inc/plugins/mybbresources_repository.php','inc/plugins/mybbresources_repository.php','plugin'],
            ['mybb-plugin-repository/admin/modules/config/mybbresources_repository.php','admin/modules/config/mybbresources_repository.php','admin'],
        ],
    ],
];
foreach(['Manifest.php','Compatibility.php','PackageManager.php','CatalogException.php','CatalogClient.php','OfficialMyBBRelease.php','OfficialMyBBArchive.php','DatabaseTools.php','PluginInventory.php','PluginLifecycle.php','OfficialMyBBUpgrade.php','CatalogPagination.php','RepositoryMetadataCache.php','TextTable.php'] as $file) {
    $packages['toolchain']['files'][]=['src/'.$file,'inc/plugins/mybb-tooling/src/'.$file,'other'];
}
$packages['toolchain']['files'][]=['autoload.php','inc/plugins/mybb-tooling/autoload.php','other'];
$packages['toolchain']['files'][]=['public-key-map','inc/plugins/mybb-tooling/config/trusted-keys.json','other'];

foreach($packages as $name=>$package) {
    $archivePath=rtrim($output,'/').'/'.($name==='toolchain'?'bootstrap':'repository').'-'.$version.'.zip';
    $zip=new ZipArchive();
    if($zip->open($archivePath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create '.$name.' bootstrap archive');
    $map=[];
    foreach($package['files'] as [$source,$destination,$role]) {
        $local=$source==='public-key-map'?$publicFile:dirname(__DIR__).'/'.$source;
        if(!is_file($local)) { $zip->close(); throw new RuntimeException('Missing bootstrap source '.$local); }
        $entry='payload/'.$destination;
        if(!$zip->addFile($local,$entry)) { $zip->close(); throw new RuntimeException('Could not add bootstrap archive entry '.$entry); }
        $map[]=['source'=>$entry,'destination'=>$destination,'role'=>$role];
    }
    if(!$zip->close()) throw new RuntimeException('Could not finalize '.$name.' bootstrap archive');
    $manifest=[
        'schema_version'=>1,'slug'=>$package['slug'],'name'=>$package['name'],'version'=>$version,
        'description'=>$package['description'],'authors'=>['MyBB Resources'],'license'=>'MIT','source'=>['type'=>'original'],
        'requirements'=>['mybb'=>'>=1.8.41 <1.9.0','php'=>'>=8.2'],
        'package'=>['url'=>'https://plugins.mybbresources.com/api/v1/plugins/'.$package['slug'].'/releases/'.$version.'/package','sha256'=>hash_file('sha256',$archivePath),'size'=>filesize($archivePath),'signature'=>'','key_id'=>$keyId],
        'files'=>$map,'uninstall'=>['remove_owned_files'=>true,'preserve_user_data'=>true],
    ];
    $manifest['package']['signature']=base64_encode(sodium_crypto_sign_detached(Manifest::unsignedPayload($manifest),$secret));
    Manifest::validate($manifest);
    Manifest::verifySignature($manifest,$keys);
    $namePrefix=$name==='toolchain'?'bootstrap':'repository';
    $manifestBytes=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    file_put_contents(rtrim($output,'/').'/'.$namePrefix.'-'.$version.'.json',$manifestBytes,LOCK_EX);
    file_put_contents(rtrim($output,'/').'/'.$namePrefix.'.json',$manifestBytes,LOCK_EX);
    copy($archivePath,rtrim($output,'/').'/'.$namePrefix.'.zip');
}
copy($publicFile,rtrim($output,'/').'/trusted-keys.json');
fwrite(STDOUT,"Created signed bootstrap packages:\n".rtrim($output,'/')."/bootstrap.zip\n".rtrim($output,'/')."/repository.zip\n".rtrim($output,'/')."/trusted-keys.json\n");
