<?php

namespace MoMoPaymentChecker;

use MoMoPaymentChecker\Cache\AbstractFactory;
use MoMoPaymentChecker\Cache\File;
use Symfony\Component\DomCrawler\Crawler;

class Payment
{
    const MOMO_EMAIL_SENDER = 'no-reply@momo.vn';

    /**
     * @var callable|null
     */
    public static $messageParser;

    /**
     * @var EmailReader
     */
    protected $reader;

    /**
     * @var AbstractFactory|null
     */
    protected $cache;

    public function __construct(EmailReader $reader, AbstractFactory $cache = null)
    {
        $this->reader = $reader;
        $this->cache = $cache ?: new File();
    }

    /**
     * @param string $storageDir
     * @return $this
     */
    public function setStorageDir($storageDir)
    {
        if ($this->cache instanceof File) {
            $this->cache->setStorageDir($storageDir);
        } else {
            throw new \LogicException('Set storage directory does not supported!');
        }

        return $this;
    }

    /**
     * @param \Closure $filter
     * @return array|null
     */
    public function getTransaction(\Closure $filter)
    {
        $data = $this->indexRecentMessages();

        foreach ($data as $item) {
            if (empty($item) || empty($item['data'])) {
                continue;
            }

            $returned = \call_user_func($filter, $item['data']);
            if ($returned === true) {
                return $item['data'];
            }
        }

        return null;
    }

    /**
     * @param \Google_Service_Gmail_Message $message
     * @return array|null
     */
    public function parseMessage(\Google_Service_Gmail_Message $message)
    {
        if (static::$messageParser !== null
            && \is_callable(static::$messageParser)
        ) {
            $return = \call_user_func(static::$messageParser, $message);
            if ($return === null || \is_array($return)) {
                return $return;
            }

            throw new \LogicException('Method must be return a Array or NULL!');
        }

        $parts = $message->getPayload()->getParts();

        $body = $parts[0]['body'];
        $rawData = $body->data;
        $sanitizedData = strtr($rawData, '-_', '+/');

        $decoded = \trim(\base64_decode($sanitizedData));
        if (\stripos($decoded, '<!DOCTYPE html>') !== 0) {
            return null;
        }

        $parser = new Parser($decoded);
        $transaction = $parser->parse();
        $transaction['_messageBody'] = $decoded;

        return $transaction;
    }

    /**
     * @return array
     */
    protected function indexRecentMessages()
    {
        $messages = $this->reader->listMessages([
            'maxResults' => 100,
            'q' => 'from:' . self::MOMO_EMAIL_SENDER
        ]);

        $messageIds = [];
        /** @var \Google_Service_Gmail_Message $message */
        foreach ($messages as $message) {
            $messageIds[] = $message->getId();
        }
        $this->cache->preload($messageIds);

        $data = [];
        /** @var \Google_Service_Gmail_Message $message */
        foreach ($messages as $message) {
            $data[$message->getId()] = $this->fetchMessage($message->getId());
        }

        return $data;
    }

    /**
     * @param string $messageId
     * @return array|null
     */
    protected function fetchMessage($messageId)
    {
        $cache = $this->cache;

        if ($cache->has($messageId)) {
            return $cache->get($messageId);
        }

        $message = $this->reader->getMessage($messageId);
        $transaction = $this->parseMessage($message);

        $cache->save($messageId, $transaction);

        return $transaction;
    }
}
