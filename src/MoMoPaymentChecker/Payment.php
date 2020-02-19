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

        $crawler = new Crawler();
        $crawler->addHtmlContent($decoded);

        $text = \trim($crawler->text());

        $lineMessages = \explode("\r\n", $text);
        $lineMessages = \array_map('trim', $lineMessages);
        $lineMessages = \array_diff($lineMessages, ['"', '']);
        $lineMessages = \array_values($lineMessages);

        $raw = \implode("\r\n", $lineMessages);

        $transaction = [];

        while (true) {
            $line = \array_shift($lineMessages);
            if (\preg_match('/^số tiền nhận được$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $amount = \array_shift($lineMessages);
                $amount = \str_replace('.', '', $amount);

                $transaction['amount'] = $amount;
            }

            if (\preg_match('/^mã giao dịch$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $transaction['id'] = \array_shift($lineMessages);
            }

            if (\preg_match('/^thời gian$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $date = \array_shift($lineMessages);
                $date = \str_replace('-', '', $date);
                $date = \str_replace('  ', ' ', $date);

                $transaction['date'] = $date;
            }

            if (\preg_match('/^người gửi$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $transaction['name'] = \array_shift($lineMessages);
            }

            if (\preg_match('/^số điện thoại người gửi$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $transaction['phone_number'] = \array_shift($lineMessages);
            }

            if (\preg_match('/^lời chúc$/i', $line)
                && \count($lineMessages) > 0
            ) {
                $transaction['content'] = \array_shift($lineMessages);
            }

            if (\count($lineMessages) <= 0) {
                break;
            }
        }

        if (\count($transaction) === 0) {
            return null;
        }

        if (!$this->validateTransactionData($transaction)) {
            return null;
        }

        $transaction['_raw'] = $raw;
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

        $cache->save($messageId, [
            'data' => $transaction,
            'time' => \time()
        ]);

        return $transaction;
    }

    /**
     * @param array $transaction
     * @return bool
     */
    protected function validateTransactionData(array &$transaction)
    {
        $requiredKeys = ['amount', 'id', 'date', 'phone_number'];
        foreach ($requiredKeys as $requiredKey) {
            if (!isset($transaction[$requiredKey])
                || $transaction[$requiredKey] === ''
            ) {
                return false;
            }
        }

        foreach (\array_keys($transaction) as $key) {
            if ($key === 'amount') {
                $transaction[$key] = \intval($transaction[$key]);
            } elseif ($key === 'date') {
                $transaction[$key] = \DateTime::createFromFormat(
                    'd/m/Y H:i',
                    $transaction[$key],
                    new \DateTimeZone('Asia/Ho_Chi_Minh')
                );
            }
        }

        return true;
    }
}
