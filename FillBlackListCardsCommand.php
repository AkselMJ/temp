<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FillBlackListCardsCommand extends Command
{
    protected static $defaultName = 'app:fill-black-list-cards';

    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct();

        $this->em = $em;
    }

    protected function configure()
    {
        $this->setDescription('Fill BLC from csv')
            ->addArgument('input', InputArgument::REQUIRED, 'File with BLC');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputFilePath = $input->getArgument('input');

        if (!\file_exists($inputFilePath) || !\is_readable($inputFilePath)) {
            $output->writeln("File with name: {$inputFilePath} cannot be read");
            return 404;
        }

        $this->em->beginTransaction();
        $this->em->createQuery('DELETE FROM App:BlackListCards')->execute();
        $data = preg_split("/\r\n|\n|\r/", file_get_contents($inputFilePath));
        $chunks = array_chunk($data, 10000);
        foreach ($chunks as $chunk) {
            $rows = [];
            while ($chunk) {
                $cardNumber = str_replace(" ", "", array_shift($chunk));
                if (!is_numeric($cardNumber)) {
                    $output->writeln("$cardNumber is not valid");
                    continue;
                }
                $hash = hash("sha256", $cardNumber);
                $mask = $this->maskCardNumber($cardNumber);
                $rows[] = "('{$hash}','{$mask}')"; //don't do like this, there is potential sql-injection, just for PoC
            }
            if (empty($rows)) {
                $output->writeln("no card to fill");
                return 404;
            }

            $sql = 'INSERT INTO black_list_cards VALUES ' . implode(",", $rows) . ' ON CONFLICT (card_hash) DO NOTHING';

            $rsm = new ResultSetMappingBuilder($this->em);
            $this->em->createNativeQuery($sql, $rsm)->execute();
        }
        $this->em->commit();

        return 0;
    }

    private function maskCardNumber($number, $maskingCharacter = 'X'): string
    {
        return substr($number, 0, 4) . str_repeat($maskingCharacter, strlen($number) - 8) . substr($number, -4);
    }
}
