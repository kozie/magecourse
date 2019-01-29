<?php

namespace Koos\MageCourseTwo\Plugin\Block\Theme\Html;

class FooterPlugin
{
    public function afterGetCopyright(\Magento\Theme\Block\Html\Footer $subject, $result)
    {
        return "Customized copyright!";
    }
}