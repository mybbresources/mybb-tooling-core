<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;
use ZipArchive;
use MyBB\Tooling\Compatibility;

final class PackageManager
{
    public function __construct(private readonly string $siteRoot, private readonly string $stateRoot, private readonly array $trustedKeys, private readonly int $maxEntries = 2000, private readonly int $maxExpandedBytes = 67108864) {}

    public function install(string $zipPath, array $manifest, bool $force = false, bool $dryRun = false): array
    { $this->resolveRoot(false);return $dryRun?$this->installUnlocked($zipPath,$manifest,$force,true,false):$this->withLock(fn() => $this->installUnlocked($zipPath,$manifest,$force,false,false)); }

    public function installBootstrap(string $zipPath,array $manifest,?callable $onStateWrite=null,?callable $onTransactionCreated=null): array
    { $this->resolveRoot(true);return $this->withLock(fn() => $this->installUnlocked($zipPath,$manifest,false,false,true,$onStateWrite,$onTransactionCreated)); }

    private function installUnlocked(string $zipPath, array $manifest, bool $force, bool $dryRun,bool $bootstrap,?callable $onStateWrite=null,?callable $onTransactionCreated=null): array
    {
        Manifest::validate($manifest); Manifest::verifySignature($manifest, $this->trustedKeys);
        if (filesize($zipPath) !== $manifest['package']['size'] || !hash_equals($manifest['package']['sha256'], hash_file('sha256', $zipPath))) throw new RuntimeException('Package size or SHA-256 does not match manifest');
        $root = $this->resolveRoot($bootstrap); $records = $this->readLedger(!$dryRun); $owned = []; $newDestinations=[];$conflicts=[];
        Compatibility::assert($manifest['requirements'],Compatibility::detectMybbVersion($root),PHP_VERSION);
        $archive = new ZipArchive(); if ($archive->open($zipPath) !== true) throw new RuntimeException('Cannot open plugin archive');
        try {
            if ($archive->numFiles > $this->maxEntries) throw new RuntimeException('Archive contains too many entries');
            $declared = []; foreach ($manifest['files'] as $f) $declared[$f['source']] = $f;
            $seen = []; $total = 0;
            for ($i=0; $i<$archive->numFiles; $i++) {
                $stat = $archive->statIndex($i); $name = $stat['name'];
                $directoryEntry=str_ends_with($name, '/');$checkedName=$directoryEntry?substr($name,0,-1):$name;Manifest::validateRelativePath($checkedName);
                Manifest::validateArchiveEntryType($archive,$i,$directoryEntry);
                if ($directoryEntry) continue;
                if (isset($seen[$name])) throw new RuntimeException('Duplicate archive entry'); $seen[$name] = true;
                $total += (int)$stat['size']; if ($total > $this->maxExpandedBytes) throw new RuntimeException('Expanded archive exceeds size limit');
                if (!isset($declared[$name])) throw new RuntimeException("Undeclared archive file: {$name}");
            }
            if (array_diff_key($declared, $seen)) throw new RuntimeException('Archive is missing manifest-declared files');
            foreach ($manifest['files'] as $file) {
                $newDestinations[$file['destination']]=true;
                $destination = $this->safeDestination($root, $file['destination']);
                if(file_exists($destination)&&!is_file($destination))throw new RuntimeException("Destination is not a regular file: {$file['destination']}");
                $current = is_file($destination) ? hash_file('sha256', $destination) : null;
                $prior = $records[$manifest['slug']]['files'][$file['destination']]['sha256'] ?? null;
                if ($current !== null && $current !== $prior && !$force) {
                    if (!$dryRun) throw new RuntimeException("Destination exists or was modified: {$file['destination']}");
                    $conflicts[]=$file['destination'];
                }
                $owned[] = ['source'=>$file['source'],'destination'=>$file['destination'],'sha256'=>hash('sha256', $archive->getFromName($file['source'])),'existing_sha256'=>$current];
            }
            $removeOld=[];$preserveOld=[];
            foreach(($records[$manifest['slug']]['files']??[]) as $path=>$oldFile)if(!isset($newDestinations[$path])){$dest=$this->safeDestination($root,$path);if(!is_file($dest))continue;if(hash_file('sha256',$dest)!==$oldFile['sha256']&&!$force)$preserveOld[$path]=$oldFile;else $removeOld[]=$path;}
            if ($dryRun) return ['status'=>$conflicts?'conflicts':'dry_run','slug'=>$manifest['slug'],'version'=>$manifest['version'],'files'=>$owned,'conflicts'=>$conflicts,'obsolete_files_to_remove'=>$removeOld,'modified_obsolete_files_to_preserve'=>array_keys($preserveOld)];
            return $this->apply($archive, $manifest, $owned, $root, $records,$removeOld,$preserveOld,$onStateWrite,$onTransactionCreated);
        } finally { $archive->close(); }
    }

    public function remove(string $slug, bool $force = false, bool $dryRun = false): array
    { $this->resolveRoot();return $dryRun?$this->removeUnlocked($slug,$force,true):$this->withLock(fn() => $this->removeUnlocked($slug,$force,false)); }

    /** Persist the latest cache-confirmed activation state for an installed package. */
    public function setActivationState(string $slug, bool $active): array
    { $this->resolveRoot();return $this->withLock(function()use($slug,$active){$ledger=$this->readLedger();if(!isset($ledger[$slug]))throw new RuntimeException('Plugin is not tracked as installed');$ledger[$slug]['active']=$active;$this->writeLedger($ledger);return ['slug'=>$slug,'active'=>$active];}); }

    private function removeUnlocked(string $slug, bool $force, bool $dryRun): array
    {
        $root=$this->resolveRoot(); $ledger=$this->readLedger(!$dryRun); $record=$ledger[$slug]??null; if (!$record) throw new RuntimeException('Plugin is not tracked as installed');
        if(($record['active']??false)===true)throw new RuntimeException('Plugin is recorded as active; deactivate it before uninstalling.');
        // The local activation snapshot can be unavailable on database-backed MyBB caches.
        // Refuse a positively active result here; callers must verify unknown state against
        // MyBB's runtime before applying removal.
        foreach(PluginInventory::inspect($root,$ledger)['plugins'] as $plugin)if(($plugin['managed']??false)&&($plugin['slug']??null)===$slug&&($plugin['active']??null)===true)throw new RuntimeException('Plugin is active in MyBB; deactivate it before uninstalling.');
        $remove=[]; $modified=[]; $missing=[];
        foreach ($record['files'] as $path=>$file) { $dest=$this->safeDestination($root,$path); if (!is_file($dest)) { $missing[]=$path;continue; } if (hash_file('sha256',$dest)!==$file['sha256']) { if($force)$remove[]=$path;else{$modified[]=$path;continue;} } else $remove[]=$path; }
        if (!$remove && $modified && !$missing) return ['status'=>'preserved_modified_files','removed'=>[],'preserved'=>$modified];
        if ($dryRun) return ['status'=>'dry_run','removed'=>$remove,'preserved'=>$modified,'missing'=>$missing];
        $backup=$this->newTransaction('remove-'.$slug); $removed=[];
        try {
            foreach ($remove as $path) { $dest=$this->safeDestination($root,$path); $backupFile=$backup.'/backup/'.$path; if (!is_dir(dirname($backupFile))) mkdir(dirname($backupFile),0700,true); if (!rename($dest,$backupFile)) throw new RuntimeException("Could not stage removal: {$path}"); $removed[]=$path; }
            foreach(array_merge($removed,$missing) as $path)unset($ledger[$slug]['files'][$path]);
            $preserved=$force?[]:$modified;
            if(empty($ledger[$slug]['files']))unset($ledger[$slug]);else $ledger[$slug]['preserved_modified_files']=$preserved;
            $this->writeLedger($ledger);
            return ['status'=>$preserved?'removed_with_preserved_files':'removed','removed'=>$removed,'preserved'=>$preserved,'backup'=>$backup];
        } catch (\Throwable $e) { foreach (array_reverse($removed) as $path) { $src=$backup.'/backup/'.$path; $dst=$this->safeDestination($root,$path); if (is_file($src)) { @mkdir(dirname($dst),0755,true); @rename($src,$dst); } } throw new RuntimeException('Removal failed; rollback attempted; recovery at '.$backup,0,$e); }
    }

    private function apply(ZipArchive $zip, array $m, array $files, string $root, array $ledger,array $removeOld,array $preserveOld,?callable $onStateWrite=null,?callable $onTransactionCreated=null): array
    {
        $txn=$this->newTransaction($m['slug'],$onTransactionCreated); $written=[]; $oldLedger=$ledger;
        try {
            foreach ($files as $f) {
                $dest=$this->safeDestination($root,$f['destination']); $temp=$txn.'/stage/'.hash('sha256',$f['destination']);
                if (!is_dir(dirname($temp))) mkdir(dirname($temp),0700,true);
                $contents=$zip->getFromName($f['source']); if ($contents===false) throw new RuntimeException('Cannot read archive entry'); file_put_contents($temp,$contents,LOCK_EX);
                if (hash_file('sha256',$temp)!==$f['sha256']) throw new RuntimeException('Staged file digest mismatch');
                if (is_file($dest)) { $backup=$txn.'/backup/'.$f['destination']; @mkdir(dirname($backup),0700,true); if (!copy($dest,$backup)) throw new RuntimeException("Could not back up {$f['destination']}"); }
                $written[]=['destination'=>$f['destination'],'backup'=>is_file($dest)];
                @mkdir(dirname($dest),0755,true); if (!rename($temp,$dest)) throw new RuntimeException("Could not install {$f['destination']}");
            }
            foreach($removeOld as $path){$dest=$this->safeDestination($root,$path);if(!is_file($dest))continue;$backup=$txn.'/backup/'.$path;@mkdir(dirname($backup),0700,true);if(!rename($dest,$backup))throw new RuntimeException("Could not stage obsolete owned file {$path}");$written[]=['destination'=>$path,'backup'=>true];}
            $ledger[$m['slug']]=['version'=>$m['version'],'package_sha256'=>$m['package']['sha256'],'installed_at'=>gmdate(DATE_ATOM),'active'=>(bool)($ledger[$m['slug']]['active']??false),'files'=>[]];
            foreach ($files as $f) $ledger[$m['slug']]['files'][$f['destination']]=['sha256'=>$f['sha256'],'role'=>$this->roleFor($m,$f['source'])];
            foreach($preserveOld as $path=>$oldFile)$ledger[$m['slug']]['files'][$path]=$oldFile;
            $this->writeLedger($ledger,$onStateWrite); file_put_contents($txn.'/COMMITTED',gmdate(DATE_ATOM));
            return ['status'=>'installed','slug'=>$m['slug'],'version'=>$m['version'],'files'=>array_column($files,'destination'),'obsolete_files_removed'=>$removeOld,'modified_obsolete_files_preserved'=>array_keys($preserveOld),'transaction'=>$txn];
        } catch (\Throwable $e) {
            $failures=[]; foreach (array_reverse($written) as $f) { try{$dest=$this->safeDestination($root,$f['destination']);if($f['backup']){$backup=$txn.'/backup/'.$f['destination'];@mkdir(dirname($dest),0755,true);if(!@copy($backup,$dest))$failures[]=$f['destination'];}elseif(is_file($dest)&&!@unlink($dest))$failures[]=$f['destination'];}catch(\Throwable){$failures[]=$f['destination'];} }
            try{$this->writeLedger($oldLedger,$onStateWrite);}catch(\Throwable){$failures[]='installed.json';}
            file_put_contents($txn.'/FAILED',json_encode(['error'=>$e->getMessage(),'rollback_failures'=>$failures]));
            throw new RuntimeException('Install failed; rollback '.($failures?'incomplete for '.implode(', ',$failures):'completed').'; recovery data: '.$txn,0,$e);
        }
    }

    private function roleFor(array $m,string $source): string { foreach($m['files'] as $f) if($f['source']===$source) return $f['role']; return 'other'; }
    private function resolveRoot(bool $bootstrap=false): string { $root=realpath($this->siteRoot); if(!$root || !is_dir($root))throw new RuntimeException('Path is not a validated MyBB site');if(is_file($root.'/inc/config.php'))return $root;if($bootstrap&&is_file($root.'/inc/config.default.php')&&is_file($root.'/inc/class_core.php')&&is_file($root.'/global.php')&&is_file($root.'/install/index.php'))return $root;throw new RuntimeException('Path is not a configured MyBB site (inc/config.php missing)'); }
    private function safeDestination(string $root,string $relative): string { Manifest::validateRelativePath($relative); $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative); if(is_link($path))throw new RuntimeException('Destination is a symlink and cannot be managed'); $cursor=$root; foreach(explode('/',dirname($relative)) as $part) { if($part==='.') continue; $cursor.=DIRECTORY_SEPARATOR.$part; if(is_link($cursor)) { $real=realpath($cursor); if(!$real || !str_starts_with($real,$root.DIRECTORY_SEPARATOR)) throw new RuntimeException('Destination traverses a symlink outside site root'); } } return $path; }
    private function withLock(callable $work): mixed { $this->prepareStateRoot();$h=fopen($this->stateRoot.'/manager.lock','c');if(!$h||!flock($h,LOCK_EX))throw new RuntimeException('Cannot lock package state');try{return $work();}finally{flock($h,LOCK_UN);fclose($h);} }
    private function prepareStateRoot(bool $create=true):void {
        $root=realpath($this->siteRoot);if(!$root)throw new RuntimeException('Cannot resolve validated MyBB site root');$lexicalRoot=rtrim($this->siteRoot,DIRECTORY_SEPARATOR);$statePath=rtrim($this->stateRoot,DIRECTORY_SEPARATOR);
        if(str_starts_with($statePath,$lexicalRoot.DIRECTORY_SEPARATOR)){$relative=substr($statePath,strlen($lexicalRoot)+1);$cursor=$root;foreach(explode(DIRECTORY_SEPARATOR,$relative) as $part){if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Invalid package state path');$cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor))throw new RuntimeException('Package state path cannot traverse symlinks');if(file_exists($cursor)){if(!is_dir($cursor))throw new RuntimeException('Package state path component is not a directory');}elseif($create){if(!mkdir($cursor,0700)&&!is_dir($cursor))throw new RuntimeException('Cannot create package state directory');}else{return;}if(is_link($cursor))throw new RuntimeException('Package state path cannot traverse symlinks');}}
        else{if(is_link($this->stateRoot))throw new RuntimeException('Package state directory cannot be a symlink');if(file_exists($this->stateRoot)&&!is_dir($this->stateRoot))throw new RuntimeException('Package state path component is not a directory');if(!is_dir($this->stateRoot)&&$create&&!mkdir($this->stateRoot,0700,true)&&!is_dir($this->stateRoot))throw new RuntimeException('Cannot create package state directory');}
    }
    private function stateFile(): string { if(is_link($this->stateRoot))throw new RuntimeException('Package state directory cannot be a symlink');if(!is_dir($this->stateRoot) && !mkdir($this->stateRoot,0700,true) && !is_dir($this->stateRoot)) throw new RuntimeException('Cannot create state directory');@chmod($this->stateRoot,0700);$deny=$this->stateRoot.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"Require all denied\n");$index=$this->stateRoot.'/index.html';if(!is_file($index))@file_put_contents($index,'');return $this->stateRoot.'/installed.json'; }
    private function readLedger(bool $createIfMissing=true): array { if($createIfMissing){$p=$this->stateFile();}else{$this->prepareStateRoot(false);$p=$this->stateRoot.'/installed.json';if(is_link($p))throw new RuntimeException('Installed plugin ledger cannot be a symlink');}if(!is_file($p)) return []; $v=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR); if(!is_array($v)) throw new RuntimeException('Invalid installed ledger'); return $v; }
    private function writeLedger(array $v,?callable $onWrite=null): void { $p=$this->stateFile();$bytes=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($onWrite!==null)$onWrite($p,hash('sha256',$bytes));$tmp=$p.'.'.bin2hex(random_bytes(6)).'.tmp';if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Could not write installed ledger');chmod($tmp,0600);if(!rename($tmp,$p)) throw new RuntimeException('Could not atomically update installed ledger'); }
    private function newTransaction(string $name,?callable $onCreated=null): string { $dir=$this->stateRoot.'/transactions/'.preg_replace('/[^a-z0-9-]/i','-', $name).'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3));if($onCreated!==null)$onCreated($dir);if(!mkdir($dir,0700,true)) throw new RuntimeException('Cannot create transaction directory'); return $dir; }
}
