<?php

namespace Koos\MageCourseTwo\Observer;

class Log implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Koos\MageCourseTwo\Model\Config\ConfigInterface
     */
    private $config;

    /**
     * Log constructor.
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Koos\MageCourseTwo\Model\ConfigInterface $config
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        \Koos\MageCourseTwo\Model\ConfigInterface $config
    )
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $myNodeInfo = $this->config->getMyNodeInfo();
        if (is_array($myNodeInfo)) {
            foreach ($myNodeInfo as $str) {
                $this->logger->critical($str);
            }
        }
    }
}