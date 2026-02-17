<?php

namespace Tournament\Repository;

use Tournament\Model\User\Role;
use Tournament\Model\User\RoleCollection;
use Tournament\Model\User\User;
use Tournament\Model\User\UserCollection;

class UserRepository extends \Base\Repository\UserRepository
{
   /**
    * save or update an user into the database.
    */
   public function saveUser(User $user): void
   {
      if ($user->id === null)
      {
         // Insert new user
         // do not set password_hash - to be maintained by a dedicated service.
         // do not set last_login - cannot have a value for a non-existing user
         $stmt = $this->pdo->prepare("INSERT INTO users (email, display_name, is_active, created_at) VALUES (:email, :display_name, :is_active, :created_at)");
         $stmt->execute([
            ':email' => $user->email,
            ':display_name' => $user->display_name,
            ':is_active' => $user->is_active ? 1 : 0,
            ':created_at' => $user->created_at?->format('Y-m-d H:i:s'),
         ]);
         $user->id = (int)$this->pdo->lastInsertId();
      }
      else
      {
         // Update existing user
         // do not set email - shall not be updateable according current design / and/or has to be handled by dedicated service
         // do not set password_hash - to be maintained by a dedicated service.
         // do not set created_at - not modifyable
         $stmt = $this->pdo->prepare("UPDATE users SET display_name = :display_name, last_login = :last_login, is_active = :is_active WHERE id = :id");
         $stmt->execute([
            ':id' => $user->id,
            ':display_name' => $user->display_name,
            ':last_login' => $user->last_login?->format('Y-m-d H:i:s'),
            ':is_active' => $user->is_active ? 1 : 0,
         ]);
      }

      // Sync roles
      $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id")->execute([':user_id' => $user->id]);
      $stmt = $this->pdo->prepare("INSERT INTO user_roles (user_id, role_id) SELECT :user_id, id FROM roles WHERE name = :role_name");
      foreach ($user->roles as $role)
      {
         $stmt->execute([':user_id' => $user->id, ':role_name' => $role->value]);
      }
   }

   public function isMailUsed(string $email ): bool
   {
      $stmt = $this->pdo->prepare("select count(*) from users where email=?");
      $stmt->execute([$email]);
      return array_first($stmt->fetch());
   }

   public function deleteUser(int $id): bool
   {
      // only delete from users table. Any related tables (e.g. user_roles) will be cleaned by foreign key constraints
      $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

   public function getAllUsers(): UserCollection
   {
      $users = new UserCollection();
      $stmt = $this->pdo->query("SELECT * FROM users u");
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
      {
         $users[] = $this->createUserObject($row);
      }
      return $users;
   }

   /**
    * createUser overrides the Base Repository user creation
    */
   protected function createUserObject(array $data): User
   {
      if( isset($data['admin']) )
      {
         /* legacy compatibility code - if data still contains an admin field, translate to the new role */
         $roles = RoleCollection::new( $data['admin']? [Role::ADMIN->value] : [] );
         /* legacy model didn't have the is_active field, add it here */
         $data['is_active'] ??= true;
      }
      else
      {
         /* fetch roles from database */
         $stmt = $this->pdo->prepare("select r.name from roles r left join user_roles ur on r.id=ur.role_id where ur.user_id=?");
         $stmt->execute([$data['id']]);
         $roles = RoleCollection::new($stmt->fetchAll(\PDO::FETCH_COLUMN));
      }

      /* create the user */
      return new \Tournament\Model\User\User(
         id: $data['id'],
         email: $data['email'],
         password_hash: $data['password_hash'],
         display_name: $data['display_name'],
         created_at: new \DateTime($data['created_at']??'now'),
         last_login: isset($data['last_login'])? new \DateTime($data['last_login']) : null,
         roles: $roles,
         is_active: $data['is_active'],
      );
   }

   public function registerLogin(User $user): void
   {
      try
      {
         $stmt = $this->pdo->prepare("UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=?");
         $stmt->execute([$user->id ]);
      }
      catch( \PDOException $e )
      {
         // not mission critical, just log an error message but ignore this failure otherwise
         error_log("failed to register login time: " . $e->getMessage());
      }
   }
}
