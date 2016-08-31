<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare (strict_types=1);

namespace Cawa\Clockwork;

use Cawa\App\HttpFactory;
use Cawa\Clockwork\Storage\StorageInterface;
use Cawa\Core\DI;
use Cawa\Events\DispatcherFactory;
use Cawa\Events\TimerEvent;
use Cawa\Log\Event;
use Cawa\Router\Route;
use Cawa\Router\RouterFactory;
use Cawa\Serializer\Serializer;
use Cawa\Session\SessionFactory;

class Module extends \Cawa\App\Module
{
    use HttpFactory;
    use RouterFactory;
    use SessionFactory;
    use DispatcherFactory;

    /**
     * @return bool
     */
    public function init() : bool
    {
        if (self::request()->getHeader('X-Clockwork')) {
            self::router()->addRoutes([
                (new Route())
                    ->setName('clockwork.request')
                    ->setMatch('/__clockwork/(?<id>.*)')
                    ->setController('Cawa\\Clockwork\\Controller'),
            ]);

            $this->id = uniqid();

            self::dispatcher()->addListenerByClass('\\Cawa\\Events\\TimerEvent', [$this, 'onTimerEvent']);
            self::dispatcher()->addListenerByClass('\\Cawa\\Log\\Event', [$this, 'onLogEvent']);

            self::dispatcher()->addListener('app.end', [$this, 'onEnd']);

            $this->storage = self::getStorage();

            return true;
        }

        return false;
    }

    /**
     * @return StorageInterface
     */
    public static function getStorage()
    {
        $config = DI::config()->getIfExists('clockwork');
        $class = 'Cawa\\Clockwork\\Storage\\' . ($config ? $config['type'] : 'Session');

        if ($config) {
            return new $class(... $config['config']);
        } else {
            return new $class();
        }
    }

    /**
     * @var string
     */
    private $id;

    /**
     * @var StorageInterface
     */
    private $storage;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @param TimerEvent $event
     */
    public function onTimerEvent(TimerEvent $event)
    {
        $name = ucfirst($event->getNamespace());
        $type = ucfirst($event->getType());

        $data = $event->getData();
        $data['start'] = $event->getStart() ;
        $data['duration'] = $event->getDuration() * 1000;
        $this->data['data'][$name][$type][] = $data;
    }

    /**
     * @param \Cawa\Log\Event $event
     */
    public function onLogEvent(Event $event)
    {
        $message = $event->getMessage();
        if ($event->getContext()) {
            $isAssociative = array_keys($event->getContext()) !== range(0, count($event->getContext()) - 1);

            if ($isAssociative) {
                $message .= ' [' . implode('] [', array_map(
                        function ($v, $k) {
                            return sprintf(
                                '%s: %s',
                                ucfirst(strtolower($k)),
                                is_array($v) ? json_encode($v) : $v
                            );
                        },
                        $event->getContext(),
                        array_keys($event->getContext())
                    )) . ']';
            } else {
                $message .= ' [Context: ' . json_encode($event->getContext()) . ']';
            }
        }

        $this->data['log'][] = [
            'time' => $event->getDate()->format('U.u'),
            'level' => $event->getLevel(),
            'message' =>  $message,
        ];
    }

    /**
     * @param callable $listener
     *
     * @return string
     */
    private function getControllerName($listener) : string
    {
        if (is_array($listener)) {
            if (is_string($listener[0])) {
                return $listener[0] . '::' . $listener[1];
            } else {
                return get_class($listener[0]) . '::' . $listener[1];
            }
        } elseif (is_string($listener)) {
            return $listener;
        } else {
            $reflection = new \ReflectionFunction($listener);

            return $reflection->getClosureScopeClass()->getName() . '::' .
                'closure[' . $reflection->getStartLine() . ':' .
                $reflection->getEndLine()  . ']';
        }
    }

    /**
     *
     */
    public function onEnd()
    {
        self::response()->addHeader('X-Clockwork-Id', $this->id);
        self::response()->addHeader('X-Clockwork-Version', '2.0');

        $this->generateData();
        $this->storage->set($this->id, $this->data);
    }

    /**
     *
     */
    protected function generateData()
    {
        $this->data['id'] = $this->id;
        $start = self::request()->getServer('REQUEST_TIME_FLOAT');
        $end = microtime(true);

        $this->data['time'] = $start;
        $this->data['method'] = self::request()->getMethod();
        $this->data['uri'] = (string) self::request()->getUri()->get(false);

        if (self::router()->current()) {
            $this->data['controller'] = $this->getControllerName(self::router()->current()->getController());
        }
        $this->data['memory'] = memory_get_peak_usage(true);

        // request data
        foreach (self::request()->getCookies() as $cookie) {
            $this->data['request']['cookies'][$cookie->getName()] = $cookie->getValue();
        }

        $this->data['request']['requestHeaders'] = self::request()->getHeaders();
        $this->data['request']['responseHeaders'] = self::response()->getHeaders();
        $this->data['request']['get'] = self::request()->getUri()->getQueries() ?? [];
        $this->data['request']['post'] = self::request()->getPosts() ?? [];
        $this->data['request']['files'] = self::request()->getUploadedFiles() ?? [];
        $this->data['request']['server'] = self::request()->getServers() ?? [];

        // session data
        if (self::session()->isStarted()) {
            $session = self::session()->getData();

            foreach ($session as $key => $value) {
                if (is_object($value)) {
                    $session[$key] = Serializer::serialize($value);
                }
            }

            $this->data['request']['session'] = $session;

            unset($this->data['request']['session']['clockwork']);
        }

        foreach ($this->data['request'] as $type => $data) {
            if (!$data) {
                unset($this->data['request'][$type]);
            }
        }

        // duration
        $this->data['responseTime'] = $end ;
        $this->data['responseStatus'] = self::response()->getStatus();
        $this->data['responseDuration'] = ($end - $start) * 1000;

        $this->data['timelineData'] = $this->data['timelineData'] ?? [];

        $this->data['timelineData'] = array_merge(['Total execution time' => [[
            'start' => $start,
            'end' => $end,
            'duration' =>  ($end - $start) * 1000,
        ]]], $this->data['timelineData']);
    }
}
