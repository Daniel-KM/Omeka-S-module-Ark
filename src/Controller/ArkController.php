<?php

namespace Ark\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Omeka\Mvc\Exception\NotFoundException;

class ArkController extends AbstractActionController
{
    /**
     * Route the url to the original record.
     */
    public function indexAction()
    {
        $naan = $this->params('naan');
        $name = $this->params('name');
        $naanArk = $this->settings()->get('ark_naan');

        // Check are kept, because the file "routes.ini" may be used.
        if ($naan !== $naanArk) {
            throw new NotFoundException;
        }

        $qualifier = $this->params('qualifier');

        $resource = $this->ark()->find([
            'naan' => $naan,
            'name' => $name,
            'qualifier' => $qualifier,
        ]);
        if (empty($resource)) {
            throw new NotFoundException;
        }

        // Manage special uris.
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        if (isset($uri) && 0 == substr_compare($uri, '?', -1)) {
            $this->setPlainTextContentType();
            $view = new ViewModel(['resource' => $resource]);
            $view->setTemplate('ark/index/metadata');
            $view->setTerminal(true);

            if (0 == substr_compare($uri, '??', -2)) {
                $view->setVariable('policy', true);
            }

            return $view;
        }
    }

    /**
     * Returns the main policy for the institution.
     */
    public function policyAction()
    {
        $naan = $this->params('naan');
        $naanArk = $this->settings()->get('ark_naan');

        // Check are kept, because the file "routes.ini" may be used.
        if ($naan !== $naanArk) {
            throw new NotFoundException;
        }

        $view = new ViewModel;
        $view->setVariable('policy', $this->settings()->get('ark_policy_main'));

        $uri = $_SERVER['REQUEST_URI'];
        if (0 == substr_compare($uri, '?', -1)) {
            $this->setPlainTextContentType();
            $view->setTemplate('ark/index/policy-txt');
            $view->setTerminal(true);
        } else {
            $view->setTemplate('ark/index/policy-html');
        }

        return $view;
    }

    protected function setPlainTextContentType()
    {
        $this->getResponse()
            ->getHeaders()
            ->addHeaderLine('Content-Type: text/plain; charset=UTF-8');
    }
}
