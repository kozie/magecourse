<?php

namespace Koos\MageCourseTwo\Plugin\Model\Catalog;

use Magento\Catalog\Model\Product;

class ProductPlugin
{
    const PRICE_INCR = 18.13;

    public function afterGetPrice(Product $subject, $result)
    {
        if (is_numeric($result)) {
            return $result + self::PRICE_INCR;
        }

        return $result;
    }
}