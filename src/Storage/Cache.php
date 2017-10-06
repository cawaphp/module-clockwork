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

use Cawa\Cache\CacheFactory;

class Cache implements StorageInterface
{
    use CacheFactory;

    /**
     * @var string
     */
    private $name;

    /**
     * @param string $name
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id) : array
    {
        $data = self::cache($this->name)->get($id);

        if ($data) {
            self::cache($this->name)->delete($id);
        }

        return $data ? $data : [];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $id, array $data)
    {
        self::cache($this->name)->set($id, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $id) : bool
    {
        return self::cache($this->name)->delete($id);
    }
}
