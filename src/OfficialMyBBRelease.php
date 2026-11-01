<?php
declare(strict_types=1);
namespace MyBB\Tooling;
use RuntimeException;
final class OfficialMyBBRelease {
 public static function latestVersion(string $downloadPage):string{$text=html_entity_decode(strip_tags($downloadPage),ENT_QUOTES|ENT_HTML5,'UTF-8');if(!preg_match('/Download\s+MyBB\s+(\d+\.\d+\.\d+)/i',$text,$m))throw new RuntimeException('Could not determine latest stable version from official download page');return $m[1];}
 public static function listedVersion(string $versionsPage,string $version):bool{$text=html_entity_decode(strip_tags($versionsPage),ENT_QUOTES|ENT_HTML5,'UTF-8');return (bool)preg_match('/(?<![0-9.])'.preg_quote($version,'/').'(?![0-9.])/',$text);}
 public static function versionCode(string $version):string{if(!preg_match('/^\d+\.\d+\.\d+$/',$version))throw new RuntimeException('MyBB version must be numeric stable MAJOR.MINOR.PATCH');return str_replace('.','',$version);}
 public static function packageUrl(string $version):string{return 'https://resources.mybb.com/downloads/mybb_'.self::versionCode($version).'.zip';}
 public static function sha512(string $checksums):string{if(preg_match('/SHA-?512(?:\s*\([^\r\n)]*\))?\s*[:=]\s*([a-f0-9]{128})/i',$checksums,$m))return strtolower($m[1]);if(preg_match('/\b([a-f0-9]{128})\s+\*?mybb_\d+\.zip\b/i',$checksums,$m))return strtolower($m[1]);throw new RuntimeException('Official checksum file did not contain a recognized SHA-512 checksum');}
 public static function packageSha512(string $releasePage,string $version):string{self::versionCode($version);$separatedTags=preg_replace('/>\s*</','> <',$releasePage);$text=html_entity_decode(strip_tags($separatedTags??$releasePage),ENT_QUOTES|ENT_HTML5,'UTF-8');if(!preg_match('/\bMyBB\s+'.preg_quote($version,'/').'(?![0-9.])/i',$text))throw new RuntimeException('Official version page did not identify the requested MyBB release');$start=stripos($text,'Full Package');$end=$start===false?false:stripos($text,'Changed Files',$start);if($start===false||$end===false)throw new RuntimeException('Official version page did not contain an unambiguous full-package section');$section=substr($text,$start,$end-$start);if(!preg_match('/\bsha\s*-?\s*512\s*:\s*([a-f0-9]{128})\b/i',$section,$m))throw new RuntimeException('Official version page did not publish a full-package SHA-512 checksum');return strtolower($m[1]);}
}
