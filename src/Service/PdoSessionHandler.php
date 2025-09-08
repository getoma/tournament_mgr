<?php

namespace App\Service;
use PDO;
use SessionHandlerInterface;

class PdoSessionHandler implements SessionHandlerInterface
{
   public function __construct(private PDO $pdo)
   {}

   public function open($savePath, $sessionName): bool
   {
      return true;
   }

   public function close(): bool
   {
      return true;
   }

   public function read($id): string
   {
      $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
      $stmt->execute(['id' => $id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      return $row ? $row['data'] : '';
   }

   public function write($id, $data): bool
   {
      $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, data, last_access)
            VALUES (:id, :idata, NOW())
            ON DUPLICATE KEY UPDATE data = :udata, last_access = NOW()
        ");
      return $stmt->execute(['id' => $id, 'idata' => $data, 'udata' => $data]);
   }

   public function destroy($id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

   public function gc($maxlifetime): int|false
   {
      $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_access < (NOW() - INTERVAL :maxlifetime SECOND)");
      $stmt->execute(['maxlifetime' => $maxlifetime]);
      return $stmt->rowCount();
   }
}
