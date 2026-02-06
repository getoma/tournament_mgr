<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Tournament\Model\User\Role;

final class UserExtension extends AbstractMigration
{
   public function up(): void
   {
      /* create the roles table and seed it from the Role enumeration */
      $this->table('roles')
         ->addColumn('name', 'string', ['length' => 127, 'null' => false ] )
         ->addIndex(['name'], ['unique' => true] )
         ->create();
      $this->table('roles')
         ->insert( array_map(fn($role) => ['name' => $role->value], Role::cases()) )
         ->saveData();

      /* create mapping table user<->roles */
      $this->table('user_roles', ['id' => false, 'primary_key' => ['user_id', 'role_id']])
         ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
         ->addColumn('role_id', 'integer', ['signed' => false, 'null' => false])
         ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'constraint' => 'USER_ID'])
         ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'constraint' => 'ROLE_ID'])
         ->create();

      /* translate the admin flag to the new role system */
      $this->query('insert into user_roles select u.id, r.id from users u, roles r where u.admin=TRUE and r.name=?', [Role::ADMIN->value]);

      /* extend users table */
      $this->table('users')
         ->removeColumn('admin')
         ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('last_login', 'datetime')
         ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
         ->save();
   }

   public function down(): void
   {
      /* delete any inactive users (active flag didn't exist before this) */
      $this->query("delete from users where is_active=FALSE");

      /* reset users table */
      $this->table('users')
         ->addColumn('admin', 'boolean', ['null' => false, 'default' => false])
         ->removeColumn('created_at')
         ->removeColumn('last_login')
         ->removeColumn('is_active')
         ->save();

      /* recover admin flag from roles */
      $this->query(<<<'QUERY'
      UPDATE users u
             left join user_roles ur on u.id=ur.user_id
             left join roles r       on ur.role_id=r.id
      SET u.admin=1
      WHERE r.name=?
QUERY, [Role::ADMIN->value]);

      /* remove role tables */
      $this->table('user_roles')->drop()->save();
      $this->table('roles')->drop()->save();
   }
}