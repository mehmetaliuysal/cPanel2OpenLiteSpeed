<?php

class TransferTool {
    private $yellow;
    private $green;
    private $red;
    private $boldRed;

    private $reset;

    private $cpanelUser;
    private $cpanelMainDomain;
    private $destinationUser;
    private $destinationServer;
    private $destinationBasePath;
    private $destinationFullPath;

    private $adminEmails;
    private $phpHandler;

    private $vhostPath;
    private $vhostOwner;

    private $cloudflareEmail;
    private $cloudflareAPIKey;
    private $cloudflareADNSRecordValue;

    public function __construct() {
        $this->yellow = "\033[1;33m";
        $this->green = "\033[0;32m";
        $this->red = "\033[0;31m";
        $this->boldRed = "\033[1;31m";
        $this->reset = "\033[0m";

        $this->loadConfig();
    }

    public function promptInputs() {
        echo "\n\n{$this->yellow}cPanel -> app-server (litespeed) transfer tool{$this->reset}\n\n";
        echo "Please enter the cPanel username: ";
        $this->cpanelUser = trim(fgets(STDIN));
        $this->destinationBasePath = str_replace('{cPanelUsername}', $this->cpanelUser, $this->destinationBasePath);
        $this->destinationFullPath = "{$this->destinationBasePath}/public_html";
        $this->cpanelMainDomain = $this->getCpanelMainDomain($this->cpanelUser);
    }

    public function checkAndCreateUserGroup() {
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'id {$this->cpanelUser} || (groupadd {$this->cpanelUser} && useradd -g {$this->cpanelUser} {$this->cpanelUser})'";
        $output = shell_exec($command);
        echo ($output) ? "{$this->green}User and group were checked. Created if necessary.{$this->reset}\n\n" : "";
    }

    public function createDirectoryAndAssignOwnership() {
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'mkdir -p {$this->destinationFullPath} && chown {$this->cpanelUser}:{$this->cpanelUser} {$this->destinationBasePath}'";
        $output = shell_exec($command);
        echo ($output) ? "{$this->green}Directory creation completed.{$this->reset}\n\n" : "";
    }

    public function transferFiles() {
        $command = "rsync -avz -e 'ssh -p 22 -o IdentitiesOnly=yes' /home/{$this->cpanelUser}/public_html/ {$this->destinationUser}@{$this->destinationServer}:{$this->destinationFullPath}";
        $output = shell_exec($command);
        echo ($output) ? "{$this->green}Files transferred to the server at {$this->destinationServer}/$this->destinationBasePath.{$this->reset}\n\n" : "";
    }

    public function assignOwnership() {
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'chown -R {$this->cpanelUser}:{$this->cpanelUser} {$this->destinationBasePath}'";
        $output = shell_exec($command);
        echo ($output) ? "{$this->green}File and directory ownerships have been updated.{$this->reset}\n\n" : "";
    }


    private function getCpanelMainDomain($username) {
        $filePath = "/var/cpanel/users/{$username}";

        if (!file_exists($filePath)) {
            throw new Exception("cPanel user configuration file does not exist for user: {$username}");
        }

        $userData = file_get_contents($filePath);
        preg_match('/^DNS=(.*)$/m', $userData, $matches);

        return $matches[1] ?? null;
    }

    public function createVhostFile() {


        if (!$this->cpanelMainDomain) {
            echo "{$this->red}Failed to fetch the main domain for cPanel user: {$this->cpanelUser}{$this->reset}\n";
            return;
        }

        $vhostPath4User = $this->vhostPath . '/' . $this->cpanelUser;
        $vhostFile4User =  $vhostPath4User . '/vhconf.conf';


        $vhostTemplate = <<<VHOST
docRoot                   {$this->destinationFullPath}
vhDomain                  {$this->cpanelMainDomain}
vhAliases                 *.{$this->cpanelMainDomain}
adminEmails               {$this->adminEmails}
enableGzip                1
enableBr                  1

errorlog \$SERVER_ROOT/logs/\$VH_NAME-error.log {
  useServer               0
  logLevel                ERROR
  rollingSize             100M
  keepDays                30
  compressArchive         0
}

index  {
  useServer               0
  indexFiles              index.html index.php
  autoIndex               0
}

scripthandler  {
  add                     {$this->phpHandler} php
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
}
VHOST;


        $createVhostDirCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'mkdir -p {$vhostPath4User}'";
        shell_exec($createVhostDirCommand);


        $vhostConfigReplaced = str_replace('{cPanelUsername}', $this->cpanelUser, $vhostTemplate);
        $vhostConfigEscaped = str_replace('$', '\\$', $vhostConfigReplaced);

        // SSH ile vhost dosyasını oluşturma ve içeriği doldurma
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'echo \"$vhostConfigEscaped\" > {$vhostFile4User}'";
        exec($command, $output, $return_var);
        if ($return_var === 0) {
            echo "{$this->green}Vhost file for {$this->cpanelUser} has been created.{$this->reset}\n\n";
        } else {
            echo "{$this->red}Failed to create Vhost file.{$this->reset}\n\n";
        }

        $ownershipCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'chown -R {$this->vhostOwner}:{$this->vhostGroup} {$vhostPath4User}'";
        shell_exec($ownershipCommand);
    }


    public function addVhostConfig() {
        $configBlock = <<<VHOST
virtualhost {$this->cpanelUser} {
  vhRoot                  $this->destinationBasePath
  configFile              conf/vhosts/{$this->cpanelUser}/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
  setUIDMode              0
  user                    {$this->cpanelUser}
  group                   {$this->cpanelUser}
}
VHOST;

        // SSH ile dosyayı okuma
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'cat {$this->lswsConfigPath}'";
        $output = shell_exec($command);
        if (strpos($output, "virtualhost {$this->cpanelUser}") === false) { // Eğer ilgili blok bulunmuyorsa
            $appendCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'echo \"\n$configBlock\" >> {$this->lswsConfigPath}'";
            shell_exec($appendCommand);
            echo "{$this->green}Virtualhost configuration for {$this->cpanelUser} has been added.{$this->reset}\n\n";
        } else {
            echo "{$this->yellow}Virtualhost configuration for {$this->cpanelUser} already exists. Skipping addition.{$this->reset}\n\n";
        }
    }


    public function modifyListener() {
        $mainDomain = $this->getCpanelMainDomain($this->cpanelUser);
        $mapEntry = "  map                     {$this->cpanelUser} {$mainDomain}";

        // SSH ile dosyayı okuma
        $command = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'cat {$this->lswsConfigPath}'";
        $configContent = shell_exec($command);

        $ports = [
            '443' => [
                'name' => 'Secure',
                'secure' => 1
            ],
            '8080' => [
                'name' => 'NonSecure',
                'secure' => 0
            ]
        ];

        foreach ($ports as $port => $details) {
            if (preg_match("/listener\s+[^\{]+\{\s*address\s+\*?:$port\s+secure\s+{$details['secure']}.*?\}/s", $configContent, $matches)) {
                $listenerBlock = $matches[0];

                $escapedMainDomain = preg_quote($mainDomain, '/');
                $escapedUserName = preg_quote($this->cpanelUser, '/');


                if (!preg_match("/map\s+$escapedUserName\s+$escapedMainDomain\s+\*\.\w*" . $escapedMainDomain . "/s", $listenerBlock)) {
                    // Listener bloğuna map entry ekle
                    $updatedBlock = str_replace('}', $mapEntry . "\n}", $listenerBlock);
                    $configContent = str_replace($listenerBlock, $updatedBlock, $configContent);

                    // SSH ile dosyaya yazma
                    $tempFile = tempnam(sys_get_temp_dir(), 'lsws');
                    file_put_contents($tempFile, $configContent);
                    $writeCommand = "cat {$tempFile} | ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'cat > {$this->lswsConfigPath}'";
                    shell_exec($writeCommand);
                    unlink($tempFile);

                    echo "{$this->green}Map entry for {$mainDomain} added to existing listener block for port $port.{$this->reset}\n\n";
                } else {
                    echo "{$this->yellow}Map entry for {$mainDomain} already exists in listener block for port $port. Skipping addition.{$this->reset}\n\n";
                }
            } else { // Eğer address *:port içeren listener bloğu yoksa
                $listenerBlock = <<<LISTENER

listener {$details['name']} $port {
  address                 *:$port
  secure                  {$details['secure']}
{$mapEntry}
}

LISTENER;

                $appendCommand = "echo \"$listenerBlock\" | ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} 'cat >> {$this->lswsConfigPath}'";
                shell_exec($appendCommand);
                echo "{$this->green}Listener block for $port added along with map entry.{$this->reset}\n\n";
            }
        }
    }


    public function createRemoteBashScript() {
        $bashScriptContent = <<<'EOD'
#!/bin/bash

TARGET_PATH="$1"

if [ -f "$TARGET_PATH/.htaccess" ]; then
    awk 'BEGIN {IGNORECASE=1}
    /<IfModule/,/<\/IfModule>/ { next }
    /<FilesMatch/,/<\/FilesMatch>/ { next }
    /<\/IfModule>/ { next }
    /<\/FilesMatch>/ { next }
    { print }
    ' "$TARGET_PATH/.htaccess" > /tmp/.htaccess.tmp

    if [ $? -eq 0 ]; then
        mv /tmp/.htaccess.tmp "$TARGET_PATH/.htaccess"
        echo "success"
    else
        echo "awk_error"
    fi
else
    echo "notfound"
fi

EOD;

        $localTmpScriptPath = sys_get_temp_dir() . '/update_htaccess.sh';
        file_put_contents($localTmpScriptPath, $bashScriptContent);

        $remoteScriptPath = '/tmp/update_htaccess.sh';
        $scpCommand = "scp -o IdentitiesOnly=yes $localTmpScriptPath {$this->destinationUser}@{$this->destinationServer}:$remoteScriptPath";
        exec($scpCommand, $output, $returnVar);

        if ($returnVar !== 0) {
            echo "{$this->red}Error occurred while copying the bash script to the remote server.{$this->reset}\n";
            exit(1);
        }

        $sshCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} ";
        $chmodCommand = "chmod +x $remoteScriptPath";
        exec($sshCommand . $chmodCommand, $chmodOutput, $chmodReturnVar);

        if ($chmodReturnVar !== 0) {
            echo "{$this->red}Failed to set execute permissions for the bash script on the remote server.{$this->reset}\n";
            exit(1);
        }

        echo "{$this->green}Bash script created successfully on the remote server.{$this->reset}\n";
    }




    public function updateHtaccess() {
        $sshCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} ";

        // Bash scripti kullanarak .htaccess dosyasını güncelle
        $runBashScriptCommand = "/tmp/update_htaccess.sh {$this->destinationFullPath}";

        exec($sshCommand . $runBashScriptCommand, $output, $returnVar);

        if ($returnVar === 0) {
            if (in_array("success", $output)) {
                echo "{$this->green}Htaccess file updated successfully.{$this->reset}\n";
            } elseif (in_array("awk_error", $output)) {
                echo "{$this->yellow}Htaccess cannot be updated. There was an error while processing the file.{$this->reset}\n";
                return;
            } else {
                echo "{$this->yellow}Htaccess file not found.{$this->reset}\n";
            }
        } else {
            echo "{$this->red}There was an error executing the bash script on the remote server.{$this->reset}\n";
            return;
        }
    }



    public function restartLiteSpeedGracefully() {
        $sshCommand = "ssh -o IdentitiesOnly=yes {$this->destinationUser}@{$this->destinationServer} ";

        // LiteSpeed sunucusunu graceful şekilde yeniden başlat
        $runGracefulRestartCommand = "/usr/local/lsws/bin/lswsctrl restart";

        exec($sshCommand . $runGracefulRestartCommand, $output, $returnVar);

        if ($returnVar === 0) {
            if (strpos(implode("\n", $output), "SIGUSR1") !== false) {
                echo "{$this->green}LiteSpeed server restarted gracefully.{$this->reset}\n";
            } else {
                echo "{$this->yellow}There might have been an issue while restarting LiteSpeed gracefully. Check server logs for more info.{$this->reset}\n";
                return;
            }
        } else {
            echo "{$this->red}There was an error executing the graceful restart command on the remote server.{$this->reset}\n";
            return;
        }
    }





    private function loadConfig() {


        if (!file_exists('transfer.conf')) {
            echo "{$this->red}Configuration file 'transfer.conf' not found.{$this->reset}\n";
            exit;
        }

        $lines = file('transfer.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            list($key, $value) = explode('=', $line, 2);
            $config[$key] = $value;
        }

        // Expected keys in the config file
        $expectedKeys = [
            'destinationUser', 'destinationServer', 'destinationBasePath',
            'destinationFullPath', 'vhostPath', 'adminEmails', 'phpHandler',
            'vhostOwner', 'vhostGroup', 'lswsConfigPath'
        ];

        foreach ($expectedKeys as $key) {
            if (!isset($config[$key])) {
                echo "{$this->red}Missing configuration key: {$key}. Please check your 'transfer.conf'.{$this->reset}\n";
                exit;
            }
        }


        $this->destinationUser = $config['destinationUser'];
        $this->destinationServer = $config['destinationServer'];
        $this->destinationBasePath = $config['destinationBasePath'];
        $this->destinationFullPath = $config['destinationFullPath'];
        $this->vhostPath = $config['vhostPath'];
        $this->adminEmails = $config['adminEmails'];
        $this->phpHandler = $config['phpHandler'];


        $this->lswsConfigPath = $config['lswsConfigPath'] ?? '/usr/local/lsws/conf/httpd_config.conf';
        $this->vhostOwner = $config['vhostOwner'];
        $this->vhostGroup = $config['vhostGroup'];

        if (array_key_exists('cloudflareAPIKey', $config)) {
            $this->cloudflareAPIKey = $config['cloudflareAPIKey'];
        }

        if (array_key_exists('cloudflareEmail', $config)) {
            $this->cloudflareEmail = $config['cloudflareEmail'];
        }

        if (array_key_exists('cloudflareADNSRecordValue', $config)) {
            $this->cloudflareADNSRecordValue = $config['cloudflareADNSRecordValue'];
        }
    }

    public function run() {
        $startTime = microtime(true);

        $this->promptInputs();
        $this->checkAndCreateUserGroup();
        $this->createDirectoryAndAssignOwnership();
        $this->transferFiles();
        $this->assignOwnership();
        $this->createVhostFile();
        $this->addVhostConfig();
        $this->modifyListener();
        $this->createRemoteBashScript();
        $this->updateHtaccess();


        if ($this->cloudflareAPIKey !== null && $this->cloudflareEmail) {
            require 'cloudflare-api.php';
            $cloudFlareApi = new CloudflareAPI($this->cloudflareAPIKey, $this->cloudflareEmail);
            $update = $cloudFlareApi->upsertDNSARecord($this->cpanelMainDomain, $this->cloudflareADNSRecordValue);

            if ($update['status']) {
                echo "{$this->green}DNS record updated successfully.{$this->reset}\n";
            } else {
                echo "{$this->red}There was an error updating DNS record:" . $update['message'] . "{$this->reset}\n";
            }

            $update = $cloudFlareApi->setSSLMode($this->cpanelMainDomain, 'full');
            if ($update['status']) {
                echo "{$this->green}SSL mod changed successfully [Full].{$this->reset}\n";
            } else {
                echo "{$this->red}There was an error changing SSL mode:" . $update['message'] . "{$this->reset}\n";
            }
        }

        $this->restartLiteSpeedGracefully();


        $endTime = microtime(true);
        $elapsedTime = round($endTime - $startTime, 2);


        echo "{$this->green}Elapsed time: {$elapsedTime} seconds{$this->reset}\n\n";
    }
}

$transferTool = new TransferTool();
$transferTool->run();
