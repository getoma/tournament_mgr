<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropLegacyTeams extends AbstractMigration
{
   public function up()
   {
      $pc = $this->table('participants_categories');
      if( $pc->hasColumn('team_id') )
      {
         $pc->dropForeignKey('team_id')->removeColumn('team_id')->update();
      }

      if( $this->hasTable('teams') )
      {
         $this->table('teams')->drop()->save();
      }
   }

   public function down()
   {
      /* do nothing - the stuff removed by up() is nowhere used anyway */
   }
}