<?php

namespace Kega\DeployDedicated\Helper;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Deploy
 * @package Kega\DeployDedicated\Helper
 */
class Deploy
{
    const OPT_NO_DISABLE_MAINTENANCE = 'keep-maintenance';
    const OPT_NO_GIT = 'no-git';
    const OPT_NO_UPGRADE = 'no-upgrade';

    const CD = 'cd ' . BP . ' && ';
    const PHP = '/usr/bin/env php ';
    const CURL = '/usr/bin/env curl ';
    const NPROC = '/usr/bin/env nproc ';
    const SYSCTL = '/usr/bin/env sysctl -n hw.ncpu ';
    const REDIS = '/usr/bin/env redis-cli ';
    const GIT = self::CD . '/usr/bin/env git ';
    const COMPOSER = '/usr/bin/env composer ';
    const COMPOSER_ARGS = ' --optimize-autoloader '; // --no-dev
    const RM = 'rm -rfv ';
    const ERR = ' 2>&1';
    const MAGENTO = self::PHP . BP . '/bin/magento ';
    const CUSTOM_DEPLOY_SCRIPT_PATH = BP . '/bin/custom_deploy_scripts';

    const STATIC_EMPTY = ".\n..\n.htaccess\n";
    const BACKUP_SUFFIX = '.deployment.bak';

    /**
     * @var array
     */
    private $caches = [];

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var \Kega\DeployDedicated\Console\Command\DeployAdminCommand|\Kega\DeployDedicated\Console\Command\DeployWebnodeCommand
     */
    private $command;

    /**
     * @var \Zend_Db_Adapter_Pdo_Mysql
     */
    private $db;

    /**
     * Deploy constructor.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param \Kega\DeployDedicated\Console\Command\DeployWebnodeCommand|\Kega\DeployDedicated\Console\Command\DeployAdminCommand|\Kega\DeployDedicated\Console\Command\KegaOpCacheFlushCommand $command
     */
    public function __construct(InputInterface $input, OutputInterface $output, $command)
    {
        $this->input = $input;
        $this->output = $output;
        $this->command = $command;
    }

    /**
     * Get a database connection instance
     *
     * @return \Zend_Db_Adapter_Pdo_Mysql
     * @throws \Exception
     */
    private function getDb()
    {
        if (!$this->db) {
            $config = include(BP . '/app/etc/env.php');
            if (!isset($config['db']['connection']['default'])) {
                throw new \Exception('Cannot read app/etc/env.php config');
            }
            $this->db = new \Zend_Db_Adapter_Pdo_Mysql($config['db']['connection']['default']);
        }
        return $this->db;
    }

    /**
     * Get a cache pools
     *
     * @return array
     * @throws \Exception
     */
    private function getCacheSettings()
    {
        $config = include(BP . '/app/etc/env.php');
        $pools = [];
        if (isset($config['cache']['frontend']['default']['backend'])
            && isset($config['cache']['frontend']['default']['backend_options'])
            && $config['cache']['frontend']['default']['backend'] === 'Cm_Cache_Backend_Redis'
        ) {
            $pools[] = $config['cache']['frontend']['default']['backend_options'];
        }
        if (isset($config['cache']['frontend']['page_cache']['backend'])
            && isset($config['cache']['frontend']['page_cache']['backend_options'])
            && $config['cache']['frontend']['page_cache']['backend'] === 'Cm_Cache_Backend_Redis'
        ) {
            $pools[] = $config['cache']['frontend']['page_cache']['backend_options'];
        }
        return $pools;
    }

    /**
     * Get a cache pools
     *
     * @return array
     * @throws \Exception
     */
    private function getCachesEnabled()
    {
        $config = include(BP . '/app/etc/env.php');
        $enabled = [];
        if (isset($config['cache_types'])
        ) {
            foreach ($config['cache_types'] as $key => $status) {
                if (!$status) {
                    continue;
                }
                $enabled[] = $key;
            }
        }
        return $enabled;
    }

    /**
     * Enable maintenance
     * Doing this without bin/magento is faster
     *
     * @return void
     */
    public function enableMaintenanceMode()
    {
        $this->output->writeln('<info>Enabled maintenance mode</info>');
        touch(BP . '/' . MaintenanceMode::FLAG_DIR . '/' . MaintenanceMode::FLAG_FILENAME);
    }

    /**
     * Disable maintenance
     * Doing this without bin/magento is faster
     *
     * @return void
     */
    public function disableMaintenanceMode()
    {
        if (!$this->input->getOption(self::OPT_NO_DISABLE_MAINTENANCE) && !$this->command->getError()) {
            $this->output->writeln('<info>Disabled maintenance mode</info>');
            @unlink(BP . '/' . MaintenanceMode::FLAG_DIR . '/' . MaintenanceMode::FLAG_FILENAME);
            return;
        }
        $this->output->writeln('<info>Keeping maintenance mode enabled</info>');
    }

    /**
     * Disable maintenance ips
     */
    public function enableMaintenanceIps()
    {
        $original = BP . '/' . MaintenanceMode::FLAG_DIR . '/' . MaintenanceMode::IP_FILENAME;
        $backup = $original . self::BACKUP_SUFFIX;
        if (!is_file($backup)) {
            return;
        }
        $this->output->writeln('<info>Temporarily disable maintenance ips</info>');
        rename($backup, $original);
    }

    /**
     * Enable maintenance ips
     */
    public function disableMaintenanceIps()
    {
        $original = BP . '/' . MaintenanceMode::FLAG_DIR . '/' . MaintenanceMode::IP_FILENAME;
        $backup = $original . self::BACKUP_SUFFIX;
        if (!is_file($original)) {
            return;
        }
        $this->output->writeln('<info>Temporarily disable maintenance ips</info>');
        rename($original, $backup);
    }

    /**
     * Disable all caches
     */
    public function disableCaches()
    {
        $this->output->writeln('<info>Disabling all caches</info>');
        $this->caches = $this->getCachesEnabled();
        $this->runCommand(self::MAGENTO . 'cache:disable');
    }

    /**
     * Restore all caches that were enabled before
     * Resolves errors when unsupported cache types are enabled in app/etc/env.php
     */
    public function enableCaches()
    {
        if (!$this->caches) {
            $this->output->writeln('<info>No cache to enable</info>');
            return;
        }
        $invalidCacheTypes = [];
        $this->output->writeln('<info>Enabling previously enabled caches</info>');
        $result = $this->runCommand(self::MAGENTO . 'cache:enable ' . implode(' ', $this->caches));
        if (strpos($result, 'InvalidArgumentException') !== false) {
            $this->output->writeln('<error>Cache config error, trying to resolve</error>');
            if (preg_match("#Supported types: ([\w\s\n,]+)(?=\n\n)#", $result, $matches) && isset($matches[1])) {
                $caches = explode(',', preg_replace('#[^a-z_,]#', '', $matches[1]));
                $invalidCacheTypes = array_diff($this->caches, $caches);
                $this->caches = array_intersect($this->caches, $caches);
            }
            $result = $this->runCommand(self::MAGENTO . 'cache:enable ' . implode(' ', $this->caches));
            if (!$this->caches || strpos($result, 'InvalidArgumentException') !== false) {
                $this->output->writeln('<error>Cache cannot be enabled</error>');
                $this->command->setError();
                return;
            }
            $this->output->writeln('<info>Cache config error resolved successfully</info> <error>but these cache types are not supported and should be removed from app/etc/env.php: ' .
                implode(', ', $invalidCacheTypes) . '</error>');
            return;
        }
    }

    /**
     * Remove the contents of var/cache and var/page_cache
     *
     * @return void
     */
    public function flushCacheDirs()
    {
        $this->output->writeln('<info>Flushing cache directories</info>');
        $this->runCommand(self::RM . BP . '/' . DirectoryList::VAR_DIR . '/' . DirectoryList::CACHE . '/*');
        $this->runCommand(self::RM . BP . '/' . DirectoryList::VAR_DIR . '/page_cache/*');
    }


    /**
     * Remove the contents of the redis
     *
     * @return void
     */
    public function flushCachePools()
    {
        if ($pools = $this->getCacheSettings()) {
            $this->output->writeln('<info>Flushing redis cache pools</info>');
        }
        foreach ($pools as $pool) {
            $this->runCommand(self::REDIS . "-h {$pool['server']} -p {$pool['port']} flushall");
        }
    }

    /**
     * Remove the contents of var/generation
     *
     * @return void
     */
    public function flushGeneration()
    {
        $this->output->writeln('<info>Flushing generation</info>');
        $this->runCommand(self::RM . BP . '/generated/code/*');
        $this->runCommand(self::RM . BP . '/generated/metadata/*');
    }

    /**
     * Remove the var/di dir
     *
     * @return void
     */
    public function flushDi()
    {
        $this->output->writeln('<info>Flushing di</info>');
        $this->runCommand(self::RM . BP . '/var/di');
    }

    /**
     * Remove the contents of pub/static (except .htaccess) and var/view_preprocessed
     *
     * @return void
     * @throws \Exception
     */
    public function flushStaticContent()
    {
        $this->output->writeln('<info>Flushing static content</info>');

        $this->runCommand(self::RM . BP . '/pub/static/*');
        $this->runCommand(self::RM . BP . '/var/view_preprocessed/*');
        $this->runCommand(self::RM . BP . '/var/view_preprocessed/.DS_Store');

        if (shell_exec('ls -a1 ' . BP . '/pub/static' . self::ERR) !== self::STATIC_EMPTY) {
            $this->runCommand(self::GIT . 'checkout pub/static/.htaccess');
        }
        if (shell_exec('ls -a1 ' . BP . '/pub/static' . self::ERR) !== self::STATIC_EMPTY) {
            throw new \Exception('Contents of ' . BP . '/pub/static are not as expected');
        }
    }

    /**
     * Pull updates from git
     *
     * @return void
     * @throws \Exception
     */
    public function gitPull()
    {
        if ($this->input->getOption(self::OPT_NO_GIT)) {
            $this->output->writeln('<info>Skipping updates from git</info>');
            return;
        }
        $this->output->writeln('<info>Pulling updates from git</info>');

        $result = $this->runCommand(self::GIT . 'pull --rebase');
        if (strpos($result, 'error:') !== false ||
            strpos($result, 'fatal:') !== false ||
            strpos($result, 'Cannot pull with rebase') !== false
        ) {
            throw new \Exception('Git could not fetch updates. Use --no-git to skip');
        }
    }

    /**
     * Composer install without dev modules
     *
     * @throws \Exception
     */
    public function composerInstall()
    {
        $this->output->writeln('<info>Installing all dependencies</info>');

        $result = $this->runCommand(self::COMPOSER . 'install' . self::COMPOSER_ARGS, true);
        if (strpos($result, 'command not found') !== false) {
            throw new \Exception('Composer not installed');
        }
    }

    /**
     * Remove Magento modules used in require-dev from app/etc/config.php
     *
     * @TODO: Get dev modules from composer.json
     * @TODO: Save php array new syntax array[]; instead of array();
     */
    public function removeDevModulesFromConfig()
    {
        $this->output->writeln('<info>Remove dev-modules from app/etc/config.php</info>');
        $devModules = $this->getDevModules();
        $filename = BP . '/app/etc/config.php';
        $config = include($filename);

        if (!empty($devModules)) {
            foreach ($devModules as $module) {
                if (isset($config['modules'][$module])) {
                    unset($config['modules'][$module]);
                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->output->writeln('Removed: ' . $module);
                    }
                } else {
                    if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                        $this->output->writeln('Not installed: ' . $module);
                    }
                }
            }
            $updatedConfig = "<?php\nreturn " . \var_export($config, true) . ";";

            file_put_contents($filename, $updatedConfig,LOCK_EX);
        }
    }

    /**
     * Update the contents of app/etc/config.xml
     */
    public function updateModulesSequence()
    {
        $this->output->writeln('<info>Updating app/etc/config.php</info>');
        $this->runCommand(self::MAGENTO . 'kega:update-modules-sequence');
    }

    /**
     * - Create an opcode cache flush file
     * - Request the opcode cache flush file via the web (with hosts file entry emulation)
     * - Delete the opcode cache flush file
     *
     * @return void
     * @throws \Exception
     */
    public function flushOPC()
    {
        $this->output->writeln('<info>Flushing opcode cache</info>');

        /**
         * Try to flush OPC on each found server IP address. Break on success.
         */
        try {
            $result = $this->runCommand('reset-php-cache');
            if (strpos($result, 'The opcache has been reset') !== false) {
                $this->output->writeln('<info>Opcache successfully flushed</info>');
                return;
            }
        } catch (\Exception $exception) {
            // continue to failed message
        }
        $this->output->writeln('<error>OPC flush failed, flush manually</error>');
        $this->command->setError();
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function deployStaticContent()
    {
        $this->output->writeln('<info>Deploying static content</info>');

        $db = $this->getDb();
        $select = $db->select()->distinct()->from('core_config_data', null)
            ->joinInner('theme', 'theme_id=value OR area="adminhtml"', 'code')
            ->where('path LIKE ?', 'design/theme/theme_id');
        $themes = $db->fetchCol($select);
        $select = $db->select()->from('core_config_data', 'value')
            ->where('path LIKE ?', 'general/locale/code');
        $languages = $db->fetchCol($select);
        $languages[] = 'en_US';
        $languages[] = 'nl_NL';
        $languages = array_unique($languages);

        // Amount of jobs is max the amount of processor cores
        $lang = implode(' ', $languages);
        $processors = trim(shell_exec(self::NPROC . self::ERR));
        if (!intval($processors)) {
            $processors = trim(shell_exec(self::SYSCTL . self::ERR));
            if (!intval($processors)) {
                $processors = 1;
            }
        }
        $this->output->writeln('<info>System has ' . $processors . ' processors, deploying with max ' . $processors . ' jobs</info>');
        $jobs = min(count($languages) * count($themes), $processors);

        $this->output->writeln('<info>Languages: ' . $lang . '</info>');
        foreach ($themes as &$theme) {
            $theme = '--theme="' . $theme . '"';
        }
        $theme = implode(' ', $themes);


        $this->runCommand(self::MAGENTO . "setup:static-content:deploy --jobs=$jobs $theme $lang", true);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function compileDi()
    {
        $this->output->writeln('<info>Compiling DI</info>');

        $command = self::MAGENTO . "setup:di:compile";
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $command .= ' -vv';
        }
        $this->runCommand($command, true);
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function setupUpgrade()
    {
        if ($this->input->getOption(self::OPT_NO_UPGRADE)) {
            $this->output->writeln('<info>Skipping upgrade scripts</info>');
            return;
        }
        $this->output->writeln('<info>Running upgrade scripts</info>');
        $this->runCommand(self::MAGENTO . "setup:upgrade", true);
    }

    /**
     * Run custom deploy scripts
     */
    public function runCustomScripts()
    {
        if (!is_dir(self::CUSTOM_DEPLOY_SCRIPT_PATH)) {
            $this->output->writeln('<info>No custom deploy script directory: ' . self::CUSTOM_DEPLOY_SCRIPT_PATH . '</info>');
            return;
        }
        $customFiles = array_diff(scandir(self::CUSTOM_DEPLOY_SCRIPT_PATH), ['..', '.', '.gitkeep']);
        if (!$customFiles) {
            $this->output->writeln('<info>No custom deploy scripts to run in ' . self::CUSTOM_DEPLOY_SCRIPT_PATH . '</info>');
            return;
        }
        foreach ($customFiles as $customFile) {
            $script = self::CUSTOM_DEPLOY_SCRIPT_PATH . '/' . $customFile;
            $this->output->writeln('<info>Running custom deploy script: ' . $script . '</info>');
            $this->runCommand($script, true);
        }
    }

    /**
     * @param $command
     * @return string|bool
     */
    private function runCommand($command, $directOutput = false)
    {
        if ($this->output->getVerbosity() <= OutputInterface::VERBOSITY_NORMAL) {
            $directOutput = false;
        }
        if (!$directOutput) {
            $command .= self::ERR;
        }
        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln('<comment>' . $command . '</comment>');
        }
        if (!$directOutput) {
            $result = shell_exec($command);
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->output->write($result, true, OutputInterface::OUTPUT_RAW);
            }
            return $result;
        }
        passthru($command);
        return true;
    }

    /**
     * This (Magento) modules are only used on dev. We need to remove these from app/etc/config.php
     *
     * For client specific modules we can extend this function.
     *
     * @return array
     */
    public function getDevModules()
    {
        // @TODO: Get dev modules from composer.json
        return [];
    }
}
