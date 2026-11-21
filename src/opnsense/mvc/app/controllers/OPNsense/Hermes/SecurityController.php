<?php
namespace OPNsense\Hermes;

/**
 * Security Wizard page controller.
 */
class SecurityController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/Hermes/security');
    }
}
