<?php
namespace OPNsense\Hermes;

class GuideController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        // Load guides list via API
        $guidesApi = new \OPNsense\Hermes\Api\GuideApiController();
        $result = $guidesApi->listAction();
        $guides = $result['guides'] ?? [];

        $this->view->guides = $guides;
        $this->view->pick('OPNsense/Hermes/guides');
    }

    public function viewAction()
    {
        $guideId = $this->request->getParam('id', 'trim');
        if (empty($guideId)) {
            $this->FlashMessage->addError(__('Guide ID required'));
            $this->redirect('/ui/hermes/guides/');
            return;
        }

        // Load the guide with AI suggestions
        $guidesApi = new \OPNsense\Hermes\Api\GuideApiController();
        $result = $guidesApi->getAction();

        if ($result['status'] !== 'ok') {
            $this->FlashMessage->addError($result['message'] ?? __('Failed to load guide'));
            $this->redirect('/ui/hermes/guides/');
            return;
        }

        $this->view->guide = $result['guide'];
        $this->view->steps = $result['steps'];
        $this->view->pick('OPNsense/Hermes/guide');
    }
}
