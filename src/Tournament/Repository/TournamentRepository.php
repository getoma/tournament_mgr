<?php

namespace Tournament\Repository;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;
use Tournament\Model\Tournament\TournamentCollection;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;

use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryCollection;

use PDO;

class TournamentRepository
{
   /** @var Tournaments[] */
   private $tournaments = []; // tournaments loaded tournaments by id for consecutive calls

   /**@var Area[] */
   private $areas = [];

   /**@var AreaCollection[] */
   private $areas_by_tournament = [];

   /** @var Category[] */
   private $categories = [];
   private $categories_by_tournament = [];

   public function __construct(private PDO $pdo)
   {
   }

   public function getAllTournaments(): TournamentCollection
   {
      $tournaments = new TournamentCollection();
      $stmt = $this->pdo->query("SELECT * FROM tournaments order by date asc, name asc");
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
      {
         $this->tournaments[$row['id']] ??= new Tournament(...$row);
         $tournaments[] = $this->tournaments[$row['id']];
      }
      return $tournaments;
   }

   public function getTournamentById($id): ?Tournament
   {
      if( !isset($this->tournaments[$id]) )
      {
         $stmt = $this->pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
         $stmt->execute(['id' => $id]);
         $data = $stmt->fetch(PDO::FETCH_ASSOC);
         if( $data )
         {
            $this->tournaments[$id] = new Tournament(...$data);
         }
      }
      return $this->tournaments[$id] ?? null;
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
      unset($this->tournaments[$id]);
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
         $this->tournaments[$t->id] = $t;
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
      if ($result && isset($this->tournaments[$id]))
      {
         $this->tournaments[$id]->status = $newStatus;
      }
      return $result;
   }

   public function getAreasByTournamentId($tournamentId): AreaCollection
   {
      if (isset($this->areas_by_tournament[$tournamentId]))
      {
         return new AreaCollection($this->areas_by_tournament[$tournamentId]);
      }

      $areas = [];
      $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE tournament_id = :tournamentId order by name");
      $stmt->execute(['tournamentId' => $tournamentId]);
      foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row)
      {
         $this->areas[$row['id']] ??= new Area(...$row);
         $areas[] = $this->areas[$row['id']];
      }
      $this->areas_by_tournament[$tournamentId] = $areas;
      return new AreaCollection($areas);
   }

   public function getAreaById($id): ?Area
   {
      if (!isset($this->areas[$id]))
      {
         $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE id = :id");
         $stmt->execute(['id' => $id]);
         $data = $stmt->fetch(\PDO::FETCH_ASSOC);
         if ($data)
         {
            $this->areas[$id] = new Area(...$data);
         }
      }
      return $this->areas[$id] ?? null;
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

   private static function parseConfig($json)
   {
      // decode json config
      $config = json_decode($json ?? '{}', true) ?: [];
      // downwards compatiblity: remove any config keys that are no longer used
      $config = array_intersect_key($config, Category::getValidationRules('details_only'));
      // done
      return $config;
   }

   public function getCategoriesByTournamentId(int $tournamentId): CategoryCollection
   {
      if (!isset($this->categories_by_tournament[$tournamentId]))
      {
         $stmt = $this->pdo->prepare("SELECT id, name, mode, config_json FROM categories WHERE tournament_id = :tournament_id order by id");
         $stmt->execute(['tournament_id' => $tournamentId]);
         $this->categories_by_tournament[$tournamentId] = [];
         foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
         {
            $category =  $this->categories[$row['id']]
               ?? new Category(
                  id: (int) $row['id'],
                  tournament_id: $tournamentId,
                  name: $row['name'],
                  mode: $row['mode'],
                  config: self::parseConfig($row['config_json'])
               );
            $this->categories_by_tournament[$tournamentId][] = $category;
            $this->categories[$category->id] = $category;
         }
      }
      return new CategoryCollection($this->categories_by_tournament[$tournamentId]);
   }

   public function getCategoryById(int $id): ?Category
   {
      $stmt = $this->pdo->prepare("SELECT id, tournament_id, name, mode, config_json FROM categories WHERE id = :id");
      $stmt->execute(['id' => $id]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$row) return null;

      $category = new Category(
         id: (int) $row['id'],
         tournament_id: (int) $row['tournament_id'],
         name: $row['name'],
         mode: $row['mode'],
         config: self::parseConfig($row['config_json'])
      );
      $this->categories[$category->id] = $category;

      return $category;
   }

   public function createCategory(int $tournamentId, string $name, string $mode): int
   {
      $stmt = $this->pdo->prepare("INSERT INTO categories (tournament_id, name, mode) VALUES (:tournament_id, :name, :mode)");
      $stmt->execute(["tournament_id" => $tournamentId, "name" => $name, "mode" => $mode]);
      return (int) $this->pdo->lastInsertId();
   }

   public function updateCategory(array $data): bool
   {
      $stmt = $this->pdo->prepare("UPDATE categories SET name = :name, mode = :mode WHERE id = :id");
      return $stmt->execute([
         'id' => $data['id'],
         'name' => $data['name'],
         'mode' => $data['mode']
      ]);
   }

   public function updateCategoryDetails(array $data): bool
   {
      $stmt = $this->pdo->prepare("UPDATE categories SET mode = :mode, config_json=:config WHERE id = :id");
      return $stmt->execute([
         'id' => $data['id'],
         'mode' => $data['mode'],
         'config' => json_encode($data['config'] ?? []),
      ]);
   }

   public function deleteCategory(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM categories WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

}
