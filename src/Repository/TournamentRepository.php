<?php

namespace App\Repository;

use App\Model\Data\Tournament;

use PDO;

class TournamentRepository
{
   public function __construct(private PDO $pdo)
   {
   }

   public function getAllTournaments(): array
   {
      $tournaments = [];
      $stmt = $this->pdo->query("SELECT * FROM tournaments order by date asc, name asc");
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
      {
         $tournaments[] = new Tournament(...$row);
      }
      return $tournaments;
   }

   public function getTournamentById($id): ?Tournament
   {
      $stmt = $this->pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
      $stmt->execute(['id' => $id]);
      $data = $stmt->fetch(PDO::FETCH_ASSOC);
      if( $data )
      {
         return new Tournament(...$data);
      }
      return null;
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
      return $stmt->execute(['id' => $id]);
   }

   public function updateTournament(Tournament $t)
   {
      $stmt = $this->pdo->prepare("UPDATE tournaments SET name = :name, date = :date, status = :status, notes = :notes WHERE id = :id");
      $stmt->execute([
         'name' => $t->name,
         'date' => $t->date,
         'status' => $t->status,
         'notes' => $t->notes,
         'id' => $t->id
      ]);
   }
}