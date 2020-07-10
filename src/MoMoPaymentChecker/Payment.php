<?php

namespace MoMoPaymentChecker;

class Payment
{
    const MOMO_EMAIL_SENDER = 'no-reply@momo.vn';

    /**
     * @var callable|null
     */
    public static $messageParser;
    /**
     * @var callable|null
     */
    public static $indexer;

    /**
     * @var EmailReader
     */
    protected $reader;

    /**
     * @var CacheInterface
     */
    protected $cache;

    public function __construct(EmailReader $reader, CacheInterface $cache)
    {
        $this->reader = $reader;
        $this->cache = $cache;
    }

    /**
     * @return CacheInterface
     */
    public function getCache()
    {
        return $this->cache;
    }

    /**
     * @return EmailReader
     */
    public function getReader()
    {
        return $this->reader;
    }

    /**
     * @param array $params
     * @param \Closure $filter
     * @return array|null
     */
    public function getTransaction(array $params, \Closure $filter)
    {
        if (static::$indexer !== null) {
            $data = call_user_func(static::$indexer, $this, $params);
        } else {
            $data = $this->defaultIndexer($params);
        }

        foreach ($data as $item) {
            if (empty($item)) {
                continue;
            }

            $returned = \call_user_func($filter, $item);
            if ($returned === true) {
                return $item;
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
        $isReportEmail = false;
        /** @var \Google_Service_Gmail_MessagePartHeader $header */
        foreach ($message->getPayload()->getHeaders() as $header) {
            if ($header->getName() === 'Sender'
                && strtolower($header->getValue()) === self::MOMO_EMAIL_SENDER
            ) {
                $isReportEmail = true;

                break;
            }
        }

        if (!$isReportEmail) {
            return null;
        }

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
     * @param array $params
     * @return array
     */
    protected function defaultIndexer(array $params)
    {
        if (isset($params['maxPages'])) {
            $limitPages = $params['maxPages'];
            unset($params['maxPages']);
        } else {
            $limitPages = 2;
        }

        $data = [];
        $pageToken = null;

        do {
            $limitPages--;
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $response = $this->reader->listMessages($params);

            if ($response->getMessages()) {
                $messageIds = [];
                /** @var \Google_Service_Gmail_Message $message */
                foreach ($response->getMessages() as $message) {
                    $messageIds[] = $message->getId();
                }
                $this->cache->preload($messageIds);

                /** @var \Google_Service_Gmail_Message $message */
                foreach ($response->getMessages() as $message) {
                    $data[$message->getId()] = $this->fetchMessage($message->getId());
                }

                $pageToken = $response->getNextPageToken();
            }
        } while ($pageToken && $limitPages > 0);

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
