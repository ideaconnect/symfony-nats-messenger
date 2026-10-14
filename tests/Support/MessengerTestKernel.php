<?php

declare(strict_types=1);

namespace IDCT\NatsMessenger\Tests\Support;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A Symfony application with FrameworkBundle and Messenger, the bundles and configuration a test gives it, and a
 * directory of its own, for tests that need what Symfony compiles: the tags autoconfiguration adds, the
 * serializer decorators Messenger adds, the services an application's own files define.
 *
 * Building a NATS transport opens no connection, so a test can take one from the container and look at what the
 * factory and the serializer configuration made of it without a NATS server.
 */
final class MessengerTestKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $directory;

    /**
     * @param list<BundleInterface> $extraBundles The bundles besides FrameworkBundle
     * @param array<string, mixed>  $messenger The `framework.messenger` configuration
     * @param list<string>          $files     YAML configuration files, as the application's own config directory
     *                                         would hold them, imported in order
     */
    public function __construct(
        private readonly array $extraBundles,
        private readonly array $messenger,
        private readonly array $files = [],
    ) {
        $this->directory = sys_get_temp_dir() . '/nats-messenger-kernel-' . bin2hex(random_bytes(8));

        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield from $this->extraBundles;
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    public function boot(): void
    {
        $errorHandler = self::currentErrorHandler();
        $exceptionHandler = self::currentExceptionHandler();

        parent::boot();

        // FrameworkBundle::boot() registers Symfony's ErrorHandler, which can put its own handlers on top of
        // PHPUnit's. Take them off again, so that PHPUnit keeps handling what the test triggers.
        if (self::currentErrorHandler() !== $errorHandler) {
            restore_error_handler();
        }
        if (self::currentExceptionHandler() !== $exceptionHandler) {
            restore_exception_handler();
        }
    }

    /**
     * Boots the kernel and returns its container with the private services reachable, as `framework.test`
     * makes them for tests.
     */
    public function testContainer(): ContainerInterface
    {
        $this->boot();

        $container = $this->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    /** Shuts the kernel down and deletes its directory. */
    public function remove(): void
    {
        $this->shutdown();
        (new Filesystem())->remove($this->directory);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'messenger' => $this->messenger,
        ]);

        foreach ($this->files as $index => $yaml) {
            $file = $this->directory . '/config/' . $index . '.yaml';
            (new Filesystem())->dumpFile($file, $yaml);
            $container->import($file);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    /** PHP 8.5 has get_error_handler(); setting a handler and putting the old one back works on 8.2 too. */
    private static function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }
}
