<?php
namespace OPNsense\AIOpenSense;

/**
 * Security Wizard page controller.
 */
class SecurityController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/AIOpenSense/security');
    }
}
