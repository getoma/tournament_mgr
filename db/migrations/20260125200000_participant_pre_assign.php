<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ParticipantPreAssign extends AbstractMigration
{
   public function up(): void
   {
      $this->table('participants')
         ->removeColumn('separation_flag')
         ->update();

      $this->table('participants_categories')
         ->addColumn('pre_assign', 'string', ['length' => 127, 'null' => true])
         ->addIndex(['category_id', 'pre_assign'], ['unique' => true, 'name' => 'UC_SLOT_PRE_ASSIGN'])
         ->update();
   }

   public function down(): void
   {
      $this->table('participants')
         ->addColumn('separation_flag', 'boolean', ['null' => false, 'default' => false])
         ->update();

      $this->table('participants_categories')
         ->removeIndex(['category_id', 'pre_assign'])
         ->removeColumn('pre_assign')
         ->update();
   }
}
