<?php
declare(strict_types=1);

// Monorepo development uses the canonical source directly. Standalone projects
// use their normal public VCS dependency and lockfile, without copied sources.
$root=dirname(__DIR__);
$components=array_slice($argv,1)?:['mybb-cli','mybb-installer-cli'];
foreach($components as $component){
    if(!in_array($component,['mybb-cli','mybb-installer-cli','plugins.mybbresources'],true))throw new RuntimeException('Unsupported local component.');
    $directory=$root.'/'.$component;$original=json_decode((string)file_get_contents($directory.'/composer.json'),true,512,JSON_THROW_ON_ERROR);
    $original['repositories']=[['type'=>'path','url'=>$root,'options'=>['symlink'=>true,'versions'=>['mybbresources/mybb-tooling'=>'0.1.0']]]];
    $file=$directory.'/composer.local.'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($file,json_encode($original,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    $temporaryLock=substr($file,0,-5).'.lock';$hasLock=is_file($directory.'/composer.lock');
    if($hasLock)copy($directory.'/composer.lock',$temporaryLock);
    try{
        $env=getenv();$env['COMPOSER']=basename($file);
        $command=['composer','update',...($hasLock?['mybbresources/mybb-tooling','--with-dependencies']:[]),'--no-interaction','--prefer-dist','--no-progress'];
        $process=proc_open($command,[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$directory,$env);
        if(!is_resource($process))throw new RuntimeException('Cannot start Composer.');fclose($pipes[0]);
        if(proc_close($process)!==0)throw new RuntimeException('Local component dependency installation failed.');
    }finally{@unlink($file);@unlink(substr($file,0,-5).'.lock');}
}
