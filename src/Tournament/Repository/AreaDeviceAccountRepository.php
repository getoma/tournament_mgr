<?php

namespace Tournament\Repository;

use Tournament\Model\AreaDevices\AreaDeviceLoginCode;
use Tournament\Model\AreaDevices\AreaDeviceLoginCodeCollection;
use Tournament\Model\AreaDevices\AreaDeviceSession;
use Tournament\Model\AreaDevices\AreaDeviceSessionCollection;

class AreaDeviceAccountRepository
{
   private readonly \DateTimeZone $utc;

   /**
    * for now, I decided to not care about forensics, i.e. keep any login code or session for analysis purposes.
    * So, immediately remove any expired entries as soon as meaningful.
    */
   const KEEP_OLD_ENTRIES = false;

   public function __construct(
      private \PDO $pdo,
   )
   {
      $this->utc = new \DateTimeZone('UTC');
   }

   private function createAreaDeviceLoginCodeObject(array $data): AreaDeviceLoginCode
   {
      return new AreaDeviceLoginCode(
         id: $data['id'],
         code: $data['code'],
         area_id: $data['area_id'],
         created_at: new \DateTimeImmutable($data['created_at'], $this->utc),
         expires_at: new \DateTimeImmutable($data['expires_at'], $this->utc),
         used_at: isset($data['used_at']) ? new \DateTime($data['used_at'], $this->utc) : null,
         invalidated_at: isset($data['invalidated_at']) ? new \DateTime($data['invalidated_at'], $this->utc) : null,
      );
   }

   /**
    * get the current login codes for this tournament, regardless of their state
    */
   public function getCurrentLoginCodesByTournamentId(int $tournamentId): AreaDeviceLoginCodeCollection
   {
      $stmt = $this->pdo->prepare(<<<QUERY
      SELECT *
      FROM area_device_login_codes
      WHERE id IN (
         SELECT max(lc.id)
         FROM area_device_login_codes lc
         LEFT JOIN areas ON areas.id=lc.area_id
         WHERE areas.tournament_id = ?
         GROUP BY area_id
      )
      QUERY);
      $stmt->execute([$tournamentId]);
      $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
      return new AreaDeviceLoginCodeCollection(array_map(fn($row) => $this->createAreaDeviceLoginCodeObject($row), $result));
   }

   public function storeLoginCode(int $area_id, string $code, \DateTimeInterface $expires_at): void
   {
      $expires_at_utc = \DateTime::createFromInterface($expires_at);
      $expires_at_utc->setTimezone($this->utc);

      if( !static::KEEP_OLD_ENTRIES )
      {
         $this->pdo->prepare('DELETE FROM area_device_login_codes WHERE area_id=?')->execute([$area_id]);
      }

      $stmt = $this->pdo->prepare('INSERT INTO area_device_login_codes (area_id, code, expires_at) VALUES (:area_id, :code, :expires_at)');
      $stmt->execute([
         ':area_id' => $area_id,
         ':code' => $code,
         ':expires_at' => $expires_at_utc->format('Y-m-d H:i:s'),
      ]);
   }

   public function findValidLoginCode(string $code): ?AreaDeviceLoginCode
   {
      $stmt = $this->pdo->prepare('SELECT * FROM area_device_login_codes WHERE code = ? AND used_at IS NULL AND invalidated_at IS NULL AND expires_at > CURRENT_TIMESTAMP');
      $stmt->execute([$code]);
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      return $data ? $this->createAreaDeviceLoginCodeObject($data) : null;
   }

   public function markLoginCodeUsed(int $code_id): void
   {
      if (static::KEEP_OLD_ENTRIES)
      {
         $this->pdo->prepare('UPDATE area_device_login_codes SET used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$code_id]);
      }
      else
      {
         $this->pdo->prepare('DELETE FROM area_device_login_codes WHERE id=?')->execute([$code_id]);
      }
   }

   public function invalidateLoginCode(int $area_id): void
   {
      /* never delete here to enable a "code expired/invalidated" display */
      $stmt = $this->pdo->prepare('UPDATE area_device_login_codes SET invalidated_at = CURRENT_TIMESTAMP WHERE area_id = ? AND used_at IS NULL AND invalidated_at IS NULL');
      $stmt->execute([$area_id]);
   }

   public function cleanLoginCodesByTournamentId(int $tournamentId): void
   {
      $this->pdo->prepare(<<<QUERY
      DELETE FROM area_device_login_codes
      WHERE (expires_at < CURRENT_TIMESTAMP OR used_at IS NOT NULL OR invalidated_at IS NOT NULL)
        AND area_id IN (SELECT id FROM areas WHERE tournament_id = ?)
      QUERY)
      ->execute([$tournamentId]);
   }

   private function createSessionObject(array $data): AreaDeviceSession
   {
      return new AreaDeviceSession(
         id: $data['id'],
         area_id: $data['area_id'],
         created_at: new \DateTimeImmutable($data['created_at'], $this->utc),
         expires_at: new \DateTimeImmutable($data['expires_at'], $this->utc),
         invalidated_at: isset($data['invalidated_at']) ? new \DateTime($data['invalidated_at'], $this->utc) : null,
         last_activity_at: new \DateTime($data['last_activity_at'], $this->utc),
      );
   }

   public function createSession(int $area_id, \DateTimeInterface $expires_at): AreaDeviceSession
   {
      if (!static::KEEP_OLD_ENTRIES)
      {
         $this->pdo->prepare('DELETE FROM area_device_sessions WHERE area_id=?')->execute([$area_id]);
      }

      $stmt = $this->pdo->prepare(' INSERT INTO area_device_sessions (area_id, expires_at, last_activity_at, token_hash)'
                                 .' VALUES (:area_id, :expires_at, CURRENT_TIMESTAMP, "")');
      $stmt->execute([
         ':area_id' => $area_id,
         ':expires_at' => $expires_at->format('Y-m-d H:i:s'),
      ]);
      $id = $this->pdo->lastInsertId();
      return $this->getValidSessionById($id);
   }

   /**
    * get the latest device sessions for each area for a tournament, regardless of their current state
    */
   public function getCurrentSessionsPerTournamentId(int $tournamentId): AreaDeviceSessionCollection
   {
      $stmt = $this->pdo->prepare(<<<QUERY
      SELECT *
      FROM area_device_sessions
      WHERE id IN (
         SELECT max(ads.id)
         FROM area_device_sessions ads
         LEFT JOIN areas ON areas.id=ads.area_id
         WHERE areas.tournament_id = ?
         GROUP BY area_id
      )
      QUERY);
      $stmt->execute([$tournamentId]);
      $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
      return new AreaDeviceSessionCollection(array_map(fn($row) => $this->createSessionObject($row), $result));
   }

   public function getValidSessionById(int $id): ?AreaDeviceSession
   {
      $stmt = $this->pdo->prepare('SELECT * FROM area_device_sessions WHERE id = ? AND expires_at > CURRENT_TIMESTAMP AND invalidated_at IS NULL');
      $stmt->execute([$id]);
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      return $data ? $this->createSessionObject($data) : null;
   }

   public function findValidSessionByToken(string $token_hash): ?AreaDeviceSession
   {
      $stmt = $this->pdo->prepare('SELECT * FROM area_device_sessions WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP AND invalidated_at IS NULL');
      $stmt->execute([$token_hash]);
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      return $data ? $this->createSessionObject($data) : null;
   }

   public function setTokenHash(int $id, string $token_hash): void
   {
      $stmt = $this->pdo->prepare('UPDATE area_device_sessions SET token_hash = :token_hash WHERE id = :id');
      $stmt->execute([
         'id' => $id,
         'token_hash' => $token_hash,
      ]);
   }

   public function updateSessionActivity(int $id): void
   {
      $stmt = $this->pdo->prepare('UPDATE area_device_sessions SET last_activity_at = CURRENT_TIMESTAMP WHERE id = ?');
      $stmt->execute([$id]);
   }

   public function invalidateSession(int $id): void
   {
      $stmt = $this->pdo->prepare('UPDATE area_device_sessions SET invalidated_at = CURRENT_TIMESTAMP WHERE id = ?');
      $stmt->execute([$id]);
   }

   public function invalidateSessionByAreaId(int $area_id): void
   {
      $stmt = $this->pdo->prepare('UPDATE area_device_sessions SET invalidated_at = CURRENT_TIMESTAMP WHERE area_id = ?');
      $stmt->execute([$area_id]);
   }

   public function cleanSessionsByTournamentId(int $tournamentId): void
   {
      $stmt = $this->pdo->prepare(<<<QUERY
      DELETE FROM area_device_sessions
      WHERE (expires_at < CURRENT_TIMESTAMP OR invalidated_at IS NOT NULL)
        AND area_id IN (SELECT id FROM areas WHERE tournament_id = ?)
      QUERY);
      $stmt->execute([$tournamentId]);
   }
}
