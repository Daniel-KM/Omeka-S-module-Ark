<?php declare(strict_types=1);

namespace Ark\Controller\Admin;

use Ark\Form\CreateArksForm;
use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ArkController extends AbstractActionController
{
    public function indexAction()
    {
        $form = $this->getForm(CreateArksForm::class);

        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost();
            $form->setData($data);
            if ($form->isValid()) {
                $job = $this->jobDispatcher()->dispatch(\Ark\Job\CreateArks::class);
                $urlPlugin = $this->url();
                $message = new PsrMessage(
                    'ARK creation started in job {link_job}#{job_id}{link_end} ({link_log}logs{link_end}).', // @translate
                    [
                        'link_job' => sprintf('<a href="%1$s">', $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()])),
                        'job_id' => $job->getId(),
                        'link_end' => '</a>',
                        'link_log' => sprintf('<a href="%1$s">', class_exists('Log\Module', false)
                            ? $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])
                            : $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
                    ]
                );
                $message->setEscapeHtml(false);
                $this->messenger()->addSuccess($message);

                return $this->redirect()->toRoute('admin/ark-admin', [], true);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
        ]);
    }
}
