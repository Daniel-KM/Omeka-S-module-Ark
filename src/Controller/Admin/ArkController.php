<?php

namespace Ark\Controller\Admin;

use Ark\Form\CreateArksForm;
use Omeka\Stdlib\Message;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class ArkController extends AbstractActionController
{
    public function indexAction()
    {
        $form = $this->getForm(CreateArksForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost();
            $form->setData($data);
            if ($form->isValid()) {
                $job = $this->jobDispatcher()->dispatch('Ark\Job\CreateArks');

                $jobUrl = $this->url()->fromRoute('admin/id', [
                    'controller' => 'job',
                    'action' => 'show',
                    'id' => $job->getId(),
                ]);

                $message = new Message(
                    'ARK creation started in %sjob %s%s', // @translate
                    sprintf('<a href="%s">', htmlspecialchars($jobUrl)),
                    $job->getId(),
                    '</a>'
                );

                $message->setEscapeHtml(false);
                $this->messenger()->addSuccess($message);

                return $this->redirect()->toRoute('admin/ark-admin', [], true);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        return $view;
    }
}
