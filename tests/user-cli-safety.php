<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$temp = sys_get_temp_dir().'/mybb-user-cli-'.bin2hex(random_bytes(6));
$site = $temp.'/site';
$passwordPath = $temp.'/new-password.txt';
if (!mkdir($site.'/inc/datahandlers', 0700, true)) throw new RuntimeException('Could not create synthetic MyBB site.');
file_put_contents($site.'/inc/config.php', "<?php\n\$config=[];\n");
file_put_contents($site.'/global.php', <<<'PHP'
<?php
class UserCliFixturePlugins { public function run_hooks(string $name): void { file_put_contents(__DIR__.'/hook-called.txt', $name); } }
class UserCliFixtureDatabase { public function delete_query(string $table, string $where): void { file_put_contents(__DIR__.'/sessions-revoked.txt', $table.' '.$where); } }
$GLOBALS['plugins'] = new UserCliFixturePlugins();
$GLOBALS['db'] = new UserCliFixtureDatabase();
PHP);
file_put_contents($site.'/inc/functions_user.php', <<<'PHP'
<?php
function get_user(int $uid): array { return $uid === 7 ? ['uid'=>7,'username'=>'fixture-user','email'=>'user@example.test'] : []; }
PHP);
file_put_contents($site.'/inc/datahandlers/user.php', <<<'PHP'
<?php
class UserDataHandler {
    public array $data=[];
    public function __construct(string $method) { if ($method !== 'update') throw new RuntimeException('Expected update mode'); }
    public function set_data(array $data): void { $this->data=$data; }
    public function validate_user(): bool { return ($this->data['uid']??0)===7 && ($this->data['password']??'')===($this->data['password2']??null) && strlen($this->data['password']??'')>=12 && !str_contains($this->data['password'],$this->data['username']); }
    public function update_user(): bool { file_put_contents(dirname(__DIR__,2).'/password-updated.txt','updated for uid '.$this->data['uid']); return true; }
}
PHP);
$run = static function (array $command): array {
    $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start the MyBB CLI command.');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$stdout,$stderr];
};
$remove = static function(string $path) use (&$remove): void {
    if(is_link($path)||is_file($path)){@unlink($path);return;}if(!is_dir($path))return;
    foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry)$remove($entry->getPathname());@rmdir($path);
};
try {
    $entry=$root.'/mybb-cli/bin/mybb';
    [$code,$stdout,$stderr]=$run([PHP_BINARY,$entry,'user','password-reset','7','--path='.$site,'--json']);
    if($code!==0||$stderr!=='')throw new RuntimeException('Password reset preview failed: '.$stdout.$stderr);
    $plan=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    if(($plan['status']??null)!=='confirmation_required'||($plan['password_file_read']??null)!==false||str_contains($stdout,'password file contents'))throw new RuntimeException('Preview must identify the target and avoid reading a password.');

    [$code,$stdout,$stderr]=$run([PHP_BINARY,$entry,'user','password-reset','7','N3w-Secret!Password-99','--path='.$site,'--yes','--json']);
    if($code!==2||$stderr!==''||str_contains($stdout,'N3w-Secret!Password-99'))throw new RuntimeException('CLI must reject passwords in argv without echoing them.');

    file_put_contents($passwordPath,"N3w-Secret!Password-99\n");chmod($passwordPath,0644);
    [$code,$stdout,$stderr]=$run([PHP_BINARY,$entry,'user','password-reset','7','--path='.$site,'--password-file='.$passwordPath,'--yes','--json']);
    if($code!==1||$stderr!==''||str_contains($stdout,'N3w-Secret!Password-99')||!str_contains($stdout,'permissions are too broad'))throw new RuntimeException('World-readable password input must fail without leaking its value.');
    if(is_file($site.'/password-updated.txt'))throw new RuntimeException('Password mutation ran before protected-input validation.');

    chmod($passwordPath,0600);
    [$code,$stdout,$stderr]=$run([PHP_BINARY,$entry,'user','password-reset','7','--path='.$site,'--password-file='.$passwordPath,'--yes','--json']);
    if($code!==0||$stderr!==''||str_contains($stdout,'N3w-Secret!Password-99'))throw new RuntimeException('Protected password reset failed or leaked the password: '.$stdout.$stderr);
    $result=json_decode($stdout,true,512,JSON_THROW_ON_ERROR);
    if(($result['status']??null)!=='password_reset'||($result['uid']??null)!==7||($result['active_sessions_revoked']??null)!==true)throw new RuntimeException('Password reset did not report target and session revocation.');
    if(!is_file($site.'/password-updated.txt')||!is_file($site.'/sessions-revoked.txt')||file_get_contents($site.'/sessions-revoked.txt')!=="sessions uid='7'")throw new RuntimeException('MyBB datahandler update or session invalidation was not invoked.');
    fwrite(STDOUT,"CLI user password reset requires confirmation and protected input, uses MyBB's datahandler, and revokes sessions.\n");
} finally { $remove($temp); }
