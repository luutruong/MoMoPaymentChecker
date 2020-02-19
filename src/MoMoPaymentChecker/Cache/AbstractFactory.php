<?php

namespace MoMoPaymentChecker\Cache;

abstract class AbstractFactory
{
    /**
     * @param string $messageId
     * @return boolean
     */
    abstract public function has($messageId);

    /**
     * @param string $messageId
     * @return mixed
     */
    abstract public function get($messageId);

    /**
     * @param string $messageId
     * @param mixed $data
     * @return boolean
     */
    abstract public function save($messageId, $data);

    /**
     * @param string $messageId
     * @return void
     */
    abstract public function delete($messageId);

    /**
     * @param array $messageIds
     * @return void
     */
    public function preload(array $messageIds)
    {
    }
}
