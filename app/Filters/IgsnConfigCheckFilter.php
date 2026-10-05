<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Warn the administrators, once per session, when the publication into IGSN is enabled
 * without the secret of the webhook: the publications are then only followed by a manual refresh
 */
class IgsnConfigCheckFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        /**
         * The check is only marked as done once logged in, so that the requests before the login don't skip it
         */
        if (empty($_SESSION["igsnConfigCheck"]) && !empty($_SESSION["isLogged"])) {
            $_SESSION["igsnConfigCheck"] = 1;
            $app = config("App");
            if ($app->IGSN_enabled && empty($app->IGSN_webhookSecret) && ($_SESSION["userRights"]["admin"] ?? 0) == 1) {
                service("MessagePpci")->set(_("IGSN est activé mais app.IGSN_webhookSecret n'est pas défini : les publications ne seront suivies que par actualisation manuelle"), true);
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
