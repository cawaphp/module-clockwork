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

namespace Cawa\Clockwork\Storage;

use Cawa\Session\SessionFactory;

class Session implements StorageInterface
{
    use SessionFactory;

    //region Constants

    /**
     * Session storage var.
     */
    const SESSION_VAR = 'CLOCKWORK';

    //endregion

    /**
     * {@inheritdoc}
     */
    public function get(string $id) : array
    {
        $sessionData = self::session()->get(self::SESSION_VAR);

        if (isset($sessionData[$id])) {
            $return = $sessionData[$id];

            unset($sessionData[$id]);
            if (sizeof($sessionData) === 0) {
                self::session()->set(self::SESSION_VAR, $sessionData);
            } else {
                self::session()->remove(self::SESSION_VAR);
            }

            return $return;
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $id, array $data)
    {
        $sessionData = self::session()->get(self::SESSION_VAR);
        if (!$sessionData) {
            $sessionData = [];
        }
        $sessionData[$id] = $data;
        self::session()->set(self::SESSION_VAR, $sessionData);
    }
}
