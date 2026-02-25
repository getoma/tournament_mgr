<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DeviceAccounts extends AbstractMigration
{
   public function change()
   {
      $this->table('area_device_login_codes')
         ->addColumn('area_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('code', 'string', ['length' => 64, 'null' => false])
         ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('expires_at', 'datetime', ['null' => false])
         ->addColumn('used_at', 'datetime', ['null' => true])
         ->addColumn('invalidated_at', 'datetime', ['null' => true])
         ->addForeignKey('area_id', 'areas', 'id', ['delete' => 'CASCADE', 'constraint' => 'AREA_ID'])
         ->create();

      $this->table('area_device_sessions')
         ->addColumn('area_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('expires_at', 'datetime', ['null' => false])
         ->addColumn('invalidated_at', 'datetime', ['null' => true])
         ->addColumn('last_activity_at', 'datetime', ['null' => false])
         ->addColumn('last_php_session_id', 'string', ['length' => 128, 'null' => false])
         ->addForeignKey('area_id', 'areas', 'id', ['delete' => 'CASCADE', 'constraint' => 'AREA_ID'])
         ->create();
   }
}