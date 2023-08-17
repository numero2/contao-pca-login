<?php

/**
 * PCA login bundle for Contao Open Source CMS
 *
 * @author    Benny Born <benny.born@numero2.de>
 * @license   LGPL
 * @copyright Copyright (c) 2023, numero2 - Agentur für digitales Marketing GbR
 */


namespace numero2\PCALoginBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;


class PCALoginExtension extends Extension {


    public function load(array $mergedConfig, ContainerBuilder $container): void {

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../../config')
        );

        $loader->load('listener.yml');
        $loader->load('services.yml');
    }
}
