<?php
namespace OPNsense\AIOpenSense;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/AIOpenSense/index');
    }
}
