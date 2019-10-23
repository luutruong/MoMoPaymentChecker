<?php

namespace MoMoPaymentChecker;

use Symfony\Component\DomCrawler\Crawler;

class Payment
{
    const MOMO_EMAIL_SENDER = 'no-reply@momo.vn';

    const FILE_GROUP_DIR = 'momo_payment_checker';
    const CACHE_FILE_EXT = '.msg';

    /**
     * @var EmailReader
     */
    protected $reader;

    /**
     * @var string|null
     */
    protected $storageDir;

    public function __construct(EmailReader $reader)
    {
        $this->reader = $reader;
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

    public function getTransaction($phoneNumber, $content)
    {
        $data = $this->indexRecentMessages();

        foreach ($data as $item) {
            if (empty($item)) {
                continue;
            }

            if ($item['phone_number'] === $phoneNumber
                && $item['content'] === $content
            ) {
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
        $mailFromMoMo = false;
        /** @var \Google_Service_Gmail_MessagePartHeader $header */
        foreach ($message->getPayload()->getHeaders() as $header) {
            if ($header->getName() === 'Sender'
                && strtolower($header->getValue()) === self::MOMO_EMAIL_SENDER
            ) {
                $mailFromMoMo = true;

                break;
            }
        }

        if (!$mailFromMoMo) {
            return null;
        }

        $parts = $message->getPayload()->getParts();

        $body = $parts[0]['body'];
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

            if (preg_match('/^lời chúc$/i', $line)
                && count($lineMessages) > 0
            ) {
                $transaction['content'] = array_shift($lineMessages);
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
     * Index recent 100 mail messages
     * @return array
     */
    protected function indexRecentMessages()
    {
        if ($this->storageDir === null) {
            throw new \InvalidArgumentException('Storage directory must be set!');
        }

        $messages = $this->reader->listMessages([
            'maxResults' => 100
        ]);

        $data = [];

        /** @var \Google_Service_Gmail_Message $message */
        foreach ($messages as $message) {
            $data[$message->getId()] = $this->indexMessage($message->getId());
        }

        $files = glob($this->storageDir . '/' . self::FILE_GROUP_DIR . '/*/*/*' . self::CACHE_FILE_EXT);
        foreach ($files as $path) {
            $messageId = pathinfo($path, PATHINFO_FILENAME);
            if (array_key_exists($messageId, $data)) {
                continue;
            }

            $contents = file_get_contents($path);
            list(, $encoded) = explode(',', $contents, 2);
            $data[$messageId] = json_decode($encoded, true);
        }

        return $data;
    }

    /**
     * @param string $messageId
     * @return array|null
     */
    protected function indexMessage($messageId)
    {
        $path = $this->getMessageFilePath($messageId);

        if (file_exists($path)) {
            $contents = file_get_contents($path);
            list(, $encoded) = explode(',', $contents, 2);

            return json_decode($encoded, true);
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $message = $this->reader->getMessage($messageId);
        $transaction = $this->parseMessage($message);

        $contents = time() . ',' . strval(json_encode($transaction));
        file_put_contents($path, $contents);

        return $transaction;
    }

    /**
     * @param string $messageId
     * @return string
     */
    protected function getMessageFilePath($messageId)
    {
        $hashed = md5($messageId);
        $index = 0;
        $path = rtrim($this->storageDir, '/') . '/' . self::FILE_GROUP_DIR;

        while ($index < 2) {
            $path .= '/' . substr($hashed, $index * 2, 2);
            $index++;
        }

        return $path . '/' . $messageId . self::CACHE_FILE_EXT;
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
