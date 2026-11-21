<?php
namespace OPNsense\AIOpenSense;

class GuideController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->guides = \OPNsense\AIOpenSense\Guide::getGuides();
        $this->view->pick('OPNsense/AIOpenSense/guides');
    }

    public function viewAction()
    {
        $guideId = trim((string)$this->request->getQuery('id'));
        $guides = \OPNsense\AIOpenSense\Guide::getGuides();

        $guide = null;
        foreach ($guides as $candidate) {
            if ($candidate['id'] === $guideId) {
                $guide = $candidate;
                break;
            }
        }
        if ($guide === null) {
            $this->response->redirect('/ui/aiopensense/guides/', true);
            return;
        }

        $steps = $guide['steps'] ?? [];
        $stepId = trim((string)$this->request->getQuery('step'));
        $stepIndex = 0;
        foreach ($steps as $index => $step) {
            if ($step['id'] === $stepId) {
                $stepIndex = $index;
                break;
            }
        }

        $this->view->guide = $guide;
        $this->view->steps = $steps;
        $this->view->stepIndex = $stepIndex;
        $this->view->currentStep = $steps[$stepIndex];
        $this->view->pick('OPNsense/AIOpenSense/guide');
    }
}
