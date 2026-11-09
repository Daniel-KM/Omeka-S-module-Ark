<?php declare(strict_types=1);

namespace Ark\Job;

use Omeka\Entity\Job;
use Omeka\Job\AbstractJob;

/**
 * Rebuild the noid database from the arks stored in Omeka.
 *
 * @see \Ark\Stdlib\NoidMaintenance::rebuild()
 */
class RebuildNoidDatabase extends AbstractJob
{
    public function perform(): void
    {
        $services = $this->getServiceLocator();
        /** @var \Laminas\Log\Logger $logger */
        $logger = $services->get('Omeka\Logger');
        /** @var \Ark\Stdlib\NoidMaintenance $noidMaintenance */
        $noidMaintenance = $services->get('Ark\NoidMaintenance');

        $logger->notice(
            'Rebuilding the noid database from the {count} arks stored in Omeka.', // @translate
            ['count' => $noidMaintenance->countArks()]
        );

        try {
            $result = $noidMaintenance->rebuild(fn () => $this->shouldStop(), (int) $this->getArg('margin', 0));
        } catch (\RuntimeException $e) {
            $logger->err(
                'The noid database cannot be rebuilt: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            $this->job->setStatus($this->shouldStop() ? Job::STATUS_STOPPED : Job::STATUS_ERROR);
            return;
        }

        // Only the placeholders of each message are passed, else the other
        // values are appended to the message.
        $logger->notice(
            'The noid database was rebuilt with the backend {store} and the generator {generator}, from the {count} arks stored in Omeka.', // @translate
            ['store' => $result['store'], 'generator' => $result['generator'], 'count' => $result['arks']]
        );
        $logger->notice(
            'Arks stored in Omeka found by the minter in the original order: {count}.', // @translate
            ['count' => $result['replayed']]
        );
        $logger->notice(
            'Identifiers issued before the last ark, but whose resources were removed from Omeka: {count}. They remain issued, so they will never be issued again.', // @translate
            ['count' => $result['minted'] - $result['replayed']]
        );
        if ($result['held']) {
            $logger->warn(
                'Arks stored in Omeka that the minter cannot issue (another template or prefix): {count}. They are reserved.', // @translate
                ['count' => $result['held']]
            );
        }
        $logger->notice(
            'Margin of identifiers reserved after the last ark, for the arks of the last removed resources, that cannot be known: {count}.', // @translate
            ['count' => $result['reserved']]
        );
        $logger->notice(
            'Identifiers known by the noid database, that will never be issued again: {count}. The next ark will be issued after them.', // @translate
            ['count' => $result['minted'] + $result['reserved'] + $result['held']]
        );
        if ($result['backups']) {
            $logger->notice(
                'The previous database is kept as {backups}.', // @translate
                ['backups' => implode(', ', $result['backups'])]
            );
        }
    }
}
