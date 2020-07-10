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
     * @param string $threadId
     * @return \Google_Service_Gmail_Thread
     */
    public function getThread($threadId)
    {
        return $this->gmail->users_threads->get('me', $threadId, [
            'format' => 'full'
        ]);
    }

    /**
     * @param array $params
     * @return \Google_Service_Gmail_ListThreadsResponse
     */
    public function listThreads(array $params = [])
    {
        return $this->gmail->users_threads->listUsersThreads('me', $params);
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
