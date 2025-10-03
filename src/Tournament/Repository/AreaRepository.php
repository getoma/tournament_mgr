<?php

namespace Tournament\Repository;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;

class AreaRepository
{
   /**@var Area[] */
   private $buffer = [];

   /**@var AreaCollection[] */
   private $buffer_by_tournament = [];

   public function __construct(private \PDO $pdo)
   {
   }

   public function getAreasByTournamentId($tournamentId): AreaCollection
   {
      if( isset($this->buffer_by_tournament[$tournamentId]) )
      {
         return new AreaCollection($this->buffer_by_tournament[$tournamentId]);
      }

      $areas = [];
      $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE tournament_id = :tournamentId order by name");
      $stmt->execute(['tournamentId' => $tournamentId]);
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
      {
         $this->buffer[$row['id']] ??= new Area(...$row);
         $areas[] = $this->buffer[$row['id']];
      }
      $this->buffer_by_tournament[$tournamentId] = $areas;
      return new AreaCollection($areas);
   }

   public function getAreaById($id): ?Area
   {
      if( !isset($this->buffer[$id]) )
      {
         $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE id = :id");
         $stmt->execute(['id' => $id]);
         $data = $stmt->fetch(\PDO::FETCH_ASSOC);
         if( $data )
         {
            $this->buffer[$id] = new Area(...$data);
         }
      }
      return $this->buffer[$id] ?? null;
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