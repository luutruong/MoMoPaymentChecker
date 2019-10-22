<?php

namespace MoMoPaymentChecker;

class OAuth
{
    /**
     * @var string
     */
    protected $clientId;
    /**
     * @var string
     */
    protected $clientSecret;

    /**
     * @var string
     */
    protected $redirectUri;

    /**
     * @var \Google_Client
     */
    protected $client;

    public function __construct($clientId, $clientSecret, $redirectUri)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;

        $this->setupClient();
    }

    /**
     * @return string
     */
    public function getAuthUrl()
    {
        return $this->client->createAuthUrl();
    }

    public function getToken($code)
    {
        return $this->client->fetchAccessTokenWithAuthCode($code);
    }

    /**
     * @return \Google_Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return void
     */
    protected function setupClient()
    {
        $client = new \Google_Client();

        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);

        $client->addScope(\Google_Service_Gmail::GMAIL_READONLY);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');

        $this->client = $client;
    }
}
