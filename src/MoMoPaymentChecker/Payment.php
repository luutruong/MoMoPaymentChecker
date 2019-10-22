<?php

namespace MoMoPaymentChecker;

use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Filesystem\Filesystem;

class Payment
{
    const MOMO_EMAIL_SENDER = 'no-reply@momo.vn';

    const CACHE_FILE_EXT = '.msg';

    /**
     * @var EmailReader
     */
    protected $reader;

    /**
     * @var string|null
     */
    protected $storageDir;

    /**
     * @var Filesystem
     */
    protected $fs;

    public function __construct(EmailReader $reader)
    {
        $this->reader = $reader;
        $this->fs = new Filesystem();
    }

    /**
     * @param string $storageDir
     * @return $this
     */
    public function setStorageDir($storageDir)
    {
        $this->storageDir = $storageDir;

        return $this;
    }

    public function getTransaction($transactionId, array $messageParams = [])
    {
        $messages = $this->reader->listMessages(array_replace([
            'maxResults' => 50
        ], $messageParams));

        /** @var \Google_Service_Gmail_Message $message */
        foreach ($messages as $message) {
            $transaction = $this->parseMessage($message);
            if ($transaction !== null
                && $transaction['id'] === $transactionId
            ) {
                return $transaction;
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
        $messageId = $message->getId();
        if ($this->storageDir !== null) {
            $hashed = substr(md5($messageId), 0, 8);
            $dir = rtrim($this->storageDir, '/');
            $index = 0;

            while ($index < 8) {
                $dir .= '/' . substr($hashed, $index, 2);
                $index += 2;
            }

            $path = $dir . '/' . $messageId . self::CACHE_FILE_EXT;
            $fs = $this->fs;
            if (!$fs->exists($path)) {
                if (!$fs->isDirectory($dir)) {
                    $fs->makeDirectory($dir, 0755, true);
                }

                $messageService = $this->reader->getMessage($messageId);
                $obj = $messageService->toSimpleObject();

                $fs->put($path, json_encode($obj));
            } else {
                $contents = $fs->get($path);
                $obj = json_decode($contents);
            }
        } else {
            $messageService = $this->reader->getMessage($messageId);
            $obj = $messageService->toSimpleObject();
        }

        $mailFromMoMo = false;
        /** @var \Google_Service_Gmail_MessagePartHeader $header */
        foreach ($obj->payload->headers as $header) {
            if ($header->name === 'Sender'
                && strtolower($header->value) === self::MOMO_EMAIL_SENDER
            ) {
                $mailFromMoMo = true;

                break;
            }
        }

        if (!$mailFromMoMo) {
            return null;
        }

        $parts = $obj->payload->parts;

        $body = $parts[0]->body;
        $rawData = $body->data;
        $sanitizedData = strtr($rawData, '-_', '+/');

        $decoded = trim(base64_decode($sanitizedData));
        if (strpos($decoded, '<!DOCTYPE html>') !== 0) {
            return null;
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($decoded);

        $text = trim($crawler->text());

        $lineMessages = explode("\r\n", $text);
        $lineMessages = array_map('trim', $lineMessages);
        $lineMessages = array_diff($lineMessages, ['"', '']);
        $lineMessages = array_values($lineMessages);

        $raw = implode("\r\n", $lineMessages);

        $transaction = [];

        while (true) {
            $line = array_shift($lineMessages);
            if (preg_match('/^số tiền nhận được$/i', $line)
                && count($lineMessages) > 0
            ) {
                $amount = array_shift($lineMessages);
                $amount = str_replace('.', '', $amount);

                $transaction['amount'] = $amount;
            }

            if (preg_match('/^mã giao dịch$/i', $line)
                && count($lineMessages) > 0
            ) {
                $transaction['id'] = array_shift($lineMessages);
            }

            if (preg_match('/^thời gian$/i', $line)
                && count($lineMessages) > 0
            ) {
                $date = array_shift($lineMessages);
                $date = str_replace('-', '', $date);
                $date = str_replace('  ', ' ', $date);

                $transaction['date'] = $date;
            }

            if (preg_match('/^người gửi$/i', $line)
                && count($lineMessages) > 0
            ) {
                $transaction['name'] = array_shift($lineMessages);
            }

            if (preg_match('/^số điện thoại người gửi$/i', $line)
                && count($lineMessages) > 0
            ) {
                $transaction['phone_number'] = array_shift($lineMessages);
            }

            if (count($lineMessages) <= 0) {
                break;
            }
        }

        if (count($transaction) === 0) {
            return null;
        }

        if (!$this->validateTransactionData($transaction)) {
            return null;
        }

        $transaction['_raw'] = $raw;

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

        foreach (array_keys($transaction) as $key) {
            if ($key === 'amount') {
                $transaction[$key] = intval($transaction[$key]);
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
