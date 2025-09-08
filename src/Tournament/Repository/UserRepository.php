<?php

namespace Tournament\Repository;

use Tournament\Model\User\User;

class UserRepository extends \Base\Repository\UserRepository
{
   public function updateUser(int $id, string $displayName, bool $admin): bool
   {
      $stmt = $this->pdo->prepare("UPDATE users SET display_name = :displayName, admin = :admin WHERE id = :id");
      return $stmt->execute([
         'displayName' => $displayName,
         'admin' => $admin,
         'id' => $id
      ]);
   }

   protected function createUser(array $data): User
   {
      return new \Tournament\Model\User\User(...$data);
   }

   public function deleteUser(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

   public function getAllUsers(): array
   {
      $users = [];
      $stmt = $this->pdo->query("SELECT * FROM users");
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
      {
         $users[] = $this->createUser($row);
      }
      return $users;
   }
}
