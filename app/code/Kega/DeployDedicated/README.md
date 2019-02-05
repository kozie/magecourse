# Kega Deploy Module

This deployer module is a new version of the deployer inside Kega_Core used for deployment on dedicated servers. This module is needed to split deploy logic for Cloud and Dedicated servers.

## New in this module

New in this module is the use of 'composer install'. It is recommended to no longer commit the vendor folder before using this module.

## How to use this deployer?

```
bin/deploy-dedicated update:admin [--keep-maintenance] [--no-git]
bin/deploy-dedicated update:webnode [--keep-maintenance] [--no-git]
```

Run following command to get all options:
bin/deploy-dedicated --help

We have created a new deploy command so that the old command will continue to work. This gives everyone the flexibility to choose when they want to switch to this new module.

## Common issue
Repo authentication required during composer install

## Change log
You can find the change log [here](CHANGELOG.md).