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

use Cawa\App\App;
use Cawa\Clockwork\Storage\StorageInterface;
use Cawa\Core\DI;
use Cawa\Events\DispatcherFactory;
use Cawa\Events\TimerEvent;
use Cawa\Log\Event;
use Cawa\Orm\TraitSerializable;
use Cawa\Router\Route;
use Cawa\Session\SessionFactory;

class Module extends \Cawa\App\Module
{
    use SessionFactory;
    use DispatcherFactory;
    use TraitSerializable;

    /**
     * @return bool
     */
    public function init() : bool
    {
        if (App::request()->getHeader('X-Clockwork')) {
            App::router()->addRoutes([
                Route::create()
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
            $message .= ' [' . implode('] [', array_map(
                function ($v, $k) {
                    return sprintf('%s: %s', ucfirst(strtolower($k)), $v);
                },
                $event->getContext(),
                array_keys($event->getContext())
            )) . ']';
        }

        $this->data['log'][] = [
            'time' => $event->getDate()->format('U.u'),
            'level' => $event->getLevel(),
            'message' =>  $message,
        ];
    }

    /**
     *
     */
    public function onEnd()
    {
        App::response()->addHeader('X-Clockwork-Id', $this->id);
        App::response()->addHeader('X-Clockwork-Version', '2.0');

        $this->generateData();
        $this->storage->set($this->id, $this->data);
    }

    /**
     *
     */
    protected function generateData()
    {
        $this->data['id'] = $this->id;
        $start = App::request()->getServer('REQUEST_TIME_FLOAT');
        $end = microtime(true);

        $this->data['time'] = $start;
        $this->data['method'] = App::request()->getMethod();
        $this->data['uri'] = (string) App::request()->getUri()->get(false);
        $this->data['controller'] = App::router()->getCurrentController() . '@' .
            App::router()->getCurrentMethod();
        $this->data['memory'] = memory_get_peak_usage(true);

        // request data
        foreach (App::request()->getCookies() as $cookie) {
            $this->data['request']['cookies'][$cookie->getName()] = $cookie->getValue();
        }

        $this->data['request']['requestHeaders'] = App::request()->getHeaders();
        $this->data['request']['responseHeaders'] = App::response()->getHeaders();
        $this->data['request']['get'] = App::request()->getUri()->getQueries() ?? [];
        $this->data['request']['post'] = App::request()->getPosts() ?? [];
        $this->data['request']['files'] = App::request()->getUploadedFiles() ?? [];

        // session data
        $session =  self::session()->getData();

        foreach ($session as $key => $value) {
            if (is_object($value)) {
                $className = get_class($value);
                $session[$key] = $this->recursiveSerialize($value);
                $session[$key] = ['_className' => $className] + $session[$key];
            }
        }

        $this->data['request']['session'] = $session;

        unset($this->data['request']['session']['clockwork']);

        foreach ($this->data['request'] as $type => $data) {
            if (!$data) {
                unset($this->data['request'][$type]);
            }
        }

        // duration
        $this->data['responseTime'] = $end ;
        $this->data['responseStatus'] = App::response()->getStatus();
        $this->data['responseDuration'] = ($end - $start) * 1000;

        $this->data['timelineData'] = $this->data['timelineData'] ?? [];

        $this->data['timelineData'] = array_merge(['Total execution time' => [[
            'start' => $start,
            'end' => $end,
            'duration' =>  ($end - $start) * 1000,
        ]]], $this->data['timelineData']);
    }
}
