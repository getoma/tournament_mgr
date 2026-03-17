<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MatchAreas extends AbstractMigration
{
   public function change()
   {
      $this->table('match_areas')
         ->addColumn('category_id', 'integer', ['signed' => false, 'null' => false])
         ->addColumn('type', 'string', ['limit' => 127, 'null' => false])
         ->addColumn('name', 'string', ['limit' => 127, 'null' => false])
         ->addColumn('area_id', 'integer', ['signed' => false, 'null' => false])
         ->addIndex(['category_id', 'type', 'name'], ['unique' => true])
         ->addForeignKey('category_id', 'categories', 'id', [ 'delete' => 'CASCADE', 'constraint' => 'fk_match_areas_category'])
         ->addForeignKey('area_id', 'areas', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_match_areas_area'])
         ->create();
   }
}
