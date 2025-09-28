<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTableMatches extends AbstractMigration
{
   public function change(): void
   {
      try
      {
         $this->table('matches')
            ->addColumn('name', 'string', ['limit' => 127, 'null' => false])
            ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('area_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('red_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('white_id', 'integer', ['null' => false, 'signed' => false])
            ->addColumn('winner_id', 'integer', ['null' => true, 'signed' => false, 'default' => null])
            ->addColumn('tie_break', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('finalized_at', 'datetime', ['null' => true, 'default' => null])
            ->addForeignKey('category_id', 'categories', 'id', ['delete'=> 'CASCADE', 'update'=> 'CASCADE'])
            ->addForeignKey('white_id', 'participants', 'id', ['delete'=> 'CASCADE', 'update'=> 'CASCADE'])
            ->addForeignKey('red_id', 'participants', 'id', ['delete'=> 'CASCADE', 'update'=> 'CASCADE'])
            ->addForeignKey('winner_id', 'participants', 'id', ['delete'=> 'RESTRICT', 'update'=> 'CASCADE'])
            ->addForeignKey('area_id', 'areas', 'id', ['delete'=> 'RESTRICT', 'update'=> 'CASCADE'])
            ->addIndex(['category_id', 'name'], ['unique' => true])
            ->create();
      }
      catch (\PDOException $e)
      {
         /* make sure any half-created table is purged again */
         if ($this->hasTable('matches')) $this->table('matches')->drop()->save();
         throw $e;
      }
   }
}
