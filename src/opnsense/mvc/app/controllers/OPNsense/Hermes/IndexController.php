<?php
namespace OPNsense\Hermes;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/Hermes/index');
    }
}
