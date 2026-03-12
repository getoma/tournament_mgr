<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ChangeLog extends AbstractMigration
{
   public function change()
   {
      $this->table('change_log')
         ->addColumn('entity_type', 'string', ['length' => 127, 'null' => false])
         ->addColumn('entity_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('group_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('change_type', 'string', ['length' => 127, 'null' => false])
         ->addColumn('changed_at', 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('changed_by', 'integer', ['null' => true, 'signed' => false])
         ->addColumn('details', 'json', ['null' => false])
         ->addForeignKey('changed_by', 'users', 'id', ['delete' => 'SET_NULL', 'constraint' => 'change_log_user_id'])
         ->addForeignKey('group_id', 'tournaments', 'id', ['delete' => 'CASCADE', 'constraint' => 'change_log_tournament_id'])
         ->create();
   }
}
