<?php

namespace App\Libraries;

use Ppci\Libraries\PpciException;

/**
 * Refusal of a call by the IGSN registry, with its HTTP status and its decoded body
 */
class IgsnApiException extends PpciException
{
    public int $status;
    public array $body;

    /**
     * @param integer $status: HTTP status of the answer
     * @param array $body: decoded JSON body, empty if the answer is not JSON
     */
    function __construct(int $status, array $body = [])
    {
        $this->status = $status;
        $this->body = $body;
        parent::__construct(sprintf(_("Le registre IGSN a refusé la requête (statut %1s) : %2s"), $status, $body["error"] ?? ""));
    }
}
