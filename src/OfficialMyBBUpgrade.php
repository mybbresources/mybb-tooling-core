<?php
declare(strict_types=1);
namespace MyBB\Tooling;

use RuntimeException;
use Throwable;

/** Transactional application of validated official MyBB core files. */
final class OfficialMyBBUpgrade
{
    public static function plan(string $siteRoot, string $releaseRoot): array
    {
        $site = realpath($siteRoot);
        $release = realpath($releaseRoot);
        if ($site === false || $release === false || !is_file($site . '/inc/config.php') || !is_file($site . '/global.php')) {
            throw new RuntimeException('Upgrade requires a configured MyBB site and a staged official release.');
        }
        $actions = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release, \FilesystemIterator::SKIP_DOTS)) as $item) {
            if (!$item->isFile() || $item->isLink()) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($release) + 1));
            Manifest::validateRelativePath($relative);
            if (self::preservePath($relative)) continue;
            $destination = self::safePath($site, $relative, false);
            if (file_exists($destination) && !is_file($destination)) throw new RuntimeException('Upgrade destination is not a regular file: ' . $relative);
            $actions[] = [
                'path' => $relative,
                'action' => is_file($destination) ? 'replace' : 'add',
                'old_sha256' => is_file($destination) ? hash_file('sha256', $destination) : null,
                'old_mode' => is_file($destination) ? (fileperms($destination) & 0777) : null,
                'new_sha256' => hash_file('sha256', $item->getPathname()),
                'bytes' => filesize($item->getPathname()),
            ];
        }
        usort($actions, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        if (!$actions) throw new RuntimeException('The staged release contains no applicable MyBB core files.');
        return $actions;
    }

    public static function validateBackupDirectory(string $siteRoot, string $requested): string
    {
        $site = realpath($siteRoot);
        if ($site === false || $requested === '') throw new RuntimeException('Upgrade requires --backup-dir outside the MyBB site.');
        $path = str_starts_with($requested, DIRECTORY_SEPARATOR) ? $requested : getcwd() . DIRECTORY_SEPARATOR . $requested;
        if (str_ends_with($path, DIRECTORY_SEPARATOR)) throw new RuntimeException('Backup directory must be a new directory path, not a trailing slash.');
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent) || !is_writable($parent) || is_link($path)) throw new RuntimeException('Backup directory parent must exist, be writable, and not use a symlink target.');
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($resolved)) throw new RuntimeException('Backup directory already exists; choose a new path.');
        if ($resolved === $site || str_starts_with($resolved, rtrim($site, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) throw new RuntimeException('Upgrade backup directory must be outside the public MyBB site directory.');
        return $resolved;
    }

    public static function apply(string $siteRoot, string $releaseRoot, string $backupDirectory, array $verification = []): array
    {
        $site = realpath($siteRoot);
        if ($site === false) throw new RuntimeException('Cannot resolve MyBB site root.');
        $backup = self::validateBackupDirectory($site, $backupDirectory);
        $plan = self::plan($site, $releaseRoot);
        $release = realpath($releaseRoot);
        if ($release === false || !mkdir($backup, 0700)) throw new RuntimeException('Could not create the timestamped upgrade backup directory.');
        $journalPath = $backup . '/transaction.json';
        $journal = array_merge(['schema_version'=>1,'kind'=>'mybb-core-upgrade','site'=>$site,'release'=> $release,'status'=>'backing_up','started_at'=>gmdate(DATE_ATOM),'backup_directory'=>$backup,'files'=>$plan],$verification);
        foreach($journal['files'] as &$file){$file['status']='planned';$file['backup_status']=$file['old_sha256']===null?'not_required':'pending';}unset($file);
        self::persist($journalPath, $journal);
        try {
            // Complete and verify every required backup before replacing any live file.
            foreach ($plan as $index => $action) {
                if ($action['old_sha256'] === null) continue;
                $backupPath = $backup . '/files/' . str_replace('/', DIRECTORY_SEPARATOR, $action['path']);
                $backupParent=dirname(substr($backupPath,strlen($backup)+1));self::ensureDirectory($backup,$backupParent==='.'?'files':$backupParent);
                $source = self::safePath($site, $action['path'], false);
                if (!hash_equals($action['old_sha256'], (string)hash_file('sha256', $source)) || !copy($source, $backupPath) || !hash_equals($action['old_sha256'], (string)hash_file('sha256', $backupPath))) {
                    throw new RuntimeException('Could not create or verify backup for ' . $action['path']);
                }
                @chmod($backupPath, 0600);
                $journal['files'][$index]['backup_status']='backed_up';
                self::persist($journalPath, $journal);
            }
            $journal['status'] = 'applying';
            foreach($journal['files'] as &$file)unset($file['backup_status']);unset($file);
            self::persist($journalPath, $journal);
            foreach ($plan as $index => $action) {
                self::ensureDirectory($site, dirname($action['path']));
                $destination = self::safePath($site, $action['path'], true);
                if ($action['old_sha256'] !== null && (!is_file($destination) || !hash_equals($action['old_sha256'], (string)hash_file('sha256', $destination)))) {
                    throw new RuntimeException('Site file changed after preflight; refusing to overwrite ' . $action['path']);
                }
                if ($action['old_sha256'] === null && file_exists($destination)) throw new RuntimeException('New core file collided with an existing path: ' . $action['path']);
                $source = $release . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $action['path']);
                if (!hash_equals($action['new_sha256'], (string)hash_file('sha256', $source))) throw new RuntimeException('Staged release file changed after validation: ' . $action['path']);
                $tmp = dirname($destination) . DIRECTORY_SEPARATOR . '.' . basename($destination) . '.mybb-' . bin2hex(random_bytes(8)) . '.tmp';
                self::copyVerifiedExclusive($source, $tmp, $action['new_sha256']);
                @chmod($tmp, fileperms($source) & 0777);
                if ($action['old_sha256'] === null) {
                    if (!@link($tmp, $destination)) { @unlink($tmp); throw new RuntimeException('New core file collided with an existing path: ' . $action['path']); }
                    @unlink($tmp);
                } elseif (!@rename($tmp, $destination)) { @unlink($tmp); throw new RuntimeException('Could not atomically replace upgrade file: ' . $action['path']); }
                $journal['files'][$index]['status'] = 'applied';
                self::persist($journalPath, $journal);
            }
            $journal['status'] = 'complete';
            $journal['completed_at'] = gmdate(DATE_ATOM);
            self::persist($journalPath, $journal);
            return ['status'=>'upgraded_files','site'=>$site,'backup_directory'=>$backup,'journal'=>$journalPath,'files'=>count($plan),'replaced'=>count(array_filter($plan,static fn(array $f):bool=>$f['action']==='replace')),'added'=>count(array_filter($plan,static fn(array $f):bool=>$f['action']==='add')),'database_upgrade'=>'pending_official_web_upgrader'];
        } catch (Throwable $e) {
            try { $recovery = self::rollback($journalPath); }
            catch (Throwable $rollbackError) { throw new RuntimeException('Upgrade failed; recovery data is preserved at ' . $backup . ' and rollback needs attention: ' . $rollbackError->getMessage(), 0, $e); }
            if ($recovery['status'] === 'rollback_incomplete') throw new RuntimeException('Upgrade failed; some changed files were preserved for review. Recovery data: ' . $backup, 0, $e);
            throw new RuntimeException('Upgrade failed; the previous file set was restored. Recovery data: ' . $backup, 0, $e);
        }
    }

    /** Continue a journaled upgrade only when the verified release and every live path still match the transaction. */
    public static function resume(string $siteRoot, string $releaseRoot, string $journalPath, bool $dryRun = false): array
    {
        $site=realpath($siteRoot);$release=realpath($releaseRoot);$journalReal=is_link($journalPath)?false:realpath($journalPath);
        if($site===false||$release===false||$journalReal===false||!is_file($journalReal))throw new RuntimeException('Upgrade resume requires the site, verified release, and regular transaction journal.');
        $journal=json_decode((string)file_get_contents($journalReal),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($journal)||($journal['schema_version']??null)!==1||($journal['kind']??null)!=='mybb-core-upgrade'||($journal['site']??null)!==$site||!is_array($journal['files']??null))throw new RuntimeException('Upgrade transaction journal is invalid or belongs to another site.');
        if(!in_array(($journal['status']??''),['backing_up','applying'],true))throw new RuntimeException('Only an interrupted backing-up or applying upgrade can be resumed.');
        $backup=realpath((string)($journal['backup_directory']??''));
        if($backup===false||dirname($journalReal)!==$backup||$backup===$site||str_starts_with($backup,rtrim($site,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))throw new RuntimeException('Upgrade recovery paths do not match the transaction journal.');
        $releaseMap=self::releaseFileHashes($release);$paths=[];$pendingBackups=[];$pendingFiles=[];
        if(!$journal['files'])throw new RuntimeException('Upgrade transaction journal has no validated file plan.');
        foreach($journal['files'] as $index=>$file){
            if(!is_array($file)||!is_string($file['path']??null)||!is_string($file['new_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/',$file['new_sha256'])||!in_array(($file['action']??''),['add','replace'],true)||!in_array(($file['status']??''),['planned','applied'],true))throw new RuntimeException('Upgrade journal contains an invalid file plan.');
            $path=$file['path'];Manifest::validateRelativePath($path);$key=strtolower($path);if(isset($paths[$key]))throw new RuntimeException('Upgrade journal contains duplicate normalized file paths.');$paths[$key]=true;
            $old=$file['old_sha256']??null;if($old!==null&&(!is_string($old)||!preg_match('/^[a-f0-9]{64}$/',$old)))throw new RuntimeException('Upgrade journal contains an invalid original digest.');
            if(($old===null)!==($file['action']==='add'))throw new RuntimeException('Upgrade journal action does not match its original digest.');
            if(!isset($releaseMap[$path])||!hash_equals($file['new_sha256'],$releaseMap[$path]))throw new RuntimeException('Verified release does not match the interrupted upgrade plan: '.$path);
            $destination=self::inspectionPath($site,$path);if(file_exists($destination)&&!is_file($destination))throw new RuntimeException('An upgrade path is no longer a regular file: '.$path);$current=is_file($destination)?(string)hash_file('sha256',$destination):null;
            if($old===null){if($current!==null&&!hash_equals($file['new_sha256'],$current))throw new RuntimeException('A new core path was changed after interruption; refusing to overwrite '.$path);}
            elseif($current===null||(!hash_equals($old,$current)&&!hash_equals($file['new_sha256'],$current)))throw new RuntimeException('A core path was changed after interruption; refusing to overwrite '.$path);
            $backupFile=self::inspectionPath($backup,'files/'.$path);
            if($old!==null){
                if(file_exists($backupFile)&&!is_file($backupFile))throw new RuntimeException('An upgrade backup path is not a regular file: '.$path);
                if(is_file($backupFile)){if(!hash_equals($old,(string)hash_file('sha256',$backupFile)))throw new RuntimeException('An upgrade backup failed digest verification: '.$path);}
                elseif(($journal['status']??'')==='applying'||$current===null||!hash_equals($old,$current))throw new RuntimeException('A required original-file backup is missing: '.$path);
                else $pendingBackups[]=$path;
            }
            if($current===null||!hash_equals($file['new_sha256'],$current))$pendingFiles[]=$path;
        }
        if(count($releaseMap)!==count($paths))throw new RuntimeException('Verified release file set differs from the interrupted upgrade plan.');
        $result=['site'=>$site,'backup_directory'=>$backup,'journal'=>$journalReal,'pending_backups'=>$pendingBackups,'pending_files'=>$pendingFiles,'release_files'=>count($paths)];
        if($dryRun)return ['status'=>'dry_run']+$result;

        try{
            if(($journal['status']??'')==='backing_up'){
                foreach($journal['files'] as $index=>$file){if(($file['old_sha256']??null)===null)continue;$backupFile=self::inspectionPath($backup,'files/'.$file['path']);if(is_file($backupFile))continue;
                    $source=self::inspectionPath($site,$file['path']);if(!is_file($source)||!hash_equals($file['old_sha256'],(string)hash_file('sha256',$source)))throw new RuntimeException('Site file changed before its original backup could be completed: '.$file['path']);
                    $backupParent=dirname('files/'.$file['path']);self::ensureDirectory($backup,$backupParent==='.'?'files':$backupParent);if(!copy($source,$backupFile)||!hash_equals($file['old_sha256'],(string)hash_file('sha256',$backupFile)))throw new RuntimeException('Could not create or verify the missing original backup: '.$file['path']);@chmod($backupFile,0600);$journal['files'][$index]['backup_status']='backed_up';self::persist($journalReal,$journal);
                }
                $journal['status']='applying';foreach($journal['files'] as &$file)unset($file['backup_status']);unset($file);self::persist($journalReal,$journal);
            }
            foreach($journal['files'] as $index=>$file){
                $path=$file['path'];self::ensureDirectory($site,dirname($path));$destination=self::safePath($site,$path,true);$current=is_file($destination)?(string)hash_file('sha256',$destination):null;
                if($current!==null&&hash_equals($file['new_sha256'],$current)){$journal['files'][$index]['status']='applied';self::persist($journalReal,$journal);continue;}
                $old=$file['old_sha256']??null;if($old===null){if(file_exists($destination))throw new RuntimeException('New core file collided during resume: '.$path);}elseif($current===null||!hash_equals($old,$current))throw new RuntimeException('Site file changed during resume; refusing to overwrite '.$path);
                $source=$release.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$path);if(!is_file($source)||!hash_equals($file['new_sha256'],(string)hash_file('sha256',$source)))throw new RuntimeException('Verified release file changed during resume: '.$path);
                $tmp=dirname($destination).DIRECTORY_SEPARATOR.'.'.basename($destination).'.mybb-resume-'.bin2hex(random_bytes(8)).'.tmp';self::copyVerifiedExclusive($source,$tmp,$file['new_sha256']);@chmod($tmp,fileperms($source)&0777);
                if($old===null){if(!@link($tmp,$destination)){@unlink($tmp);throw new RuntimeException('New core file collided during resume: '.$path);}@unlink($tmp);}elseif(!@rename($tmp,$destination)){@unlink($tmp);throw new RuntimeException('Could not atomically replace upgrade file during resume: '.$path);}
                $journal['files'][$index]['status']='applied';self::persist($journalReal,$journal);
            }
            $journal['status']='complete';$journal['completed_at']=gmdate(DATE_ATOM);self::persist($journalReal,$journal);
            return ['status'=>'upgraded_files','site'=>$site,'backup_directory'=>$backup,'journal'=>$journalReal,'files'=>count($journal['files']),'replaced'=>count(array_filter($journal['files'],static fn(array $f):bool=>$f['action']==='replace')),'added'=>count(array_filter($journal['files'],static fn(array $f):bool=>$f['action']==='add')),'database_upgrade'=>'pending_official_web_upgrader'];
        }catch(Throwable $e){
            try{$recovery=self::rollback($journalReal);}catch(Throwable $rollbackError){throw new RuntimeException('Upgrade resume failed; recovery data is preserved at '.$backup.' and rollback needs attention: '.$rollbackError->getMessage(),0,$e);}
            if($recovery['status']==='rollback_incomplete')throw new RuntimeException('Upgrade resume failed; changed files were preserved for review. Recovery data: '.$backup,0,$e);
            throw new RuntimeException('Upgrade resume failed; the previous file set was restored. Recovery data: '.$backup,0,$e);
        }
    }

    public static function rollback(string $journalPath): array
    {
        $journal = json_decode((string)@file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($journal) || ($journal['schema_version'] ?? null) !== 1 || ($journal['kind'] ?? null) !== 'mybb-core-upgrade' || !is_array($journal['files'] ?? null)) throw new RuntimeException('Upgrade transaction journal is invalid.');
        if (($journal['status'] ?? '') === 'complete') throw new RuntimeException('Upgrade is committed; use a new upgrade rather than rolling back a completed transaction.');
        $site = realpath((string)($journal['site'] ?? ''));
        $backup = realpath((string)($journal['backup_directory'] ?? dirname($journalPath)));
        if ($site === false || $backup === false || dirname(realpath($journalPath) ?: '') !== $backup) throw new RuntimeException('Upgrade recovery paths do not match the transaction journal.');
        $restored=[];$removed=[];$preserved=[];
        foreach (array_reverse($journal['files']) as $file) {
            $relative=(string)($file['path']??'');
            try { Manifest::validateRelativePath($relative); $destination=self::safePath($site,$relative,true); }
            catch (Throwable) { $preserved[]=$relative?:'(invalid path)'; continue; }
            if (!file_exists($destination)) {
                if ($file['old_sha256']===null) continue;
                $backupFile=self::inspectionPath($backup,'files/'.$relative);
                if (!is_file($backupFile)||!hash_equals((string)$file['old_sha256'],(string)hash_file('sha256',$backupFile))) { $preserved[]=$relative; continue; }
                self::ensureDirectory($site,dirname($relative));
                $tmp=$destination.'.rollback-'.bin2hex(random_bytes(6)).'.tmp';
                if (self::tryCopyVerifiedExclusive($backupFile,$tmp,(string)$file['old_sha256'])) { @chmod($tmp,(int)($file['old_mode']??0644)); if(@rename($tmp,$destination))$restored[]=$relative;else{$preserved[]=$relative;@unlink($tmp);} }
                else { $preserved[]=$relative; }
                continue;
            }
            $current=(string)@hash_file('sha256',$destination);
            if (hash_equals((string)$file['new_sha256'],$current)) {
                if ($file['old_sha256']===null) { if (@unlink($destination)) $removed[]=$relative; else $preserved[]=$relative; continue; }
                $backupFile=self::inspectionPath($backup,'files/'.$relative);
                if (!is_file($backupFile)||!hash_equals((string)$file['old_sha256'],(string)hash_file('sha256',$backupFile))) { $preserved[]=$relative; continue; }
                self::ensureDirectory($site,dirname($relative));
                $tmp=$destination.'.rollback-'.bin2hex(random_bytes(6)).'.tmp';
                if (self::tryCopyVerifiedExclusive($backupFile,$tmp,(string)$file['old_sha256'])) { @chmod($tmp,(int)($file['old_mode']??0644)); if(@rename($tmp,$destination))$restored[]=$relative;else{$preserved[]=$relative;@unlink($tmp);} }
                else { $preserved[]=$relative; }
                continue;
            }
            if ($file['old_sha256']!==null && hash_equals((string)$file['old_sha256'],$current)) continue;
            $preserved[]=$relative;
        }
        $journal['status']=$preserved?'rollback_incomplete':'rolled_back';$journal['rollback_restored']=$restored;$journal['rollback_removed']=$removed;$journal['rollback_preserved']=$preserved;$journal['recovered_at']=gmdate(DATE_ATOM);self::persist($journalPath,$journal);
        return ['status'=>$journal['status'],'restored'=>$restored,'removed'=>$removed,'preserved'=>$preserved,'journal'=>$journalPath];
    }

    private static function preservePath(string $path): bool
    {
        return $path==='inc/config.php'||$path==='inc/settings.php'||str_starts_with($path,'uploads/')||$path==='uploads'||str_starts_with($path,'cache/')||$path==='cache'||str_starts_with($path,'inc/plugins/')||$path==='inc/plugins'||str_starts_with($path,'admin/backups/')||$path==='admin/backups';
    }

    private static function safePath(string $root,string $relative,bool $allowMissing): string
    {
        Manifest::validateRelativePath($relative);$cursor=$root;$parts=explode('/',$relative);
        foreach($parts as $index=>$part){$cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor))throw new RuntimeException('Upgrade path traverses a symlink: '.$relative);if($index<count($parts)-1&&file_exists($cursor)&&!is_dir($cursor))throw new RuntimeException('Upgrade parent is not a directory: '.$relative);}
        if (!$allowMissing && !file_exists($cursor)) return $cursor;
        $parent=realpath(dirname($cursor));if($parent===false||($parent!==$root&&!str_starts_with($parent,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)))throw new RuntimeException('Upgrade path resolves outside site root: '.$relative);
        return $cursor;
    }

    /** Resolve a planned path lexically while rejecting every existing symlinked or non-directory parent. */
    private static function inspectionPath(string $root,string $relative): string
    {
        Manifest::validateRelativePath($relative);$cursor=$root;$missing=false;$parts=explode('/',$relative);
        foreach($parts as $index=>$part){$cursor.=DIRECTORY_SEPARATOR.$part;if($missing)continue;if(is_link($cursor))throw new RuntimeException('Upgrade path traverses a symlink: '.$relative);if($index<count($parts)-1&&file_exists($cursor)&&!is_dir($cursor))throw new RuntimeException('Upgrade parent is not a directory: '.$relative);if(!file_exists($cursor))$missing=true;}
        return $cursor;
    }

    /** @return array<string,string> */
    private static function releaseFileHashes(string $release): array
    {
        $files=[];foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release,\FilesystemIterator::SKIP_DOTS)) as $item){if(!$item->isFile()||$item->isLink())continue;$path=str_replace(DIRECTORY_SEPARATOR,'/',substr($item->getPathname(),strlen($release)+1));Manifest::validateRelativePath($path);if(self::preservePath($path))continue;if(isset($files[$path]))throw new RuntimeException('Verified release contains duplicate normalized paths.');$files[$path]=(string)hash_file('sha256',$item->getPathname());}return $files;
    }

    private static function ensureDirectory(string $root,string $relative): void
    {
        if ($relative==='.'||$relative==='') return;
        $cursor=$root;
        foreach(explode('/',$relative) as $part){$cursor.=DIRECTORY_SEPARATOR.$part;if(is_link($cursor))throw new RuntimeException('Upgrade directory is a symlink: '.$relative);if(!is_dir($cursor)&&!@mkdir($cursor,0755))throw new RuntimeException('Could not create upgrade directory: '.$relative);$real=realpath($cursor);if($real===false||!str_starts_with($real,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))throw new RuntimeException('Upgrade directory resolves outside its root: '.$relative);}
    }

    private static function persist(string $path,array $journal): void
    {
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';$json=json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Could not persist upgrade transaction journal');@chmod($tmp,0600);if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Could not publish upgrade transaction journal');}
    }

    private static function copyVerifiedExclusive(string $source,string $destination,string $expectedHash): void
    {
        if (!self::tryCopyVerifiedExclusive($source,$destination,$expectedHash)) throw new RuntimeException('Could not stage and verify upgrade file: ' . basename($destination));
    }

    private static function tryCopyVerifiedExclusive(string $source,string $destination,string $expectedHash): bool
    {
        $input=@fopen($source,'rb');if($input===false)return false;$output=@fopen($destination,'xb');if($output===false){fclose($input);return false;}
        $copied=stream_copy_to_stream($input,$output);$flushed=fflush($output);fclose($input);fclose($output);
        if($copied===false||!$flushed||!is_file($destination)||!hash_equals($expectedHash,(string)hash_file('sha256',$destination))){@unlink($destination);return false;}
        return true;
    }
}
