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

interface StorageInterface
{
    /**
     * @param string $id
     *
     * @return array
     */
    public function get(string $id) : array;

    /**
     * @param string $id
     * @param array $data
     */
    public function set(string $id, array $data);
}
