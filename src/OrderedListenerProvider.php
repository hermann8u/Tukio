<?php
declare(strict_types=1);

namespace Crell\Tukio;

use Crell\Tukio\Entry\ListenerEntry;
use Crell\Tukio\OrderedCollection\OrderedCollection;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

class OrderedListenerProvider implements ListenerProviderInterface, OrderedProviderInterface
{
    use ProviderUtilitiesTrait;

    /**
     * @var OrderedCollection
     */
    protected $listeners;

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container = null)
    {
        $this->listeners = new OrderedCollection();
        $this->container = $container;
    }

    public function getListenersForEvent(object $event): iterable
    {
        /** @var ListenerEntry $listener */
        foreach ($this->listeners as $listener) {
            if ($event instanceof $listener->type) {
                yield $listener->listener;
            }
        }
    }

    /**
     * Tries to get the type of a callable listener.
     *
     * If unable, throws an exception with information about the listener whose type could not be fetched.
     *
     * @param callable $listener
     * @return string
     */
    protected function getType(callable $listener)
    {
        try {
            $type = $this->getParameterType($listener);
        } catch (\InvalidArgumentException $exception) {
            if ($this->isClassCallable($listener) || $this->isObjectCallable($listener)) {
                throw InvalidTypeException::fromClassCallable($listener[0], $listener[1], $exception);
            }
            if ($this->isFunctionCallable($listener) || $this->isClosureCallable($listener)) {
                throw InvalidTypeException::fromFunctionCallable($listener, $exception);
            }
            throw new InvalidTypeException($exception);
        }
        return $type;
    }

    public function addListener(callable $listener, int $priority = 0, string $id = null, string $type = null): string
    {
        $type = $type ?? $this->getType($listener);
        $id = $id ?? $this->getListenerId($listener);

        return $this->listeners->addItem(new ListenerEntry($listener, $type), $priority, $id);
    }

    public function addListenerBefore(string $pivotId, callable $listener, string $id = null, string $type = null) : string
    {
        $type = $type ?? $this->getType($listener);
        $id = $id ?? $this->getListenerId($listener);

        return $this->listeners->addItemBefore($pivotId, new ListenerEntry($listener, $type), $id);
    }

    public function addListenerAfter(string $pivotId, callable $listener, string $id = null, string $type = null) : string
    {
        $type = $type ?? $this->getType($listener);
        $id = $id ?? $this->getListenerId($listener);

        return $this->listeners->addItemAfter($pivotId, new ListenerEntry($listener, $type), $id);
    }

    public function addListenerService(string $serviceName, string $methodName, string $type, int $priority = 0, string $id = null): string
    {
        $id = $id ?? $serviceName . '-' . $methodName;
        return $this->addListener($this->makeListenerForService($serviceName, $methodName), $priority, $id, $type);
    }

    public function addListenerServiceBefore(string $pivotId, string $serviceName, string $methodName, string $type, string $id = null) : string
    {
        $id = $id ?? $serviceName . '-' . $methodName;
        return $this->addListenerBefore($pivotId, $this->makeListenerForService($serviceName, $methodName), $id, $type);
    }

    public function addListenerServiceAfter(string $pivotId, string $serviceName, string $methodName, string $type, string $id = null) : string
    {
        $id = $id ?? $serviceName . '-' . $methodName;
        return $this->addListenerAfter($pivotId, $this->makeListenerForService($serviceName, $methodName), $id, $type);
    }

    /**
     * Creates a callable that will proxy to the provided service and method.
     *
     * @param string $serviceName
     *   The name of a service.
     * @param string $methodName
     *   A method on the service.
     * @return callable
     *   A callable that proxies to the the provided method and service.
     */
    protected function makeListenerForService(string $serviceName, string $methodName) : callable
    {
        if (!$this->container) {
            throw new ContainerMissingException();
        }

        // We cannot verify the service name as existing at this time, as the container may be populated in any
        // order.  Thus the referenced service may not be registered now but could be registered by the time the
        // listener is called.

        // Fun fact: We cannot auto-detect the listener target type from a container without instantiating it, which
        // defeats the purpose of a service registration. Therefore this method requires an explicit event type. Also,
        // the wrapping listener must listen to just object.  The explicit $type means it will still get only
        // the right event type, and the real listener can still type itself properly.
        $container = $this->container;
        $listener = function (object $event) use ($serviceName, $methodName, $container) : void {
            $container->get($serviceName)->$methodName($event);
        };
        return $listener;
    }

    public function addSubscriber(string $class, string $serviceName) : void
    {
        $proxy = new ListenerProxy($this, $serviceName, $class);

        // Explicit registration is opt-in.
        if (in_array(SubscriberInterface::class, class_implements($class))) {
            /** @var SubscriberInterface $class */
            $class::registerListeners($proxy);
        }

        try {
            $rClass = new \ReflectionClass($class);
            $methods = $rClass->getMethods(\ReflectionMethod::IS_PUBLIC);
            /** @var \ReflectionMethod $rMethod */
            foreach ($methods as $rMethod) {
                $methodName = $rMethod->getName();
                if (!in_array($methodName, $proxy->getRegisteredMethods()) && strpos($methodName, 'on') === 0) {
                    $params = $rMethod->getParameters();
                    $type = $params[0]->getType();
                    if (is_null($type)) {
                        throw InvalidTypeException::fromClassCallable($class, $rMethod->getName());
                    }
                    $this->addListenerService($serviceName, $rMethod->getName(), $type->getName());
                }
            }
        } catch (\ReflectionException $e) {
            throw new \RuntimeException('Type error registering subscriber.', 0, $e);
        }
    }
}
