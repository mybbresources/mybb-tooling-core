<?php
declare(strict_types=1);

// Validate the actual tracked export, not a developer's ignored working files.
$root=dirname(__DIR__);
$process=proc_open(['git','ls-files','-z'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
if(!is_resource($process))throw new RuntimeException('Cannot inspect public tracked files.');
fclose($pipes[0]);$files=explode("\0",stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($process)!==0)throw new RuntimeException('Public tree check requires its Git checkout.');
$directories=['core-plugin','src','tools','tests','mybb-cli','mybb-installer-cli','mybb-plugin-repository','docs','examples','sample-sites','.github'];
$rootFiles=['README.md','RELEASING.md','PLUGIN-MANIFEST.md','LICENSE','autoload.php','composer.json','.gitignore'];
$count=0;
foreach($files as $file){
 if($file==='')continue;$count++;
 $parts=explode('/',$file);$base=basename($file);
 if(count($parts)===1&&!in_array($file,$rootFiles,true))throw new RuntimeException('Unreviewed public root file: '.$file);
 if(count($parts)>1&&!in_array($parts[0],$directories,true))throw new RuntimeException('Private/unreviewed directory in public export: '.$parts[0]);
 if(in_array('deploy',$parts,true)||in_array('runtime',$parts,true)||in_array('vendor',$parts,true)||str_starts_with($base,'.env')||preg_match('/\.(sql|sqlite3?|db|dump|key|pem|p12|pfx)$/i',$file))throw new RuntimeException('Private/generated artifact in public export: '.$file);
 $path=$root.'/'.$file;
 if(is_link($path))throw new RuntimeException('Unexpected symlink in public source.');
 $bytes=file_get_contents($path);
 if(preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',$bytes))throw new RuntimeException('Private key material in public export: '.$file);
}
if($count===0)throw new RuntimeException('Public tracked source is empty.');
echo 'PASS public distribution boundary: '.$count." tracked files; private applications, deployment folders, generated data and keys excluded.\n";
