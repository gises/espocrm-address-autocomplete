<?php

use Espo\Core\Container;

/**
 * Raeumt beim Deinstallieren den Scheduled Job weg - sonst bliebe ein
 * Job-Datensatz zurueck, dessen Klasse nicht mehr existiert, und der
 * Scheduler wuerde bei jedem Lauf einen Fehler loggen.
 *
 * Die photonAddress*-Config-Werte bleiben bewusst stehen, damit eine
 * Neuinstallation die Einstellungen wiederfindet.
 */
class AfterUninstall
{
    /**
     * @param array<string, mixed> $params
     */
    public function run(Container $container, array $params = []): void
    {
        /** @var \Espo\ORM\EntityManager $entityManager */
        $entityManager = $container->get('entityManager');

        $job = $entityManager
            ->getRDBRepository('ScheduledJob')
            ->where(['job' => 'PhotonAddressCacheCleanup'])
            ->findOne();

        if ($job) {
            $entityManager->removeEntity($job);
        }
    }
}
