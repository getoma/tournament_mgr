<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RememberMeToken extends AbstractMigration
{
   public function change()
   {
      $this->table('users')
         ->addColumn('remember_me_token', 'string', [ 'length' => 255, 'null' => true, 'after' => 'password_hash' ])
         ->update();

      $this->table('area_device_sessions')
         ->renameColumn('last_php_session_id', 'token_hash')
         ->update();
   }
}