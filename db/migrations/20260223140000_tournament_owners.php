<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TournamentOwners extends AbstractMigration
{
   public function change()
   {
      $this->table('tournament_owners', ['id' => false, 'primary_key' => ['user_id', 'tournament_id']])
         ->addColumn('user_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('tournament_id', 'integer', ['null' => false, 'signed' => false])
         ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'constraint' => 'USER_ID'])
         ->addForeignKey('tournament_id', 'tournaments', 'id', ['delete' => 'CASCADE', 'constraint' => 'TOURNAMENT_ID'])
         ->create();
   }
}