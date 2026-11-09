<?php declare(strict_types=1);

namespace Ark\Controller\Admin;

use Ark\Form\CreateArksForm;
use Ark\Stdlib\NoidMaintenance;
use Common\Stdlib\PsrMessage;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ArkController extends AbstractActionController
{
    /**
     * @var \Ark\Stdlib\NoidMaintenance
     */
    protected $noidMaintenance;

    public function __construct(NoidMaintenance $noidMaintenance)
    {
        $this->noidMaintenance = $noidMaintenance;
    }
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
                        'link_job' => sprintf('<a href="%1$s">', htmlspecialchars($urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                        'job_id' => $job->getId(),
                        'link_end' => '</a>',
                        'link_log' => sprintf('<a href="%1$s">', htmlspecialchars(class_exists('Log\Module', false)
                            ? $urlPlugin->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]])
                            : $urlPlugin->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))),
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

    /**
     * Download the identifiers issued without resource: removed resources and
     * margin of a rebuild.
     */
    public function unboundAction()
    {
        if (!$this->userIsAllowed(\Omeka\Module\Manager::class, 'configure')) {
            throw new \Omeka\Mvc\Exception\PermissionDeniedException(
                $this->translate('Only the administrators can download the identifiers issued without resource.') // @translate
            );
        }

        // The date is the one of the database: after a rebuild, it is the date
        // of the rebuild, not the original one.
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, ['ark', 'rank', 'issued_in_database', 'former_resources', 'type'], ',', '"', '\\');
        foreach ($this->noidMaintenance->identifiersWithoutResource() as $row) {
            fputcsv($stream, ['ark:/' . $row['ark'], $row['position'], $row['date'], $row['resources'], $row['type']], ',', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'text/csv; charset=utf-8')
            ->addHeaderLine('Content-Disposition', 'attachment; filename="ark-unbound-' . date('Ymd-His') . '.csv"');
        $response->setContent($csv);
        return $response;
    }
}
