<?php

namespace Tournament\Repository;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentCollection;
use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryCollection;

use PDO;
use Tournament\Model\Category\CategoryConfiguration;

class TournamentRepository
{
   /**@var Tournament[] */
   private $tournaments = [];

   /**@var Area[] */
   private $areas = [];
   /**@var AreaCollection[] */
   private $areas_by_tournament = [];

   /**@var Category[] */
   private $categories = [];

   /**@var CategoryCollection[] */
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
            $data['categories'] = $this->getCategoriesByTournamentId($id);
            $this->tournaments[$id] = new Tournament(...$data);
         }
      }
      return $this->tournaments[$id] ?? null;
   }

   public function saveTournament(Tournament $t): bool
   {
      $result = false;
      if ($t->id)
      {
         $stmt = $this->pdo->prepare("UPDATE tournaments SET name = :name, date = :date, status = :status, notes = :notes WHERE id = :id");
         $result = $stmt->execute($t->asArray(['id', 'name', 'date', 'status', 'notes']));
      }
      else
      {
         $stmt = $this->pdo->prepare("INSERT INTO tournaments (name, date, status, notes) VALUES (:name, :date, :status, :notes)");
         $result = $stmt->execute($t->asArray(['name', 'date', 'status', 'notes']));
         if( $result )
         {
            $t->id = $this->pdo->lastInsertId();
            $this->tournaments[$t->id] = $t;
         }
      }
      return $result;
   }

   public function deleteTournament(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM tournaments WHERE id = :id");
      return $stmt->execute(['id' => $id]);
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

   public function saveArea(Area $area): bool
   {
      $result = false;
      if ($area->id)
      {
         $stmt = $this->pdo->prepare("UPDATE areas SET name = :name WHERE id = :id");
         $result = $stmt->execute($area->asArray(['id', 'name']));
      }
      else
      {
         $stmt = $this->pdo->prepare("INSERT INTO areas (name, tournament_id) VALUES (:name, :tournament_id)");
         $result = $stmt->execute($area->asArray(['name', 'tournament_id']));
         if ($result)
         {
            $area->id = $this->pdo->lastInsertId();
            $this->areas[$area->id] = $area;
            $this->areas_by_tournament[$area->tournament_id][] = $area;
         }
      }
      return $result;
   }

   public function deleteArea($areaId): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM areas WHERE id = :id");
      return $stmt->execute(['id' => $areaId]);
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
                  config: CategoryConfiguration::load($row['config_json'])
               );
            $this->categories_by_tournament[$tournamentId][] = $category;
            $this->categories[$category->id] = $category;
         }
      }
      return new CategoryCollection($this->categories_by_tournament[$tournamentId]);
   }

   public function getCategoryById(int $id): ?Category
   {
      if( !isset($this->categories[$id]) )
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
            config: CategoryConfiguration::load($row['config_json'])
         );
         $this->categories[$category->id] = $category;
      }

      return $this->categories[$id] ?? null;
   }

   public function saveCategory(Category $category): bool
   {
      $result = false;
      if ($category->id)
      {
         $stmt = $this->pdo->prepare("UPDATE categories SET name = :name, mode = :mode, config_json = :config WHERE id = :id");
         $result = $stmt->execute($category->asArray(['id', 'name', 'mode']) + ['config' => $category->config->json()]);
      }
      else
      {
         $stmt = $this->pdo->prepare("INSERT INTO categories (tournament_id, name, mode, config_json) VALUES (:tournament_id, :name, :mode, :config)");
         $result = $stmt->execute($category->asArray(['tournament_id', 'name', 'mode']) + ['config' => $category->config->json()]);
         if ($result)
         {
            $category->id = $this->pdo->lastInsertId();
            $this->categories[$category->id] = $category;
            $this->categories_by_tournament[$category->tournament_id][] = $category;
         }
      }
      return $result;
   }

   public function deleteCategory(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM categories WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }
}
