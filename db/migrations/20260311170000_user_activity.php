<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserActivity extends AbstractMigration
{
   public function change()
   {
      $this->table('users')
         ->renameColumn('last_login', 'last_activity_at')
         ->update();
   }
}
