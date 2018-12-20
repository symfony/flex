<?php

namespace Harmony\Flex\Platform\Handler;

use Composer\Json\JsonFile;
use Harmony\Flex\Config\JsonConfigSource;
use Harmony\Flex\Repository\HarmonyRepository;
use Harmony\Flex\Util\Harmony;
use Harmony\Sdk;

/**
 * Class Authentication
 *
 * @package Harmony\Flex\Platform\Handler
 */
class Authentication extends AbstractHandler
{

    /**
     * Ask and store HarmonyCMS API Access Token.
     *
     * @return string
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    public function authenticate(): string
    {
        $harmonyUtil = new Harmony($this->io, $this->composer->getConfig());

        // load global auth file
        $tokenFile = new JsonFile($this->composer->getConfig()->get('home') . '/auth.json');
        if (true === $tokenFile->exists()) {
            $jsonConfigSource = new JsonConfigSource($tokenFile, true);
            $this->composer->getConfig()->setAuthConfigSource($jsonConfigSource);

            $token = $jsonConfigSource->getConfigSetting('harmony-oauth.' . HarmonyRepository::REPOSITORY_NAME);
            if (null !== $token) {
                /** @var Sdk\Receiver\Events $events */
                $events      = $this->client->getReceiver(Sdk\Client::RECEIVER_EVENTS);
                $tokenStatus = $events->tokenStatus($token);
                if (isset($tokenStatus['status']) && 'authenticated' === $tokenStatus['status']) {
                    $this->client->setBearerToken($token);

                    /** @var Sdk\Receiver\Users $users */
                    $users = $this->client->getReceiver(Sdk\Client::RECEIVER_USERS);
                    $this->io->success('Welcome back "' . $users->getUser()['username'] . '"!');

                    return $token;
                }
            }
        }

        return $harmonyUtil->askOAuthInteractively($this->client);
    }
}