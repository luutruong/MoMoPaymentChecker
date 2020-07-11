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
        $start = microtime(true);

        $tableRows = $this->crawler->filter('tr');
        $total = $tableRows->count();

        $data = [];
        for ($index = ($total - 13); $index >= 0; $index--) {
            $node = $tableRows->eq($index);
            $table = $node->filter('table');
            if (!$table->count()) {
                continue;
            }

            $subRows = $table->filter('tr');
            for ($j = 0; $j < $subRows->count(); $j++) {
                $value = $this->matching($subRows->eq($j));
                if ($value !== null) {
                    $data = \array_merge($data, $value);
                }
            }

            if ($this->isValid($data)) {
                break;
            }
        }

        $data['_timing'] = number_format(microtime(true) - $start, 4);

        return $data;
    }

    protected function isValid(array $data)
    {
        $required = [
            'sender',
            'phoneNumber',
            'transactionDate',
            'note',
            'transactionId',
            'amount'
        ];

        foreach ($required as $key) {
            if (empty($data[$key])) {
                return false;
            }
        }

        return true;
    }

    protected function matching(Crawler $node)
    {
        $text = \trim($node->text());
        $text = \str_replace("\n", '', $text);
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
        if (\preg_match('/^lời chúc(.*)$/ui', $message, $matches) === 1) {
            return \trim($matches[1]);
        }

        return null;
    }

    protected function getSenderPhoneNumber($message)
    {
        if (\preg_match('/^số điện thoại người gửi\s*([0-9]{10,13})$/ui', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function getSender($message)
    {
        if (\preg_match('/^người gửi(.*)/ui', $message, $matches) === 1) {
            return \trim($matches[1]);
        }

        return null;
    }

    protected function getTransactionDate($message)
    {
        if (\preg_match(
            '/^thời gian\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}\s*\-\s*[0-9]{1,2}:[0-9]{1,2})$/ui',
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
        if (\preg_match('/^mã giao dịch\s*([0-9]+)$/ui', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function getReceivedAmount($message)
    {
        if (\preg_match('/^(số tiền nhận được|số tiền)\s*([0-9\.\,]+)/ui', $message, $matches)) {
            $amount = $matches[2];
            $amount = \str_replace('.', '', $amount);

            return \intval($amount);
        }

        return null;
    }
}
