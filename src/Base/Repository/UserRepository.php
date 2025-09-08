<?php

namespace Base\Repository;

use Base\Model\User;
use Base\Service\SessionService;
use PDO;

class UserRepository
{
   /* buffer of session user lookups to avoid multiple DB queries in one request */
   private $sessionUsers = [];

   public function __construct(protected \PDO $pdo, private SessionService $session)
   {
   }

   /**
    * centralized generation of User
    * Enables injection of a specialized User class in derived classes.
    */
   protected function createUser(array $data): User
   {
      return new User(...$data);
   }

   public function findByEmail(string $email): ?User
   {
      $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email");
      $stmt->execute(['email' => $email]);
      $data = $stmt->fetch();
      return $data ? $this->createUser($data) : null;
   }

   public function findByUsername(string $username): ?User
   {
      $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = :username");
      $stmt->execute(['username' => $username]);
      $data = $stmt->fetch();
      return $data ? $this->createUser($data) : null;
   }

   public function updateUserPassword(int $id, string $hashedPassword): bool
   {
      $stmt = $this->pdo->prepare("UPDATE users SET password_hash = :passwordHash WHERE id = :id");
      return $stmt->execute([
         'passwordHash' => $hashedPassword,
         'id' => $id
      ]);
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
      $user = $data ? $this->createUser($data) : null;
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
