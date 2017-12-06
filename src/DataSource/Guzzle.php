<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Cawa\Clockwork\DataSource;

use Cawa\Events\DispatcherFactory;
use Cawa\Events\TimerEvent;
use Cawa\Log\LoggerFactory;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class Guzzle
{
    use DispatcherFactory;
    use LoggerFactory;


    /**
     * Called when the middleware is handled by the client.
     *
     * @param callable $handler
     *
     * @return callable
     */
    public function __invoke(callable $handler)
    {
        return function (RequestInterface $request, array $options) use ($handler) {

            $event = new TimerEvent('guzzle.request');

            $event->addData([
                'method' => $request->getMethod(),
                'url' => (string) $request->getUri(),
                'status' => 0,
            ]);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $event) {
                    $event->addData([
                        'status' => $response->getStatusCode(),
                    ]);

                    self::logger()->debug((string) (new MessageFormatter(MessageFormatter::DEBUG))->format($request, $response, null));

                    self::emit($event);

                    return $response;
                },
                function (GuzzleException $exception) use ($request, $event) {
                    if ($exception instanceof RequestException) {
                        if ($exception->getResponse()) {
                            $event->addData([
                                'status' => $exception->getResponse()->getStatusCode(),
                            ]);
                        }

                        self::logger()->debug((string) (new MessageFormatter(MessageFormatter::DEBUG))->format($request, $exception->getResponse(), null));
                    } else {
                        self::logger()->debug((string) (new MessageFormatter(MessageFormatter::DEBUG))->format($request, null, null));
                    }

                    self::emit($event);

                    return Promise\rejection_for($exception);
                }
            );
        };
    }
}
