<?php

/*
 * This file is part of the AdminBundle package.
 *
 * (c) Muhammad Surya Ihsanuddin <surya.kejawen@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonian\Indonesia\AdminBundle\Configuration;

use Symfonian\Indonesia\AdminBundle\Exception\RuntimeException;
use Symfonian\Indonesia\AdminBundle\SymfonianIndonesiaAdminConstants;
use Symfonian\Indonesia\AdminBundle\Util\MethodInvoker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

trait ConfigurationAwareTrait
{
    /**
     * @return ContainerInterface
     */
    abstract protected function getContainer();

    /**
     * @param string $key
     *
     * @return Configurator
     */
    protected function getConfigurator($key)
    {
        /** @var KernelInterface $kernel */
        $kernel = $this->getContainer()->get('kernel');
        /** @var Configurator $configurator */
        $configurator = $this->getContainer()->get('symfonian_id.admin.congiration.configurator');
        if ('prod' !== strtolower($kernel->getEnvironment())) {
            return $configurator;
        }

        return $this->fetchFromCache($configurator, $key);
    }

    /**
     * @param Configurator $configurator
     * @param $cacheKey
     *
     * @return Configurator
     *
     * @throws RuntimeException
     */
    private function fetchFromCache(Configurator $configurator, $cacheKey)
    {
        $cacheDir = $this->getContainer()->getParameter('kernel.cache_dir');
        $cacheFile = str_replace('\\', '_', $cacheKey);
        $fullPath = sprintf('%s/%s/%s.php.cache', $cacheDir, SymfonianIndonesiaAdminConstants::CACHE_DIR, $cacheFile);
        if (!file_exists($fullPath)) {
            //It's impossible but we need to prevent and make sure it is not throwing an exception
            return $configurator;
        }

        $configurations = require $fullPath;
        /** @var array $configuration */
        foreach ($configurations as $k => $configuration) {
            //$k is string presentate of config class
            $configurator->addConfiguration(MethodInvoker::bindSet($configuration, $configurator->getConfiguration($k)));
        }

        return $configurator;
    }
}
