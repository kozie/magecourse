<?php

namespace Koos\MageCourseTwo\Model;

use Koos\MageCourseTwo\Model\Config\Reader;
use Magento\Framework\Config\CacheInterface;
use Magento\Framework\Config\Data;
use Magento\Framework\Serialize\SerializerInterface;

class Config extends Data implements ConfigInterface
{
    public function __construct(
        Reader $reader,
        CacheInterface $cache,
        $cacheId = 'test_config',
        SerializerInterface $serializer = null
    )
    {
        parent::__construct($reader, $cache, $cacheId, $serializer);
    }

    public function getMyNodeInfo()
    {
        return $this->get();
    }
}