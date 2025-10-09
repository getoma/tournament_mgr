<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTableMatchPoints extends AbstractMigration
{
   public function change(): void
   {
      try
      {
         $this->table('match_points')
            ->addColumn('match_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('participant_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('point', 'char', ['limit' => 1, 'null' => false])
            ->addColumn('given_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('caused_by',  'integer', ['null' => true, 'signed' => false])
            ->addForeignKey('match_id', 'matches', 'id', ['delete'=> 'CASCADE', 'update'=> 'CASCADE'])
            ->addForeignKey('participant_id', 'participants', 'id', ['delete'=> 'RESTRICT', 'update'=> 'CASCADE'])
            ->addForeignKey('caused_by', 'match_points', 'id', ['delete' => 'SET NULL', 'update' => 'CASCADE'])
            ->create();

      }
      catch (\PDOException $e)
      {
         /* make sure any half-created table is purged again */
         if ($this->hasTable('match_points')) $this->table('match_points')->drop()->save();
         throw $e;
      }
   }
}
