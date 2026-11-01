<?php
declare(strict_types=1);
namespace MyBB\Tooling;
use RuntimeException;

/** Durable intent for the explicit legacy-to-core ownership transition. */
final class CoreMigrationRecovery
{
    public static function begin(string $root,string $state,string $transaction,array $oldLedger,array $manifest,array $files,array $obsolete): array
    {
        $path=$transaction.'/core-migration.json';$bytes=(string)file_get_contents($state.'/installed.json');
        if(json_decode($bytes,true,64,JSON_THROW_ON_ERROR)!==$oldLedger)throw new RuntimeException('Legacy ledger changed before migration journaling.');
        $plan=[];
        foreach($files as $file)$plan[$file['destination']]=['old_sha256'=>$file['existing_sha256'],'new_sha256'=>$file['sha256']];
        foreach($obsolete as $relative)$plan[$relative]=['old_sha256'=>$oldLedger['mybb-toolchain']['files'][$relative]['sha256'],'new_sha256'=>null];
        foreach($plan as $relative=>&$entry)$entry['old_mode']=$entry['old_sha256']===null?null:(fileperms(self::path($root,$relative))&0777);unset($entry);
        $journal=['schema_version'=>1,'kind'=>'legacy-core-migration','site'=>$root,'state'=>realpath($state),'status'=>'applying','manifest'=>$manifest,'old_ledger'=>base64_encode($bytes),'ledger_digests'=>[hash('sha256',$bytes)],'files'=>$plan];
        self::save($path,$journal);return ['path'=>$path,'data'=>$journal];
    }
    public static function save(string $path,array $journal): void
    {
        $temporary=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$mask=umask(0077);
        try{$handle=fopen($temporary,'x');}finally{umask($mask);}
        if($handle===false)throw new RuntimeException('Cannot create private migration journal.');
        try{$bytes=json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(fwrite($handle,$bytes)!==strlen($bytes)||!fflush($handle))throw new RuntimeException('Cannot persist migration journal.');}finally{fclose($handle);}
        if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Cannot publish migration journal.');}
    }
    public static function recover(string $root,string $state,string $path,array $keys,bool $dryRun): array
    {
        $root=realpath($root);$state=realpath($state);$resolved=realpath($path);
        if($root===false||$state===false||$resolved===false||is_link($path)||!str_starts_with($resolved,$state.'/transactions/')||basename($resolved)!=='core-migration.json')throw new RuntimeException('Recovery requires a journal in this site package transaction directory.');
        $path=self::path($state,substr($resolved,strlen($state)+1));$transaction=dirname($path);
        $journal=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if(($journal['schema_version']??null)!==1||($journal['kind']??null)!=='legacy-core-migration'||($journal['site']??null)!==$root||($journal['state']??null)!==$state)throw new RuntimeException('Migration journal does not belong to this site.');
        if(($journal['status']??null)==='committed'||file_exists($transaction.'/COMMITTED'))throw new RuntimeException('Migration is committed; use a new package update instead of rollback.');
        $manifest=$journal['manifest']??[];Manifest::validate($manifest);Manifest::verifySignature($manifest,$keys);
        if($manifest['schema_version']!==2||$manifest['slug']!=='mybb-tooling-core')throw new RuntimeException('Migration journal has no signed core-package intent.');
        $old=base64_decode((string)($journal['old_ledger']??''),true);$oldLedger=$old===false?null:json_decode($old,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($oldLedger)||!is_array($oldLedger['mybb-toolchain']['files']??null)||!is_array($journal['files']??null))throw new RuntimeException('Migration recovery metadata is invalid.');
        $ledgerPath=self::path($state,'installed.json');
        if(!is_file($ledgerPath)||!in_array(hash_file('sha256',$ledgerPath),$journal['ledger_digests']??[],true))throw new RuntimeException('Installed ledger changed after migration; preserve it for operator review.');
        $declared=array_fill_keys(array_column($manifest['files'],'destination'),true);$failures=[];$actions=[];
        $expected=$declared;foreach($oldLedger['mybb-toolchain']['files'] as $relative=>$file)if(str_starts_with($relative,'inc/plugins/mybb-tooling/'))$expected[$relative]=true;
        $expectedPaths=array_keys($expected);$actualPaths=array_keys($journal['files']);sort($expectedPaths);sort($actualPaths);
        if($expectedPaths!==$actualPaths)throw new RuntimeException('Recovery intent omits or adds core file paths.');
        foreach($journal['files'] as $relative=>$entry){
            if(!(str_starts_with($relative,'inc/plugins/mybb-tooling/')||$relative==='inc/plugins/mybb_tooling_core.php'))throw new RuntimeException('Recovery intent contains a non-core destination.');
            $before=$entry['old_sha256']??null;$after=$entry['new_sha256']??null;
            if(($after!==null&&(!isset($declared[$relative])||!is_string($after)||!preg_match('/^[a-f0-9]{64}$/D',$after)))||($after===null&&isset($declared[$relative])))throw new RuntimeException('Recovery intent does not match the signed file map.');
            if($before!==null&&($oldLedger['mybb-toolchain']['files'][$relative]['sha256']??null)!==$before)throw new RuntimeException('Recovery backup digest does not match legacy ownership.');
            $full=self::path($root,$relative);$current=is_file($full)?hash_file('sha256',$full):null;
            if(file_exists($full)&&!is_file($full)){$failures[]=$relative;continue;}
            if($current===$before)continue;
            if($current!==$after){$failures[]=$relative;continue;}
            $backup=null;
            if($before!==null){$backup=self::path($transaction,'backup/'.$relative);if(!is_file($backup)||!hash_equals($before,hash_file('sha256',$backup))){$failures[]=$relative;continue;}}
            $actions[$relative]=['backup'=>$backup,'sha256'=>$before,'mode'=>$entry['old_mode']??0644];
        }
        if($failures)return ['status'=>'rollback_incomplete','preserved_paths'=>$failures,'journal'=>$path];
        if($dryRun)return ['status'=>'dry_run','restore_paths'=>array_keys($actions),'journal'=>$path];
        foreach($actions as $relative=>$action){
            $full=self::path($root,$relative);
            if($action['backup']===null){if(is_file($full)&&!unlink($full))throw new RuntimeException('Cannot remove migration-created core file: '.$relative);}
            else{$temporary=$full.'.'.bin2hex(random_bytes(8)).'.restore';if(!copy($action['backup'],$temporary)||!hash_equals($action['sha256'],hash_file('sha256',$temporary)))throw new RuntimeException('Cannot verify restored core file: '.$relative);chmod($temporary,((int)$action['mode'])&0777);if(!rename($temporary,$full))throw new RuntimeException('Cannot publish restored core file: '.$relative);}
        }
        $temporary=$ledgerPath.'.'.bin2hex(random_bytes(8)).'.restore';$mask=umask(0077);try{$handle=fopen($temporary,'x');}finally{umask($mask);}
        if($handle===false)throw new RuntimeException('Cannot create recovered ledger.');try{if(fwrite($handle,$old)!==strlen($old)||!fflush($handle))throw new RuntimeException('Cannot finish recovered ledger.');}finally{fclose($handle);}
        if(!rename($temporary,$ledgerPath))throw new RuntimeException('Cannot publish recovered legacy ledger.');
        $journal['status']='rolled_back';self::save($path,$journal);
        return ['status'=>'rolled_back','restored_paths'=>array_keys($actions),'journal'=>$path];
    }
    private static function path(string $root,string $relative): string
    {
        Manifest::validateRelativePath($relative);$path=$root;foreach(explode('/',$relative) as $part){$path.='/'.$part;if(is_link($path))throw new RuntimeException('Recovery path cannot traverse a symlink.');}return $path;
    }
}
