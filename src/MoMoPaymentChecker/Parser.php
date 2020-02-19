<?php

namespace MoMoPaymentChecker;

use Symfony\Component\DomCrawler\Crawler;

class Parser
{
    /**
     * @var string
     */
    protected $content;

    /**
     * @var Crawler
     */
    protected $crawler;

    public function __construct($content)
    {
        $this->content = $content;

        $crawler = new Crawler();
        $crawler->addHtmlContent($content);

        $this->crawler = $crawler;
    }

    public function parse()
    {
        $tableRows = $this->crawler->filter('tr');

        $data = [];
        for ($index = 0; $index < $tableRows->count(); $index++) {
            $value = $this->matching($tableRows->eq($index));
            if ($value !== null) {
                $data = \array_merge($data, $value);
            }
        }

        return $data;
    }

    protected function matching(Crawler $node)
    {
        $text = \trim($node->text());
        if (\strlen($text) === 0) {
            return null;
        }

        if ($value = $this->getReceivedAmount($text)) {
            return ['amount' => $value];
        } elseif ($value = $this->getTransactionID($text)) {
            return ['transactionId' => $value];
        } elseif ($value = $this->getTransactionDate($text)) {
            return ['transactionDate' => $value];
        } elseif ($value = $this->getSender($text)) {
            return ['sender' => $value];
        } elseif ($value = $this->getSenderPhoneNumber($text)) {
            return ['phoneNumber' => $value];
        } elseif ($value = $this->getNote($text)) {
            return ['note' => $value];
        }

        return null;
    }

    protected function getNote($message)
    {
        if (\preg_match('/^lời chúc (.*)$/ui', $message, $matches) === 1) {
            return \trim($matches[1]);
        }

        return null;
    }

    protected function getSenderPhoneNumber($message)
    {
        if (\preg_match('/^số điện thoại người gửi ([0-9]{10,13})$/ui', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function getSender($message)
    {
        if (\preg_match('/^người gửi (.*)/ui', $message, $matches) === 1) {
            return \trim($matches[1]);
        }

        return null;
    }

    protected function getTransactionDate($message)
    {
        if (\preg_match(
            '/^thời gian ([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}\s*\-\s*[0-9]{1,2}:[0-9]{1,2})$/ui',
            $message
            , $matches) === 1
        ) {
            $time = $matches[1];
            $time = \str_replace('  ', ' ', $time);

            return \DateTime::createFromFormat(
                'd/m/Y - H:i',
                $time,
                new \DateTimeZone('Asia/Ho_Chi_Minh')
            );
        }

        return null;
    }

    protected function getTransactionID($message)
    {
        if (\preg_match('/^mã giao dịch ([0-9]+)$/ui', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function getReceivedAmount($message)
    {
        if (\preg_match('/^số tiền nhận được ([0-9\.\,]+)/ui', $message, $matches) === 1) {
            $amount = $matches[1];
            $amount = \str_replace('.', '', $amount);

            return \intval($amount);
        }

        return null;
    }
}
