<?php

namespace Tournament\Repository;

use Tournament\Model\Data\Area;

class AreaRepository
{
   public function __construct(private \PDO $pdo)
   {
   }

   public function getAreasByTournamentId($tournamentId): array
   {
      $areas = [];
      $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE tournament_id = :tournamentId order by name");
      $stmt->execute(['tournamentId' => $tournamentId]);
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
      {
         $areas[] = new Area(...$row);
      }
      return $areas;
   }

   public function getAreaById($id): ?Area
   {
      $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE id = :id");
      $stmt->execute(['id' => $id]);
      $data = $stmt->fetch(\PDO::FETCH_ASSOC);
      return $data ? new Area(...$data) : null;
   }

   public function createArea(Area $area): Area
   {
      $stmt = $this->pdo->prepare("INSERT INTO areas (name, tournament_id) VALUES (:name, :tournamentId)");
      $stmt->execute(['name' => $area->name, 'tournamentId' => $area->tournament_id]);
      $area->id = $this->pdo->lastInsertId();
      return $area;
   }

   public function deleteArea($areaId): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM areas WHERE id = :id");
      return $stmt->execute(['id' => $areaId]);
   }

   public function updateArea(Area $area): bool
   {
      $stmt = $this->pdo->prepare("UPDATE areas SET name = :name WHERE id = :id");
      return $stmt->execute([
         'name' => $area->name,
         'id' => $area->id
      ]);
   }
}