<?php
declare(strict_types=1);
namespace MyBB\Tooling;
use RuntimeException;
final class Compatibility {
 public static function assert(array $requirements,string $mybbVersion,string $phpVersion):void {
  foreach(['mybb'=>$mybbVersion,'php'=>$phpVersion] as $component=>$actual){$range=(string)($requirements[$component]??'');if($range===''||!self::satisfies($actual,$range))throw new RuntimeException("Incompatible {$component} version {$actual}; release requires ".($range?:'a declared range'));}
 }
 public static function satisfies(string $actual,string $range):bool {
  self::validateRange($range);$constraints=preg_split('/\s+/',trim($range));
  foreach($constraints as $constraint){if(!preg_match('/^(>=|<=|>|<|=|\^|~)?(\d+(?:\.\d+){0,2})(?:\.(x|\*))?$/i',$constraint,$m))throw new RuntimeException('Unsupported compatibility range expression: '.$constraint);$op=$m[1]?:'=';$target=$m[2];if(isset($m[3])&&$m[3]!==''){if($op!=='=')throw new RuntimeException('Wildcard compatibility ranges only support exact prefix matching');$actualParts=explode('.',$actual);foreach(explode('.',$target) as $index=>$part)if(!isset($actualParts[$index])||$actualParts[$index]!==$part)return false;continue;}$cmp=version_compare($actual,$target);$parts=array_map('intval',explode('.',$target));$upper=null;if($op==='^'){$upper=$parts[0]>0?($parts[0]+1).'.0.0':(($parts[1]??0)>0?'0.'.($parts[1]+1).'.0':'0.0.'.(($parts[2]??0)+1));}elseif($op==='~'){$upper=count($parts)>=2?$parts[0].'.'.($parts[1]+1).'.0':($parts[0]+1).'.0.0';}$ok=match($op){'>='=>$cmp>=0,'<='=>$cmp<=0,'>'=>$cmp>0,'<'=>$cmp<0,'='=>$cmp===0,'^'=>$cmp>=0&&version_compare($actual,(string)$upper,'<'),'~'=>$cmp>=0&&version_compare($actual,(string)$upper,'<'),default=>false};if(!$ok)return false;}return true;
 }
 public static function validateRange(string $range):void{$constraints=preg_split('/\s+/',trim($range));if(!$constraints||$constraints===[''])throw new RuntimeException('Compatibility range cannot be empty');foreach($constraints as $constraint){if(!preg_match('/^(>=|<=|>|<|=|\^|~)?(\d+(?:\.\d+){0,2})(?:\.(x|\*))?$/i',$constraint,$m))throw new RuntimeException('Unsupported compatibility range expression: '.$constraint);if(isset($m[3])&&$m[3]!==''&&isset($m[1])&&$m[1]!==''&&$m[1]!=='=')throw new RuntimeException('Wildcard compatibility ranges only support exact prefix matching');}}
 public static function detectMybbVersion(string $root):string {
  $file=$root.'/inc/class_core.php';if(!is_file($file))throw new RuntimeException('Cannot determine MyBB version: inc/class_core.php is missing');$source=(string)file_get_contents($file);if(preg_match('/(?:\$version|MYBB_VERSION)\s*=\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]/i',$source,$m))return $m[1];throw new RuntimeException('Cannot safely determine the installed MyBB version');
 }
}
