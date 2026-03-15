<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ParticipantWithdraw extends AbstractMigration
{
   public function change()
   {
      $this->table('participants')
         ->addColumn('withdrawn', 'boolean', ['null' => false, 'default' => false])
         ->update();
   }
}
