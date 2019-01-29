<?php

namespace Koos\MageCourseTwo\Plugin\Block\Theme\Html;

class BreadcrumbsPlugin
{
    public function aroundAddCrumb(\Magento\Theme\Block\Html\Breadcrumbs $subject, callable $proceed, $crumbName, array $crumbInfo)
    {
        if (substr($crumbName, -3) != "(!)") {
            $crumbName .= "(!)";

            return $proceed($crumbName, $crumbInfo);
        }
    }

    public function afterAddCrumb(\Magento\Theme\Block\Html\Breadcrumbs $subject, $result, $crumbName, array $crumbInfo)
    {
        if (substr($crumbName, -3) != "(!)") {
            return $subject->addCrumb($crumbName . "(!)", $crumbInfo);
        }

        return $result;
    }
}