<?php
declare(strict_types=1);

// Included by run.php; real supported transitions are checked in the SQL fixture.
test('installer rejects older and next-major upgrade sources before download or writes',function()use($tmp){
    $base=$tmp.'/upgrade-source-range';mkdir($base,0700);
    foreach(['1.8.39','1.9.0'] as $version){
        $root=$base.'/'.$version;mkdir($root.'/inc',0700,true);
        file_put_contents($root.'/inc/config.php','<?php');
        file_put_contents($root.'/global.php','<?php');
        file_put_contents($root.'/inc/class_core.php','<?php $version = '.var_export($version,true).';');
        $before=hash_file('sha256',$root.'/inc/class_core.php');
        $process=proc_open([PHP_BINARY,dirname(__DIR__).'/mybb-installer-cli/bin/mybb-install','upgrade','--path='.$root,'--backup-dir='.$base,'--dry-run','--json'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot launch upgrade refusal check.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        eq(proc_close($process),1);
        $result=json_decode($stdout,true,64,JSON_THROW_ON_ERROR);
        eq(str_contains($result['error']['message']??'','outside the supported upgrade range'),true);
        eq(str_contains($stderr,'PRE-DOWNLOAD PLAN'),false);
        eq($before,hash_file('sha256',$root.'/inc/class_core.php'));
    }
    eq(count(glob($base.'/mybb-upgrade-*')),0);
});
