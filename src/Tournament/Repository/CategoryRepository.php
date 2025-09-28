<?php

namespace Tournament\Repository;

use Tournament\Model\Data\Category;
use PDO;

class CategoryRepository
{
   private $categories_by_tournament = [];
   private $categories_by_id = [];

   public function __construct(private PDO $pdo)
   {
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

   public function getCategoriesByTournamentId(int $tournamentId): array
   {
      if (!array_key_exists($tournamentId, $this->categories_by_tournament))
      {
         $stmt = $this->pdo->prepare("SELECT id, name, mode, config_json FROM categories WHERE tournament_id = :tournament_id order by id");
         $stmt->execute(['tournament_id' => $tournamentId]);
         $this->categories_by_tournament[$tournamentId] = [];
         foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
         {
            $category =  $this->categories_by_id[$row['id']]
                      ?? new Category(
               id: (int) $row['id'],
               tournament_id: $tournamentId,
               name: $row['name'],
               mode: $row['mode'],
               config: self::parseConfig($row['config_json'])
            );
            $this->categories_by_tournament[$tournamentId][$category->id] = $category;
            $this->categories_by_id[$category->id] = $category;
         }
      }
      return $this->categories_by_tournament[$tournamentId];
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
      $this->categories_by_id[$category->id] = $category;

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
      return $stmt->execute( [
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

   public function setCategoryParticipants(int $categoryId, array $participantIds): bool
   {
      // Remove old entries that are not in the new list
      if (!empty($participantIds))
      {
         $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
         $sql = "DELETE FROM participants_categories WHERE category_id = ? AND participant_id NOT IN ($placeholders)";
         $params = array_merge([$categoryId], $participantIds);
      }
      else
      {
         $sql = "DELETE FROM participants_categories WHERE category_id = ?";
         $params = [$categoryId];
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);

      // Then add any new participants
      if (empty($participantIds))
      {
         return true;
      }

      $values = [];
      $params = [];
      foreach ($participantIds as $participantId)
      {
         $values[] = "(?, ?)";
         $params[] = $participantId;
         $params[] = $categoryId;
      }
      $sql = "INSERT IGNORE INTO participants_categories (participant_id, category_id) VALUES " . implode(',', $values);
      $stmt = $this->pdo->prepare($sql);
      return $stmt->execute($params);
   }
}
