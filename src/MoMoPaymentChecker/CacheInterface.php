<?php

namespace MoMoPaymentChecker;

interface CacheInterface
{
    /**
     * @param string $messageId
     * @return boolean
     */
    public function has($messageId);

    /**
     * @param string $messageId
     * @return mixed
     */
    public function get($messageId);

    /**
     * @param string $messageId
     * @param mixed $data
     * @return boolean
     */
    public function save($messageId, $data);

    /**
     * @param string $messageId
     * @return void
     */
    public function delete($messageId);

    /**
     * @param array $messageIds
     * @return void
     */
    public function preload(array $messageIds);
}
