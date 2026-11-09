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
            $result = $noidMaintenance->rebuild(fn () => $this->shouldStop());
        } catch (\RuntimeException $e) {
            $logger->err(
                'The noid database cannot be rebuilt: {message}', // @translate
                ['message' => $e->getMessage()]
            );
            $this->job->setStatus($this->shouldStop() ? Job::STATUS_STOPPED : Job::STATUS_ERROR);
            return;
        }

        $logger->notice(
            'The noid database was rebuilt with the backend {store}: {replayed} arks were issued again in the same order, {held} arks that the minter cannot issue were reserved, and {minted} identifiers were issued in all.', // @translate
            $result
        );
        if ($result['backups']) {
            $logger->notice(
                'The previous database is kept as {backups}.', // @translate
                ['backups' => implode(', ', $result['backups'])]
            );
        }
        // The removed resources that had the last arks cannot be known.
        $logger->warn(
            'The identifiers issued after the last ark that is still stored in Omeka cannot be known, so they may be issued again: check it if the last resources with an ark were deleted.' // @translate
        );
    }
}
