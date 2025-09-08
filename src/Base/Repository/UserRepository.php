<?php

namespace Base\Repository;

use Base\Model\User;
use Base\Service\SessionService;
use PDO;

class UserRepository
{
   /* buffer of session user lookups to avoid multiple DB queries in one request */
   private $sessionUsers = [];

   public function __construct(private \PDO $pdo, private SessionService $session)
   {
   }

   public function findById(int $id): ?User
   {
       $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id");
       $stmt->execute(['id' => $id]);
       $data = $stmt->fetch();
       return $data ? new User(...$data) : null;
   }

   public function findByEmail(string $email): ?User
   {
       $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email");
       $stmt->execute(['email' => $email]);
       $data = $stmt->fetch();
       return $data ? new User(...$data) : null;
   }

   public function updateUser(int $id, string $displayName, bool $admin): bool
   {
       $stmt = $this->pdo->prepare("UPDATE users SET display_name = :displayName, admin = :admin WHERE id = :id");
       return $stmt->execute([
           'displayName' => $displayName,
           'admin' => $admin,
           'id' => $id
       ]);
   }

   public function updateUserPassword(int $id, string $hashedPassword): bool
   {
       $stmt = $this->pdo->prepare("UPDATE users SET password_hash = :passwordHash WHERE id = :id");
       return $stmt->execute([
           'passwordHash' => $hashedPassword,
           'id' => $id
       ]);
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
       foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
           $users[] = new User(...$row);
       }
       return $users;
   }

   public function storePasswordResetToken(int $userId, string $tokenHash, int $expiry_minutes): bool
   {
      $stmt = $this->pdo->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at)
            VALUES (:user_id, :token_hash, NOW() + INTERVAL :expiry_minutes MINUTE)
        ");
      return $stmt->execute([
         'user_id'    => $userId,
         'token_hash' => $tokenHash,
         'expiry_minutes' => $expiry_minutes
      ]);
   }

   public function findResetTokensForUser(string $userId): array
   {
      $stmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE user_id = :user_id AND expires_at > NOW()");
      $stmt->execute(['user_id' => $userId]);
      return $stmt->fetchAll(\PDO::FETCH_ASSOC);
   }

   public function cleanupResetTokens(): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
      return $stmt->execute();
   }

   public function deleteResetTokensForUser(int $userId): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE user_id = :user_id");
      return $stmt->execute(['user_id' => $userId]);
   }

   public function deleteResetToken(string $tokenHash): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE token_hash = :token_hash");
      return $stmt->execute(['token_hash' => $tokenHash]);
   }

   public function setSessionUserId(int $userId, ?string $sessionId = null): bool
   {
      if ($sessionId === null)
      {
         $sessionId = $this->session->id();
      }
      $stmt = $this->pdo->prepare("UPDATE sessions SET user_id = :user_id WHERE id = :sid");
      return $stmt->execute([
         'user_id' => $userId,
         'sid' => $sessionId
      ]);
   }

   public function getSessionUser(?string $sessionId = null): ?User
   {
      if ($sessionId === null)
      {
         $sessionId = $this->session->id();
      }
      if (isset($this->sessionUsers[$sessionId]))
      {
         return $this->sessionUsers[$sessionId];
      }
      $stmt = $this->pdo->prepare("SELECT u.* FROM users u JOIN sessions s ON u.id = s.user_id WHERE s.id = :sid");
      $stmt->execute(['sid' => $sessionId]);
      $data = $stmt->fetch();
      $user = $data ? new User(...$data) : null;
      $this->sessionUsers[$sessionId] = $user;
      return $user;
   }

   public function destroySessionsForUser(int $userId, bool $keepCurrent = false): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE user_id = :user_id" . ($keepCurrent ? " AND id != :current_sid" : ""));
      $params = ['user_id' => $userId];
      if ($keepCurrent)
      {
         $params['current_sid'] = $this->session->id();
      }
      return $stmt->execute($params);
   }

}