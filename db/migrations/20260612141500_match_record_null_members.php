<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MatchRecordNullMembers extends AbstractMigration
{
   public function up()
   {
      $this->table('matches')
         ->changeColumn('red_id', 'integer', ['null' => true, 'signed' => false])
         ->changeColumn('white_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'red_id'])
         ->update();
   }

   public function down()
   {
      $this->execute(<<<QUERY
         DELETE FROM matches WHERE red_id IS NULL OR white_id IS NULL
      QUERY);

      $this->table('matches')
         ->changeColumn('red_id', 'integer', ['null' => false, 'signed' => false])
         ->changeColumn('white_id', 'integer', ['null' => false, 'signed' => false])
         ->update();
   }

}