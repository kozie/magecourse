<?php

namespace Koos\MageCourseOne\Observer;

class BeforeAction implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->request = $request;
        $this->logger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->logger->critical(
            $this->request->getPathInfo()
        );
    }
}