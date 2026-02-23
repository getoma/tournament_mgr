<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * this table was part of the initial scheme, but is not actually used anywhere
 */
final class DropUserTournaments extends AbstractMigration
{
   public function up()
   {
      if($this->hasTable('user_tournaments'))
      {
         $this->table('user_tournaments')->drop()->save();
      }
   }

   public function down()
   {
   }
}
