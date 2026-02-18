<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ParticipantsClub extends AbstractMigration
{
   public function up(): void
   {
      $this->table('participants')
         ->addColumn('club', 'string', ['length' => 127, 'null' => true])
         ->addColumn('separation_flag', 'boolean', ['null' => false, 'default' => false])
         ->removeIndex(['tournament_id', 'lastname', 'firstname'])
         ->addIndex(['tournament_id', 'lastname', 'firstname', 'club'], ['unique' => true, 'name' => 'UC_PARTICIPANT_DATA'])
         ->update();
   }

   public function down(): void
   {
      $this->table('participants')
         ->removeIndex(['tournament_id', 'lastname', 'firstname', 'club'])
         ->addIndex(['tournament_id', 'lastname', 'firstname'], ['unique' => true, 'name' => 'UC_PARTICIPANT_NAME'])
         ->removeColumn('separation_flag')
         ->removeColumn('club')
         ->update();
   }
}