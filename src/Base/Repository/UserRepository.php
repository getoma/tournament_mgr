<?php

namespace Base\Repository;

use Base\Model\User;
use Base\Service\SessionService;
use PDO;

class UserRepository
{
   /* buffer of session user lookups to avoid multiple DB queries in one request */
   private $sessionUsers = [];

   protected const BASE_SELECT_USER_QUERY = "SELECT * FROM users";

   public function __construct(protected \PDO $pdo, private SessionService $session)
   {
   }

   /**
    * centralized generation of User
    * Enables injection of a specialized User class in derived classes.
    */
   protected function createUserObject(array $data): User
   {
      return new User(...$data);
   }

   /**
    * generalized user fetcher
    */
   public function findUser(array $filter): ?User
   {
      $filter_string = join(' and ', array_map(fn($k) => "$k=:$k", array_keys($filter)));
      try
      {
         $stmt = $this->pdo->prepare(static::BASE_SELECT_USER_QUERY . " where " . $filter_string);
         $stmt->execute($filter);
      }
      catch( \PDOException $e )
      {
         /* the query failed, most probably cause is that the static BASE_SELECT_USER_QUERY is faulty.
          * fall back by trying once more with the original BASE_SELECT_USER_QUERY
          * if it fails again, just let the exception fly
          */
         $stmt = $this->pdo->prepare(self::BASE_SELECT_USER_QUERY . " where " . $filter_string);
         $stmt->execute($filter);
      }
      $data = $stmt->fetch();
      return $data ? $this->createUserObject($data) : null;
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
      $user = $data ? $this->createUserObject($data) : null;
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
