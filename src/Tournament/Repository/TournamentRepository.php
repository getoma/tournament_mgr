<?php

namespace Tournament\Repository;

use Tournament\Model\Data\Tournament;
use Tournament\Model\Data\TournamentStatus;

use PDO;

class TournamentRepository
{
   private $buffer = []; // buffer loaded tournaments by id for consecutive calls

   public function __construct(private PDO $pdo)
   {
   }

   public function getAllTournaments(): array
   {
      $tournaments = [];
      $stmt = $this->pdo->query("SELECT * FROM tournaments order by date asc, name asc");
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
      {
         $t = new Tournament(...$row);
         if( $this->buffer[$t->id] ?? false ) continue;
         $this->buffer[$t->id] = $t;
      }
      return array_values($this->buffer);
   }

   public function getTournamentById($id): ?Tournament
   {
      if( !isset($this->buffer[$id]) )
      {
         $stmt = $this->pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
         $stmt->execute(['id' => $id]);
         $data = $stmt->fetch(PDO::FETCH_ASSOC);
         if( $data )
         {
            $this->buffer[$id] = new Tournament(...$data);
         }
      }
      return $this->buffer[$id] ?? null;
   }

   public function getTournamentByName($name): ?Tournament
   {
      $stmt = $this->pdo->prepare("SELECT * FROM tournaments WHERE name = :name AND status = 'active'");
      $stmt->execute(['name' => $name]);
      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
      if (count($results) === 1)
      {
         return new Tournament(...$results[0]);
      }
      return null;
   }

   public function createTournament(string $name, string $date, string $notes): int
   {
      $stmt = $this->pdo->prepare("INSERT INTO tournaments (name, date, notes) VALUES (:name, :date, :notes)");
      $stmt->execute(['name' => $name, 'date' => $date, 'notes' => $notes]);
      return $this->pdo->lastInsertId();
   }

   public function deleteTournament(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM tournaments WHERE id = :id");
      unset($this->buffer[$id]);
      return $stmt->execute(['id' => $id]);
   }

   public function updateTournament(Tournament $t): bool
   {
      $stmt = $this->pdo->prepare("UPDATE tournaments SET name = :name, date = :date, notes = :notes WHERE id = :id");
      $result = $stmt->execute([
         'name' => $t->name,
         'date' => $t->date,
         'notes' => $t->notes,
         'id' => $t->id
      ]);
      if ($result)
      {
         $this->buffer[$t->id] = $t;
      }
      return $result;
   }

   public function updateState(int $id, TournamentStatus $newStatus): bool
   {
      $stmt = $this->pdo->prepare("UPDATE tournaments SET status = :status WHERE id = :id");
      $result = $stmt->execute([
         'status' => $newStatus->value,
         'id' => $id
      ]);
      if ($result && isset($this->buffer[$id]))
      {
         $this->buffer[$id]->status = $newStatus;
      }
      return $result;
   }
}
