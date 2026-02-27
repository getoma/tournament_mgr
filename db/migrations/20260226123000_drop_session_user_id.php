<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DropSessionUserId extends AbstractMigration
{
   public function up()
   {
      $this->table('sessions')
         ->dropForeignKey('user_id')
         ->removeColumn('user_id')
         ->save();

      $this->table('users')
         ->addColumn('session_version', 'integer', ['signed' => false, 'null' => false, 'default' => 1])
         ->save();
   }

   public function down()
   {
      $this->table('sessions')
         ->addColumn('user_id', 'integer', ['signed' => false, 'after' => 'id'])
         ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
         ->save();

      $this->table('users')
         ->removeColumn('session_version')
         ->save();
   }

}