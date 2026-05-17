<?php declare(strict_types=1);

namespace Tournament\Repository;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentCollection;
use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\TournamentStructure\AreaMapping;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\User\UserCollection;

use PDO;

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

   public function __construct(private PDO $pdo, private UserRepository $user_repo)
   {
   }

   private function createTournamentObject(array $data): Tournament
   {
      $id = $data['id'];
      if( isset($this->tournaments[$id]) ) return $this->tournaments[$id];

      $data['categories'] = $this->getCategoriesByTournamentId($id);
      $data['owners'] = $this->getTournamentOwners($id);
      $result = new Tournament(...$data);
      $this->tournaments[$id] = $result;
      return $result;
   }

   public function getAllTournaments(): TournamentCollection
   {
      $result = new TournamentCollection();
      $stmt = $this->pdo->query("SELECT * FROM tournaments order by date asc, name asc");
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
      {
         $result[] = $this->createTournamentObject($row);
      }
      return $result;
   }

   public function getTournamentById(int $id): ?Tournament
   {
      if( isset($this->tournaments[$id]) ) return $this->tournaments[$id];

      $stmt = $this->pdo->prepare("SELECT * FROM tournaments WHERE id = :id");
      $stmt->execute(['id' => $id]);
      $data = $stmt->fetch(PDO::FETCH_ASSOC);
      return $data? $this->createTournamentObject($data) : null;
   }

   public function getTournamentOwners(int $id): UserCollection
   {
      $stmt = $this->pdo->prepare("SELECT user_id from tournament_owners where tournament_id=:id");
      $stmt->execute(['id' => $id]);
      $owner_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
      $users = $this->user_repo->getAllUsers();
      return $users->filter(fn($u) => in_array($u->id, $owner_ids));
   }

   public function saveTournament(Tournament $t): void
   {
      $this->pdo->beginTransaction();

      if ($t->id)
      {
         $stmt = $this->pdo->prepare("UPDATE tournaments SET name = :name, date = :date, status = :status, notes = :notes WHERE id = :id");
         $stmt->execute($t->asArray('id', 'name', 'date', 'status', 'notes'));
      }
      else
      {
         $stmt = $this->pdo->prepare("INSERT INTO tournaments (name, date, status, notes) VALUES (:name, :date, :status, :notes)");
         $stmt->execute($t->asArray('name', 'date', 'status', 'notes'));
         $t->id = (int)$this->pdo->lastInsertId();
         $this->tournaments[$t->id] = $t;
      }

      /* also update ownership */
      if( $t->owners->empty() )
      {
         $stmt = $this->pdo->prepare("DELETE FROM tournament_owners WHERE tournament_id = :id");
         $stmt->execute(['id' => $t->id]);
      }
      else
      {
         // only delete removed owners - to not accidently delete any related records
         // via foreign key constraints. At the moment, we don't have that, but be forward-compatible
         $placeholders = implode(',', array_fill(0, $t->owners->count(), '?'));
         $sql = "DELETE FROM tournament_owners WHERE tournament_id = ? AND user_id NOT IN ($placeholders)";
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute( array_merge([$t->id], $t->owners->column('id')) );

         // add any possible new relationships
         $stmt = $this->pdo->prepare("INSERT IGNORE INTO tournament_owners (tournament_id, user_id) VALUES (:tournament_id, :user_id)");
         foreach ($t->owners as $owner)
         {
            $stmt->execute(['tournament_id' => $t->id, 'user_id' => $owner->id]);
         }
      }

      $this->pdo->commit();
   }

   public function deleteTournament(int $id): void
   {
      $this->pdo->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$id]);
   }

   public function getAreasByTournamentId(int $tournamentId): AreaCollection
   {
      if (isset($this->areas_by_tournament[$tournamentId]))
      {
         return new AreaCollection($this->areas_by_tournament[$tournamentId]);
      }

      $areas = [];
      $stmt = $this->pdo->prepare("SELECT * FROM areas WHERE tournament_id = :tournamentId order by name");
      $stmt->execute(['tournamentId' => $tournamentId]);
      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
      {
         $this->areas[$row['id']] ??= new Area(...$row);
         $areas[] = $this->areas[$row['id']];
      }
      $this->areas_by_tournament[$tournamentId] = $areas;
      return new AreaCollection($areas);
   }

   public function getAreaById(int $id): ?Area
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

   public function getTournamentByAreaId(int $id): ?Tournament
   {
      $stmt = $this->pdo->prepare("SELECT t.* FROM tournaments t JOIN areas a ON t.id = a.tournament_id WHERE a.id = :id");
      $stmt->execute(['id' => $id]);
      $data = $stmt->fetch(PDO::FETCH_ASSOC);
      return $data? $this->createTournamentObject($data) : null;
   }

   public function saveArea(Area $area): void
   {
      if ($area->id)
      {
         $stmt = $this->pdo->prepare("UPDATE areas SET name = :name WHERE id = :id");
         $stmt->execute($area->asArray('id', 'name'));
      }
      else
      {
         $stmt = $this->pdo->prepare("INSERT INTO areas (name, tournament_id) VALUES (:name, :tournament_id)");
         $stmt->execute($area->asArray('name', 'tournament_id'));
         $area->id = (int)$this->pdo->lastInsertId();
         $this->areas[$area->id] = $area;
         $this->areas_by_tournament[$area->tournament_id][] = $area;
      }
   }

   public function deleteArea(int $areaId): void
   {
      $this->pdo->prepare("DELETE FROM areas WHERE id=?")->execute([$areaId]);
   }

   public function getCategoriesByTournamentId(int $tournamentId): CategoryCollection
   {
      if (!isset($this->categories_by_tournament[$tournamentId]))
      {
         $stmt = $this->pdo->prepare("SELECT id, name, mode, config_json FROM categories WHERE tournament_id = :tournament_id order by id");
         $stmt->execute(['tournament_id' => $tournamentId]);
         $this->categories_by_tournament[$tournamentId] = [];
         while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
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

   public function saveCategory(Category $category): void
   {
      if ($category->id)
      {
         $stmt = $this->pdo->prepare("UPDATE categories SET name = :name, mode = :mode, config_json = :config WHERE id = :id");
         $stmt->execute($category->asArray('id', 'name', 'mode', 'config'));
      }
      else
      {
         $stmt = $this->pdo->prepare(<<<QUERY
            INSERT INTO categories (tournament_id, name, mode, config_json) VALUES (:tournament_id, :name, :mode, :config)
         QUERY);
         $stmt->execute($category->asArray('tournament_id', 'name', 'mode', 'config'));
         $category->id = (int)$this->pdo->lastInsertId();
         $this->categories[$category->id] = $category;
         $this->categories_by_tournament[$category->tournament_id][] = $category;
      }
   }

   public function deleteCategory(int $id): void
   {
      $this->pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
   }

   public function getMatchAreaMappingByCategoryId(int $id): AreaMapping
   {
      $stmt = $this->pdo->prepare("SELECT type, name, area_id FROM match_areas WHERE category_id = ?");
      $stmt->execute([$id]);
      $result = new AreaMapping();
      while ($e = $stmt->fetch(\PDO::FETCH_ASSOC))
      {
         $result->store(...$e);
      }
      return $result;
   }

   public function storeAreaAssignment(MatchNode|Pool $entity): void
   {
      $type = match(true)
      {
         $entity instanceof MatchNode => AreaMapping::NODE,
         $entity instanceof Pool      => AreaMapping::POOL,
         default => throw new \InvalidArgumentException('unsupported type ' . get_class($entity))
      };

      $stmt = $this->pdo->prepare(<<<QUERY
         INSERT INTO match_areas (category_id, type, name, area_id) VALUES (:category_id, :type, :name, :area_id)
         ON DUPLICATE KEY UPDATE area_id=:u_area_id
      QUERY);

      $stmt->execute([
         'category_id' => $entity->category->id,
         'type'        => $type,
         'name'        => $entity->getName(),
         'area_id'     => $entity->getArea()->id,
         'u_area_id'   => $entity->getArea()->id,
      ]);
   }
}
