<?php

declare(strict_types=1);

namespace Ragbridge\Symfony\Sync;

use Doctrine\ORM\EntityManagerInterface;
use Ragbridge\Sync\Syncable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a sync message for every record of a Doctrine entity.
 *
 * For an application adopting the sync with existing records, and for records changed by
 * something other than the entity manager's unit of work, which {@see EntityChangeListener}
 * does not see.
 */
final class SyncCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EntityExternalId $externalId,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('ragbridge:sync')
            ->setDescription('Queue a ragbridge sync message for every record of a Doctrine entity')
            ->addArgument('entity', InputArgument::REQUIRED, 'Fully qualified class name of the Doctrine entity')
            ->addOption(
                'chunk',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of records loaded, and the entity manager cleared, at a time',
                '200',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $class = $input->getArgument('entity');

        if (! is_string($class) || ! class_exists($class) || ! is_a($class, Syncable::class, true)) {
            $io->error(sprintf(
                '%s must be the class name of a Doctrine entity implementing %s.',
                is_string($class) ? $class : 'The entity',
                Syncable::class,
            ));

            return self::FAILURE;
        }

        $chunkOption = $input->getOption('chunk');
        $chunk = max(1, is_numeric($chunkOption) ? (int) $chunkOption : 200);
        $queued = 0;

        $query = $this->entityManager->createQuery(sprintf('SELECT e FROM %s e', $class));

        foreach ($query->toIterable() as $entity) {
            if (! $entity instanceof Syncable) {
                continue;
            }

            $this->bus->dispatch(new SyncMessage($entity::class, $this->externalId->key($entity), $this->externalId->of($entity)));
            $queued++;

            if ($queued % $chunk === 0) {
                $this->entityManager->clear();
            }
        }

        $io->success(sprintf('Queued %d record(s) of %s.', $queued, $class));

        return self::SUCCESS;
    }
}
