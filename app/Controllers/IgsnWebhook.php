<?php

namespace App\Controllers;

use App\Libraries\IgsnWebhook as LibrariesIgsnWebhook;
use CodeIgniter\Controller;

/**
 * Entry point of the calls of the IGSN registry webhook.
 * Not declared in the rights: the registry is not logged in, each call is authenticated by its signature.
 * The registry calls once per sample: the route is excluded from the startcall and sessionHijacking filters,
 * to open neither a session nor the purges of the logs and temporary files at each call
 */
class IgsnWebhook extends Controller
{
    public function receive()
    {
        $lib = new LibrariesIgsnWebhook;
        $status = $lib->receive(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine("X-Webhook-Timestamp"),
            $this->request->getHeaderLine("X-Signature")
        );
        return $this->response->setStatusCode($status);
    }
}
