<?php
declare(strict_types=1);

namespace MyBB\Installer;

use mysqli;
use RuntimeException;
use Throwable;

/** Runs the official MyBB web installer steps with protected operator input. */
final class InitialSetup
{
    /** Ask for setup values only on an interactive POSIX terminal. */
    public static function prompt(): array
    {
        if (PHP_SAPI !== 'cli' || !function_exists('stream_isatty') || !@stream_isatty(STDIN) || PHP_OS_FAMILY === 'Windows') {
            throw new RuntimeException('Interactive setup requires a POSIX TTY; use --setup-config=PROTECTED_JSON for automation or other environments.');
        }

        $line = static function (string $label, string $default = ''): string {
            fwrite(STDERR, $label . ($default !== '' ? ' [' . $default . ']' : '') . ': ');
            fflush(STDERR);
            $value = fgets(STDIN);
            if (!is_string($value)) throw new RuntimeException('Could not read setup input from the terminal.');
            $value = trim($value);
            return $value !== '' ? $value : $default;
        };
        $secret = static function (string $label): string {
            fwrite(STDERR, $label . ': ');
            fflush(STDERR);
            self::setTerminalEcho(false);
            try {
                $value = fgets(STDIN);
            } finally {
                self::setTerminalEcho(true);
                fwrite(STDERR, "\n");
            }
            if (!is_string($value)) throw new RuntimeException('Could not read secret setup input from the terminal.');
            return rtrim($value, "\r\n");
        };

        fwrite(STDERR, "MyBB initial setup. Password input is hidden; values are never placed in command arguments.\n\nDatabase\n");
        $dbHost = $line('MySQLi host', 'localhost');
        $dbUser = $line('Database username');
        $dbPassword = $secret('Database password');
        $dbName = $line('Database name');
        $dbPrefix = $line('Table prefix', 'mybb_');
        $encoding = $line('Database encoding', 'utf8mb4');

        fwrite(STDERR, "\nForum\n");
        $forumUrl = $line('Forum URL (no trailing slash)');
        $forumName = $line('Forum name');
        $websiteName = $line('Website name', $forumName);
        $websiteUrl = $line('Website URL', $forumUrl);
        $contact = $line('Contact email');

        fwrite(STDERR, "\nAdministrator\n");
        $adminName = $line('Admin username');
        $adminPassword = $secret('Admin password (minimum 12 characters)');
        $adminPasswordAgain = $secret('Confirm admin password');
        if (!hash_equals($adminPassword, $adminPasswordAgain)) throw new RuntimeException('Administrator password confirmation did not match.');
        $adminEmail = $line('Admin email');

        $config = self::validateConfig([
            'database' => ['host'=>$dbHost,'username'=>$dbUser,'password'=>$dbPassword,'name'=>$dbName,'table_prefix'=>$dbPrefix,'encoding'=>$encoding],
            'forum' => ['url'=>$forumUrl,'name'=>$forumName,'website_name'=>$websiteName,'website_url'=>$websiteUrl,'contact_email'=>$contact],
            'admin' => ['username'=>$adminName,'password'=>$adminPassword,'email'=>$adminEmail],
        ]);
        fwrite(STDERR, "\nReview setup\nForum: {$config['forum']['name']} ({$config['forum']['url']})\nDatabase: {$config['database']['host']} / {$config['database']['name']} / prefix {$config['database']['table_prefix']}\nAdministrator: {$config['admin']['username']} ({$config['admin']['email']})\nContinue with MyBB setup? [y/N]: ");
        fflush(STDERR);
        $answer = fgets(STDIN);
        if (!is_string($answer) || strtolower(trim($answer)) !== 'y') throw new RuntimeException('Setup cancelled before database or site changes.');
        return $config;
    }

    public static function loadConfig(string $path, string $targetRoot): array
    {
        if ($path === '' || $path === '-') throw new RuntimeException('Provide --setup-config=PATH to a protected JSON file; secrets are not accepted in command arguments or stdin output.');
        if (is_link($path)) throw new RuntimeException('Setup config must not be a symlink.');
        $real = realpath($path);
        $root = realpath($targetRoot);
        if ($real === false || !is_file($real) || ($root !== false && str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Setup config must be a readable file outside the MyBB site directory.');
        }
        // PHP caches stat results per process. Clear that cache so a chmod
        // performed by an operator is reflected in this security check.
        clearstatcache(true, $real);
        $permissions = fileperms($real);
        if (PHP_OS_FAMILY !== 'Windows' && ($permissions === false || ($permissions & 0077) !== 0)) {
            throw new RuntimeException('Setup config permissions are too broad; restrict it to its owner (chmod 600).');
        }
        if (filesize($real) > 65536) throw new RuntimeException('Setup config exceeds the 64 KiB limit.');
        $raw = file_get_contents($real);
        if (!is_string($raw)) throw new RuntimeException('Could not read setup config.');
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('Setup config is not valid JSON.'); }
        return self::validateConfig($data);
    }

    public static function validateConfig(mixed $data): array
    {
        if (!is_array($data) || !is_array($data['database'] ?? null) || !is_array($data['forum'] ?? null) || !is_array($data['admin'] ?? null)) {
            throw new RuntimeException('Setup config must contain database, forum, and admin objects.');
        }
        $db = $data['database']; $forum = $data['forum']; $admin = $data['admin'];
        $host = self::requiredString($db, 'host', 255);
        $username = self::requiredString($db, 'username', 255);
        $password = self::requiredString($db, 'password', 4096, true);
        $name = self::requiredString($db, 'name', 64);
        $prefix = (string)($db['table_prefix'] ?? 'mybb_');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $prefix)) throw new RuntimeException('Database table_prefix must start with a letter and contain only letters, digits, and underscores (maximum 40 characters).');
        $encoding = (string)($db['encoding'] ?? 'utf8mb4');
        if (!in_array($encoding, ['utf8mb4', 'utf8'], true)) throw new RuntimeException('Database encoding must be utf8mb4 or utf8.');

        $forumUrl = self::requiredString($forum, 'url', 2048);
        $parts = parse_url($forumUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || str_ends_with($forumUrl, '/')) {
            throw new RuntimeException('Forum URL must be an absolute HTTP(S) URL without credentials, query, fragment, or trailing slash.');
        }
        $forumName = self::requiredString($forum, 'name', 120);
        $websiteName = self::optionalString($forum, 'website_name', $forumName, 120);
        $websiteUrl = self::optionalString($forum, 'website_url', $forumUrl, 2048);
        self::validateWebUrl($websiteUrl, 'website_url');
        $contact = self::requiredString($forum, 'contact_email', 254);
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('forum.contact_email must be a valid email address.');
        $adminName = self::requiredString($admin, 'username', 50);
        $adminPassword = self::requiredString($admin, 'password', 4096);
        if (strlen($adminPassword) < 12) throw new RuntimeException('Administrator password must be at least 12 characters.');
        $adminEmail = self::requiredString($admin, 'email', 254);
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('admin.email must be a valid email address.');

        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $cookiePath = ($path === '' ? '' : $path) . '/';
        $hostName = (string)$parts['host'];
        // Use the exact configured host. Guessing a registrable parent domain
        // without a public-suffix database could broaden cookie scope.
        $cookieDomain = filter_var($hostName, FILTER_VALIDATE_IP) || !str_contains($hostName, '.') ? '' : '.' . $hostName;
        return [
            'database' => ['host'=>$host,'username'=>$username,'password'=>$password,'name'=>$name,'table_prefix'=>$prefix,'encoding'=>$encoding],
            'forum' => ['url'=>$forumUrl,'name'=>$forumName,'website_name'=>$websiteName,'website_url'=>$websiteUrl,'contact_email'=>$contact,'cookie_domain'=>$cookieDomain,'cookie_path'=>$cookiePath],
            'admin' => ['username'=>$adminName,'password'=>$adminPassword,'email'=>$adminEmail],
        ];
    }

    /** Refuse databases where MyBB's official create-tables step could drop existing tables. */
    public static function preflightDatabase(array $config): array
    {
        if (!extension_loaded('mysqli')) throw new RuntimeException('The MySQLi extension is required for initial MyBB setup.');
        $db=$config['database'];[$host,$port]=self::parseHost($db['host']);
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $connection = new mysqli($host, $db['username'], $db['password'], $db['name'], $port);
            $connection->set_charset($db['encoding']);
            $tables=[];$result=$connection->query('SHOW TABLES');
            while($row=$result->fetch_row())if(str_starts_with((string)$row[0],$db['table_prefix']))$tables[]=(string)$row[0];
            $result->free();$connection->close();
        } catch (Throwable) {
            throw new RuntimeException('Could not verify the configured MySQL database. Confirm the database exists and the supplied account can connect; credentials were not displayed.');
        }
        if ($tables) throw new RuntimeException('Refusing setup because '.$db['table_prefix'].' matches existing database tables; MyBB installation may replace those tables. Choose a new empty database or unused prefix.');
        return ['driver'=>'mysqli','matching_tables'=>0,'table_prefix'=>$db['table_prefix']];
    }

    public static function configure(string $siteRoot, array $config): array
    {
        $root=realpath($siteRoot);
        if($root===false||!is_file($root.'/install/index.php')||!is_file($root.'/inc/class_core.php'))throw new RuntimeException('Site does not contain the official MyBB installer files.');
        $journalPath=$root.'.mybb-setup-transaction.json';
        if(is_file($root.'/install/lock'))return ['status'=>'already_configured','site_root'=>$root,'install_lock'=>$root.'/install/lock'];
        $journal=self::readJournal($journalPath,$root,$config);
        if($journal!==null&&($journal['status']??'')==='complete')return ['status'=>'already_configured','site_root'=>$root,'install_lock'=>$root.'/install/lock'];
        if($journal!==null&&self::unsafeToRepeat($journal))throw new RuntimeException('An earlier database setup stopped during or after a data-writing step. Review the setup journal and database before retrying: '.$journalPath);
        // Inspect the recovery journal first so a partial schema install gets a
        // precise recovery warning instead of the less useful table collision.
        self::preflightDatabase($config);
        if($journal===null)$journal=['schema_version'=>1,'site_root'=>$root,'driver'=>'mysqli','table_prefix'=>$config['database']['table_prefix'],'forum_url'=>$config['forum']['url'],'status'=>'running','last_completed_step'=>null,'in_progress_step'=>null,'started_at'=>gmdate(DATE_ATOM)];
        $journal['status']='running';$journal['updated_at']=gmdate(DATE_ATOM);self::writeJournal($journalPath,$journal);
        try {
            self::runOfficialWizard($root,$config,$journal,$journalPath);
            $journal['status']='complete';$journal['in_progress_step']=null;$journal['last_completed_step']='final';$journal['completed_at']=gmdate(DATE_ATOM);self::writeJournal($journalPath,$journal);
            return ['status'=>'configured','site_root'=>$root,'mybb_installer'=>'completed_and_locked','setup_journal'=>$journalPath,'table_prefix'=>$config['database']['table_prefix']];
        } catch(Throwable $e) {
            $journal['status']='failed';$journal['failed_at']=gmdate(DATE_ATOM);$journal['failure_step']=$journal['in_progress_step'];self::writeJournal($journalPath,$journal);
            throw new RuntimeException('MyBB database/admin setup stopped at step '.(string)($journal['failure_step']??'unknown').'. The file installation remains intact; review '.$journalPath.' before retrying. Credentials were not included in the error.');
        }
    }

    /** Execute independently isolated requests to MyBB's own installer entrypoint. */
    public static function runOfficialWizard(string $root,array $config,array &$journal,string $journalPath): void
    {
        $tempDir=sys_get_temp_dir().'/mybb-setup-'.bin2hex(random_bytes(12));
        if(!mkdir($tempDir,0700))throw new RuntimeException('Could not create private temporary directory for installer request data.');
        $payloadFile=$tempDir.'/request.json';$bridgeFile=$tempDir.'/request.php';$outputFile=$tempDir.'/response.html';$errorFile=$tempDir.'/php-error.log';
        @chmod($tempDir,0700);
        $forum=parse_url($config['forum']['url']);$host=(string)$forum['host'];$https=($forum['scheme']??'')==='https';$port=(int)($forum['port']??($https?443:80));$hostHeader=$host.(isset($forum['port'])?':'.$forum['port']:'');
        $db=$config['database'];$f=$config['forum'];$a=$config['admin'];
        $databaseFields=['dbengine'=>'mysqli','config'=>['mysqli'=>['dbhost'=>$db['host'],'dbuser'=>$db['username'],'dbpass'=>$db['password'],'dbname'=>$db['name'],'tableprefix'=>$db['table_prefix'],'encoding'=>$db['encoding']]]];
        $configurationFields=['bbname'=>$f['name'],'bburl'=>$f['url'],'websitename'=>$f['website_name'],'websiteurl'=>$f['website_url'],'contactemail'=>$f['contact_email'],'cookiedomain'=>$f['cookie_domain'],'cookiepath'=>$f['cookie_path'],'pin'=>''];
        $adminFields=['adminuser'=>$a['username'],'adminpass'=>$a['password'],'adminpass2'=>$a['password'],'adminemail'=>$a['email']];
        $steps=[
            ['license',[], 'requirements_check'],
            ['requirements_check',[], 'database_info'],
            ['database_info',[], 'create_tables'],
            ['create_tables',$databaseFields,'populate_tables'],
            ['populate_tables',[],'templates'],
            ['templates',[],'configuration'],
            ['configuration',$configurationFields,'adminuser'],
            ['adminuser',$configurationFields,'final'],
            ['final',$adminFields,null],
        ];
        try {
            $bridge="<?php\ndeclare(strict_types=1);\n\$payload=json_decode((string)file_get_contents(".var_export($payloadFile,true)."),true,64,JSON_THROW_ON_ERROR);\n\$_POST=\$payload;\n\$_GET=[];\n\$_COOKIE=[];\n\$_REQUEST=\$_POST;\n\$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>".var_export($hostHeader,true).",'SERVER_NAME'=>".var_export($host,true).",'SERVER_PORT'=>".var_export((string)$port,true).",'HTTPS'=>".var_export($https?'on':'off',true).",'SERVER_PROTOCOL'=>'HTTP/1.1','REMOTE_ADDR'=>'127.0.0.1','REQUEST_URI'=>'/install/index.php','PHP_SELF'=>'/install/index.php','SCRIPT_NAME'=>'/install/index.php'];\nchdir(".var_export($root.'/install',true).");\nrequire ".var_export($root.'/install/index.php',true).";\n";
            if(file_put_contents($bridgeFile,$bridge,LOCK_EX)!==strlen($bridge))throw new RuntimeException('Could not write private installer bridge.');@chmod($bridgeFile,0600);
            foreach($steps as [$action,$fields,$expectedNext]){
                $journal['in_progress_step']=$action;$journal['updated_at']=gmdate(DATE_ATOM);self::writeJournal($journalPath,$journal);
                $payload=['action'=>$action]+$fields;if(file_put_contents($payloadFile,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)!==strlen(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)))throw new RuntimeException('Could not write private installer request data.');@chmod($payloadFile,0600);@unlink($outputFile);@unlink($errorFile);
                $pipes=[];$process=proc_open([PHP_BINARY,$bridgeFile],[0=>['pipe','r'],1=>['file',$outputFile,'w'],2=>['file',$errorFile,'w']],$pipes,$root.'/install');
                if(!is_resource($process))throw new RuntimeException('Could not launch the official MyBB installer process.');fclose($pipes[0]);$deadline=microtime(true)+300;$exitCode=null;
                do{$status=proc_get_status($process);if(!$status['running']){$exitCode=$status['exitcode'];break;}usleep(100000);}while(microtime(true)<$deadline);
                if($exitCode===null){proc_terminate($process);proc_close($process);throw new RuntimeException('Official MyBB installer exceeded the five-minute step limit.');}
                proc_close($process);$bytes=is_file($outputFile)?filesize($outputFile):false;if($exitCode!==0||$bytes===false||$bytes>4194304)throw new RuntimeException('Official MyBB installer process failed or returned an invalid response.');
                $response=(string)file_get_contents($outputFile);
                if($expectedNext!==null&&!preg_match('/name=["\']action["\']\s+value=["\']'.preg_quote($expectedNext,'/').'["\']/i',$response))throw new RuntimeException('Official MyBB installer did not complete the expected step.');
                if($action==='final'&&(!is_file($root.'/install/lock')||!is_file($root.'/inc/config.php')||!is_file($root.'/inc/settings.php')||!str_contains($response,'successfully been installed')))throw new RuntimeException('Official MyBB installer did not confirm completion and lock the installer.');
                $journal['last_completed_step']=$action;$journal['in_progress_step']=null;$journal['updated_at']=gmdate(DATE_ATOM);self::writeJournal($journalPath,$journal);
            }
        } finally {
            @unlink($payloadFile);@unlink($bridgeFile);@unlink($outputFile);@unlink($errorFile);@rmdir($tempDir);
        }
    }

    private static function readJournal(string $path,string $root,array $config):?array
    {
        if(!is_file($path))return null;if(is_link($path))throw new RuntimeException('Setup transaction journal cannot be a symlink.');$journal=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
        if(!is_array($journal)||($journal['schema_version']??null)!==1||($journal['site_root']??null)!==$root||($journal['driver']??null)!=='mysqli'||($journal['table_prefix']??null)!==$config['database']['table_prefix']||($journal['forum_url']??null)!==$config['forum']['url'])throw new RuntimeException('Setup transaction journal does not match this site and setup config.');return $journal;
    }

    private static function unsafeToRepeat(array $journal):bool
    {
        $writes=['create_tables','populate_tables','templates','configuration','adminuser','final'];return in_array($journal['in_progress_step']??null,$writes,true)||in_array($journal['last_completed_step']??null,$writes,true);
    }

    private static function writeJournal(string $path,array $journal):void
    {
        $temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';$bytes=json_encode($journal,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)){@unlink($temp);throw new RuntimeException('Could not write setup recovery journal.');}@chmod($temp,0600);if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Could not publish setup recovery journal.');}@chmod($path,0600);
    }

    private static function parseHost(string $host):array
    {
        if(preg_match('/^\[([0-9a-fA-F:]+)\]:(\d{1,5})$/',$host,$m))return [$m[1],(int)$m[2]];
        if(substr_count($host,':')===1&&preg_match('/^([^:]+):(\d{1,5})$/',$host,$m))return [$m[1],(int)$m[2]];
        return [$host,3306];
    }

    private static function setTerminalEcho(bool $enabled): void
    {
        $process = @proc_open(['stty', $enabled ? 'echo' : '-echo'], [0=>STDIN,1=>STDOUT,2=>STDERR], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not safely hide terminal password input; use a protected JSON setup file instead.');
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Could not safely configure terminal password input; use a protected JSON setup file instead.');
    }

    private static function requiredString(array $values,string $key,int $max,bool $allowEmpty=false):string
    {
        $value=$values[$key]??null;if(!is_string($value)||strlen($value)>$max||(!$allowEmpty&&trim($value)==='')||preg_match('/[\x00-\x1f\x7f]/',$value))throw new RuntimeException('Setup config field is missing or invalid: '.$key.'.');return $value;
    }

    private static function optionalString(array $values,string $key,string $default,int $max):string
    {
        if(!isset($values[$key]))return $default;if(!is_string($values[$key])||strlen($values[$key])>$max||preg_match('/[\x00-\x1f\x7f]/',$values[$key]))throw new RuntimeException('Setup config field is invalid: '.$key.'.');return $values[$key];
    }

    private static function validateWebUrl(string $url,string $field):void
    {
        $parts=parse_url($url);if(!is_array($parts)||!in_array($parts['scheme']??'', ['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new RuntimeException('Setup config field is invalid: '.$field.'.');
    }
}
