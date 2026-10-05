<?php

namespace App\Libraries;

use Ppci\Libraries\PpciLibrary;
use Ppci\Libraries\Views\SmartyPpci;

class BeforeDisplay extends PpciLibrary
{
    static function index() {}
    static function setGeneric(SmartyPpci $vue) {
        if (!empty($_SESSION["moduleListe"])){
            $vue->set($_SESSION["moduleListe"], "moduleListe");
        }
        if (isset($_REQUEST["activeTab"])) {
            $vue->set($_REQUEST["activeTab"], "activeTab");
        }
        /**
         * Publication into the IGSN registry: the IGSN blocks of the templates are displayed only if enabled
         */
        if ($vue->app->IGSN_enabled) {
            $vue->set(1, "IGSN_enabled");
            $vue->set($vue->app->IGSN_url, "IGSN_url");
        }
    }
}
