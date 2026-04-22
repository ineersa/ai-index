<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Wiring;

use Ineersa\AiIndex\Config\IndexConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

final class SymfonyContainerBuilderFactory
{
    public function create(IndexConfig $config): ContainerBuilder
    {
        $autoloadPath = $config->projectRoot.'/vendor/autoload.php';
        if (!is_file($autoloadPath)) {
            throw new \RuntimeException(sprintf('Composer autoload file not found at %s', $autoloadPath));
        }

        require_once $autoloadPath;

        $previousWorkingDirectory = getcwd();

        if (!@chdir($config->projectRoot)) {
            throw new \RuntimeException(sprintf('Unable to switch to project root: %s', $config->projectRoot));
        }

        try {
            $kernel = $this->createKernel($config);

            if (!$kernel instanceof Kernel) {
                throw new \RuntimeException(sprintf(
                    'Kernel instance must extend %s; got %s.',
                    Kernel::class,
                    $kernel::class,
                ));
            }

            $initializeBundles = \Closure::bind(
                fn (): mixed => $this->initializeBundles(),
                $kernel,
                Kernel::class,
            );
            $initializeBundles();

            $buildContainer = \Closure::bind(
                fn (): mixed => $this->buildContainer(),
                $kernel,
                Kernel::class,
            );

            $container = $buildContainer();
            if (!$container instanceof ContainerBuilder) {
                throw new \RuntimeException(sprintf(
                    'Kernel::buildContainer() must return %s, got %s.',
                    ContainerBuilder::class,
                    get_debug_type($container),
                ));
            }

            $passConfig = $container->getCompilerPassConfig();
            $passConfig->setRemovingPasses([]);
            $passConfig->setAfterRemovingPasses([]);

            $container->compile();

            return $container;
        } finally {
            if (false !== $previousWorkingDirectory) {
                @chdir($previousWorkingDirectory);
            }
        }
    }

    private function createKernel(IndexConfig $config): KernelInterface
    {
        $environment = (string) ($config->wiring['environment'] ?? 'test');
        $debug = (bool) ($config->wiring['debug'] ?? false);

        $_SERVER['APP_ENV'] = $environment;
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_DEBUG'] = $debug ? '1' : '0';
        $_ENV['APP_DEBUG'] = $debug ? '1' : '0';

        $kernelFactory = $config->wiring['kernelFactory'] ?? null;

        if (null !== $kernelFactory) {
            if (is_string($kernelFactory) && class_exists($kernelFactory) && is_subclass_of($kernelFactory, KernelInterface::class)) {
                /** @var class-string<KernelInterface> $kernelClass */
                $kernelClass = $kernelFactory;

                return new $kernelClass($environment, $debug);
            }

            if (is_callable($kernelFactory)) {
                $kernel = $kernelFactory($environment, $debug, $config->projectRoot);
                if (!$kernel instanceof KernelInterface) {
                    throw new \RuntimeException(sprintf(
                        'Configured kernelFactory callable must return %s, got %s.',
                        KernelInterface::class,
                        get_debug_type($kernel),
                    ));
                }

                return $kernel;
            }

            if (is_string($kernelFactory) && str_contains($kernelFactory, '::') && is_callable($kernelFactory)) {
                $kernel = call_user_func($kernelFactory, $environment, $debug, $config->projectRoot);
                if (!$kernel instanceof KernelInterface) {
                    throw new \RuntimeException(sprintf(
                        'Configured kernelFactory static callable must return %s, got %s.',
                        KernelInterface::class,
                        get_debug_type($kernel),
                    ));
                }

                return $kernel;
            }

            throw new \RuntimeException(sprintf(
                'Invalid wiring.kernelFactory value (%s). Provide a KernelInterface class name or callable.',
                is_string($kernelFactory) ? $kernelFactory : get_debug_type($kernelFactory),
            ));
        }

        if (class_exists('App\\Kernel') && is_subclass_of('App\\Kernel', KernelInterface::class)) {
            /** @var class-string<KernelInterface> $defaultKernelClass */
            $defaultKernelClass = 'App\\Kernel';

            return new $defaultKernelClass($environment, $debug);
        }

        throw new \RuntimeException(
            'Could not locate Symfony kernel class. Define wiring.kernelFactory in .ai-index.php (class name or callable).',
        );
    }
}
