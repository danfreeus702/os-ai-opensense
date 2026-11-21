<?php
namespace OPNsense\AIOpenSense;

class GuideController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        // Load guides list via API
        $guidesApi = new \OPNsense\AIOpenSense\Api\GuideApiController();
        $result = $guidesApi->listAction();
        $guides = $result['guides'] ?? [];

        $this->view->guides = $guides;
        $this->view->pick('OPNsense/AIOpenSense/guides');
    }

    public function viewAction()
    {
        $guideId = $this->request->getParam('id', 'trim');
        if (empty($guideId)) {
            $this->FlashMessage->addError(__('Guide ID required'));
            $this->redirect('/ui/aiopensense/guides/');
            return;
        }

        // Load the guide with AI suggestions
        $guidesApi = new \OPNsense\AIOpenSense\Api\GuideApiController();
        $result = $guidesApi->getAction();

        if ($result['status'] !== 'ok') {
            $this->FlashMessage->addError($result['message'] ?? __('Failed to load guide'));
            $this->redirect('/ui/aiopensense/guides/');
            return;
        }

        $this->view->guide = $result['guide'];
        $this->view->steps = $result['steps'];
        $this->view->pick('OPNsense/AIOpenSense/guide');
    }
}
