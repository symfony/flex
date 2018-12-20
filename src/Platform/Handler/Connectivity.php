<?php

namespace Harmony\Flex\Platform\Handler;

use Harmony\Sdk;

/**
 * Class Connectivity
 *
 * @package Harmony\Flex\Platform\Handler
 */
class Connectivity extends AbstractHandler
{

    /**
     * @return bool
     * @throws \Exception
     * @throws \Http\Client\Exception
     */
    public function check(): bool
    {
        /** @var Sdk\Receiver\Events $events */
        $events = $this->client->getReceiver(Sdk\Client::RECEIVER_EVENTS);
        $ping   = $events->ping();
        // 1. Check HarmonyCMS API connectivity
        if (true === isset($ping['ping']) && 'pong' === $ping['ping']) {
            if ($this->io->isDebug()) {
                $this->io->success('Connectivity to ' . Sdk\Client::API_URL . ' successful!');
            }

            return true;
        }
        $this->io->error('Error connecting to HarmonyCMS API, unreachable host: ' . Sdk\Client::API_URL . '!');

        return false;
    }
}