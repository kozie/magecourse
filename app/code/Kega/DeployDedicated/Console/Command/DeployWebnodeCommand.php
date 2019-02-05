<?php

namespace Kega\DeployDedicated\Console\Command;

use Kega\DeployDedicated\Helper\Deploy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Class DeployWebnodeCommand
 * @package Kega\DeployDedicated\Console\Command
 */
class DeployWebnodeCommand extends Command
{
    /**
     * @var Deploy
     */
    private $helper;

    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    /**
     * @var bool
     */
    private $error = false;

    /**
     * Set the state of the application to error
     */
    public function setError()
    {
        $this->error = true;
    }

    /**
     * @return bool
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName('update:webnode')
             ->setDescription('Deploy a webnode server.')
             ->setHelp('This command pulls updates from git and does all things necessary to get the new code running on' .
                'the servers that are not running the database.')
             ->addOption(Deploy::OPT_NO_DISABLE_MAINTENANCE, null, InputOption::VALUE_NONE,
                'Leave maintenance mode on afterwards')
             ->addOption(Deploy::OPT_NO_GIT, null, InputOption::VALUE_NONE, 'Skip git updates')
             ->addOption(Deploy::OPT_NO_UPGRADE, null, InputOption::VALUE_NONE, 'Skip bin/magento setup:upgrade');
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        declare(ticks=1);
        pcntl_signal(SIGINT, [__CLASS__, 'signalHandler']);

        $this->input = $input;
        $this->output = $output;
        $this->helper = new Deploy($input, $output, $this);

        try {
            $this->helper->enableMaintenanceMode();
            $this->helper->disableMaintenanceIps();
            $this->helper->disableCaches();
            $this->helper->flushCachePools();
            $this->helper->flushCacheDirs();
            $this->helper->flushDi();
            $this->helper->flushGeneration();
            $this->helper->gitPull();
            $this->helper->composerInstall();
            $this->helper->removeDevModulesFromConfig();
            $this->helper->updateModulesSequence();
            $this->helper->compileDi();
            $this->helper->enableCaches();
            $this->helper->flushOPC();
            $this->helper->flushCachePools();
            $this->helper->flushCacheDirs();
            $this->helper->runCustomScripts();
            $this->helper->enableMaintenanceIps();
            $this->helper->disableMaintenanceMode();
            if ($this->error) {
                $this->output->writeln('<error>Script ran with errors</error>');
            }
        } catch (\Exception $exception) {
            $this->setError();
            $this->output->writeln('<error>' . $exception->getMessage() . '</error>');
            if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                $this->output->write($exception->getTraceAsString(), true, OutputInterface::OUTPUT_RAW);
            }
            $this->close();
        }
    }

    /**
     * Trigger rollback procedures
     */
    private function close()
    {
        $this->setError();
        $this->helper->enableCaches();
        $this->helper->flushOPC();
        $this->helper->enableMaintenanceIps();
        $this->helper->disableMaintenanceMode();
        $this->output->writeln('<error>Script ran with errors</error>');
        exit(1);
    }

    /**
     * @param int $signo
     * @return void
     */
    private function signalHandler($signo)
    {
        if ($signo !== SIGINT) {
            return;
        }
        $this->output->writeln('<error>Interruption, starting rollback procedures</error>');
        $this->close();
    }
}