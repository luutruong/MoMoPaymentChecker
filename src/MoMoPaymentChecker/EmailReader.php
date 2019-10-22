<?php

namespace MoMoPaymentChecker;

class EmailReader
{
    /**
     * @var \Google_Client
     */
    protected $client;

    /**
     * @var \Google_Service_Gmail
     */
    protected $gmail;

    public function __construct(\Google_Client $client)
    {
        $this->client = $client;

        $this->gmail = new \Google_Service_Gmail($client);
    }

    /**
     * @param string $messageId
     * @return \Google_Service_Gmail_Message
     */
    public function getMessage($messageId)
    {
        return $this->gmail->users_messages->get('me', $messageId, [
            'format' => 'full'
        ]);
    }

    /**
     * @param array $params
     * @return \Google_Service_Gmail_ListMessagesResponse
     */
    public function listMessages(array $params = [])
    {
        return $this->gmail->users_messages->listUsersMessages('me', $params);
    }
}
