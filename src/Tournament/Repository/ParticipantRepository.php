<?php

namespace Tournament\Repository;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\SlottedParticipantCollection;

use Tournament\Repository\CategoryRepository;

use PDO;

class ParticipantRepository
{
   /* buffer all participant instances, to make sure that each participant is
    * represented by a unique instance
    */
   private $participants = [];

   public function __construct(private PDO $pdo, private CategoryRepository $categoryRepo)
   {
   }

   /**
    * create a participant instance from fetched data
    */
   private function getParticipantInstance(array $data): Participant
   {
      /* extract the link to the categories first */
      $category_data = $data['category_ids'] ?? '';
      unset($data['category_ids']);

      /* create the participant instance, if not there yet */
      $this->participants[$data['id']] ??= new Participant(...$data);
      $participant = $this->participants[$data['id']];

      /* transform the category mapping from sql string output to php data struct if needed and possible */
      if( $participant->categories->empty() && !empty($category_data) )
      {
         $categories = $this->categoryRepo->getCategoriesByTournamentId($participant->tournament_id);
         foreach(explode(',', $category_data) as $categoryId)
         {
            $participant->categories[$categoryId] = $categories[$categoryId];
         }
      }

      /* done */
      return $participant;
   }

   /**
    * Get all participants for a tournament, optionally with category information
    */
   public function getParticipantsByTournamentId(int $tournamentId): ParticipantCollection
   {
      /* fetch all participants for a tournament, with category ids */
      $stmt = $this->pdo->prepare(
         "SELECT p.*, GROUP_CONCAT(pc.category_id) AS category_ids "
            . "FROM participants p LEFT JOIN participants_categories pc ON p.id = pc.participant_id "
            . "WHERE p.tournament_id = :tournament_id "
            . "GROUP BY p.id "
            . "ORDER BY p.lastname, p.firstname "
      );
      $stmt->execute(['tournament_id' => $tournamentId]);
      $result = new ParticipantCollection();
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
      {
         $result[$row['id']] = $this->getParticipantInstance($row);
      }
      return $result;
   }

   /**
    * get all participants for a specific category - category data will not be set
    */
   public function getParticipantsByCategoryId(int $categoryId): ParticipantCollection
   {
      $stmt = $this->pdo->prepare(
         "SELECT p.* "
            . "FROM participants_categories pc LEFT JOIN participants p ON p.id = pc.participant_id "
            . "WHERE pc.category_id = :category_id "
            . "ORDER BY p.lastname, p.firstname"
      );
      $stmt->execute(['category_id' => $categoryId]);

      $result = new ParticipantCollection();
      foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $result[$row['id']] = $this->getParticipantInstance($row);
      }
      return $result;
   }

   /**
    * get all participants for a specific category, identified by their slot
    * category data will not be set
    */
   public function getParticipantsWithSlotByCategoryId(int $categoryId): SlottedParticipantCollection
   {
      $stmt = $this->pdo->prepare(
         "SELECT p.*, pc.slot_name "
            . "FROM participants_categories pc LEFT JOIN participants p ON p.id = pc.participant_id "
            . "WHERE pc.category_id = :category_id "
            . "ORDER BY participant_id"
      );
      $stmt->execute(['category_id' => $categoryId]);

      $result = new SlottedParticipantCollection();
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $slot_name = $row['slot_name'];
         unset($row['slot_name']);

         $participant = $this->getParticipantInstance($row);
         if( empty($slot_name) || $result->has($slot_name) )
         {
            $result->addUnslotted($participant);
         }
         else
         {
            $result[$slot_name] = $participant;
         }
      }

      return $result;
   }

   /**
    * update the slots for each participant
    */
   public function updateAllParticipantSlots(int $categoryId, SlottedParticipantCollection $participants): bool
   {
      $this->pdo->beginTransaction();
      $clear_stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=null WHERE category_id=:category_id"
      );
      $clear_stmt->execute(['category_id' => $categoryId]);

      $update_stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=:slot_name WHERE category_id=:category_id AND participant_id=:participant_id"
      );

      foreach( $participants as $slot_name => $p )
      {
         $update_stmt->execute([
            'slot_name' => $slot_name,
            'category_id' => $categoryId,
            'participant_id' => $p->id
         ]);
      }
      $this->pdo->commit();
      return true;
   }

   /**
    * update a single participant slot
    */
   public function updateParticipantSlot(int $categoryId, string $slot_name, int $participant_id)
   {
      $update_stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=:slot_name WHERE category_id=:category_id AND participant_id=:participant_id"
      );
      return $update_stmt->execute([
         'slot_name' => $slot_name,
         'category_id' => $categoryId,
         'participant_id' => $participant_id
      ]);
   }

   /**
    * Get a single participant by ID with category information
    */
   public function getParticipantById(int $id): ?Participant
   {
      $participant = $this->participants[$id] ?? null;
      if( !$participant )
      {
         $stmt = $this->pdo->prepare(
            "SELECT p.*, GROUP_CONCAT(pc.category_id) AS category_ids "
               . "FROM participants p LEFT JOIN participants_categories pc ON p.id = pc.participant_id "
               . "WHERE p.id = :id "
         );
         $stmt->execute(['id' => $id]);
         $row = $stmt->fetch(PDO::FETCH_ASSOC);
         $participant = $row? $this->getParticipantInstance($row) : null;
      }
      return $participant;
   }

   public function addParticipant(int $tournament_id, string $lastname, string $firstname, array $categories = []): ?int
   {
      $stmt = $this->pdo->prepare("INSERT IGNORE INTO participants (tournament_id, lastname, firstname) VALUES (:tournament_id, :lastname, :firstname)");
      if ($stmt->execute(["tournament_id" => $tournament_id, "lastname" => $lastname, "firstname" => $firstname]))
      {
         $participantId = (int) $this->pdo->lastInsertId();
         // Set categories for the new participant
         if (!empty($categories))
         {
            $this->setParticipantCategories($participantId, $categories);
         }
         return $participantId;
      }
      return null;
   }

   public function updateParticipant(int $id, array $data): bool
   {
      $stmt = $this->pdo->prepare("UPDATE participants SET lastname = :lastname, firstname = :firstname WHERE id = :id");
      if ($stmt->execute(["id" => $id, "lastname" => $data['lastname'], "firstname" => $data['firstname']]))
      {
         // Set categories for the new participant
         if (!empty($data['categories']))
         {
            $this->setParticipantCategories($id, $data['categories']);
         }
         return true;
      }
      return false;
   }

   public function deleteParticipant(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM participants WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

   public function setParticipantCategories(int $participantId, array $categoryIds): bool
   {
      $this->pdo->beginTransaction();
      // First, delete existing categories for the participant, preserving only those in $categoryIds
      if (!empty($categoryIds))
      {
         $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
         $sql = "DELETE FROM participants_categories WHERE participant_id = ? AND category_id NOT IN ($placeholders)";
         $params = array_merge([$participantId], $categoryIds);
      }
      else
      {
         $sql = "DELETE FROM participants_categories WHERE participant_id = ?";
         $params = [$participantId];
      }
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute($params);

      // Then, insert new categories
      if (empty($categoryIds))
      {
         return true; // No categories to set
      }

      $values = [];
      $params = [];
      foreach ($categoryIds as $categoryId)
      {
         $values[] = "(?,?)";
         $params[] = $participantId;
         $params[] = $categoryId;
      }
      $sql = "INSERT IGNORE INTO participants_categories (participant_id, category_id) VALUES " . implode(',', $values);
      $stmt = $this->pdo->prepare($sql);
      $result = $stmt->execute($params);
      $this->pdo->commit();
      return $result;
   }

   public function importParticipants(int $tournamentId, array $participants, array $categories): array
   {
      /* TODO: duplicate decection for already existing participants (same firstname, lastname) */
      // Chunk insert participants (no need for duplicate detection, INSERT IGNORE handles it)
      $values = [];
      $params = [];
      foreach ($participants as $p)
      {
         if (isset($p['lastname'], $p['firstname']))
         {
            $values[] = "(?,?,?)";
            $params[] = $tournamentId;
            $params[] = $p['lastname'];
            $params[] = $p['firstname'];
         }
      }
      if ($values)
      {
         $sql = "INSERT IGNORE INTO participants (tournament_id, lastname, firstname) VALUES " . implode(',', $values);
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute($params);
      }

      // Fetch all participant ids
      $getpkey = fn($participant) => strtolower(trim($participant['lastname'])) . '|' . strtolower(trim($participant['firstname']));
      $stmt = $this->pdo->prepare("SELECT id, lastname, firstname FROM participants WHERE tournament_id = ?");
      $stmt->execute([$tournamentId]);
      $idMap = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
      {
         $idMap[$getpkey($row)] = $row['id'];
      }

      // Prepare chunk insert for participants_categories (all participants to all given categories) while fetching participant ids
      $imported = [];
      $catValues = [];
      $catParams = [];
      foreach ($participants as $p)
      {
         if (isset($p['lastname'], $p['firstname']))
         {
            $pid = $idMap[$getpkey($p)] ?? null;
            if ($pid && !empty($categories))
            {
               foreach ($categories as $catId)
               {
                  $catValues[] = "(?,?)";
                  $catParams[] = $pid;
                  $catParams[] = $catId;
               }
            }
            if ($pid)
            {
               $imported[] = $pid;
            }
         }
      }
      if ($catValues)
      {
         $sql = "INSERT IGNORE INTO participants_categories (participant_id, category_id) VALUES " . implode(',', $catValues);
         $stmt = $this->pdo->prepare($sql);
         $stmt->execute($catParams);
      }

      return $imported;
   }
}
