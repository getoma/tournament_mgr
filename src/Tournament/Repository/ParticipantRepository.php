<?php

namespace Tournament\Repository;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\SlottedParticipantCollection;

use PDO;

class ParticipantRepository
{
   /* buffer all participant instances, to make sure that each participant is
    * represented by a unique instance
    */
   private $participants = [];

   /* buffer error messages */
   private $errors = [];

   public function __construct(private PDO $pdo, private TournamentRepository $tournamentRepo)
   {
   }

   public function getLastErrors(): array
   {
      return $this->errors;
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
         $categories = $this->tournamentRepo->getCategoriesByTournamentId($participant->tournament_id);
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
         if( empty($slot_name) || $result->keyExists($slot_name) )
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

   public function saveParticipant(Participant $p): bool
   {
      $result = false;
      $this->pdo->beginTransaction();

      if ($p->id)
      {
         $stmt = $this->pdo->prepare("UPDATE participants SET lastname = :lastname, firstname = :firstname, club = :club, separation_flag = :separation_flag WHERE id = :id");
         $result = $stmt->execute($p->asArray(['id', 'lastname', 'firstname', 'club', 'separation_flag']));
      }
      else
      {
         $stmt = $this->pdo->prepare( "INSERT INTO participants (tournament_id, lastname, firstname, club, separation_flag) "
                                    . "VALUES (:tournament_id, :lastname, :firstname, :club, :separation_flag)");
         $result = $stmt->execute($p->asArray(['tournament_id', 'lastname', 'firstname', 'club', 'separation_flag']));
         if ($result)
         {
            $p->id = $this->pdo->lastInsertId();
            $this->participants[$p->id] = $p;
         }
      }

      if( $result ) // also update participant categories
      {
         if ($p->categories->empty())
         {
            $sql = "DELETE FROM participants_categories WHERE participant_id = ?";
            $params = [$p->id];
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
         }
         else
         {
            // First, delete existing categories for the participant, preserving only those in $categoryIds
            $categoryIds = $p->categories->column('id');
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $sql = "DELETE FROM participants_categories WHERE participant_id = ? AND category_id NOT IN ($placeholders)";
            $params = array_merge([$p->id], $categoryIds);
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);

            // then add all linked category ids
            $values = [];
            $params = [];
            foreach ($categoryIds as $categoryId)
            {
               $values[] = "(?,?)";
               $params[] = $p->id;
               $params[] = $categoryId;
            }
            $sql = "INSERT IGNORE INTO participants_categories (participant_id, category_id) VALUES " . implode(',', $values);
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params) && $result;
         }
      }

      if ($result)
      {
         $this->pdo->commit();
      }
      else
      {
         $this->pdo->rollBack();
      }

      return $result;
   }

   public function deleteParticipant(int $id): bool
   {
      $stmt = $this->pdo->prepare("DELETE FROM participants WHERE id = :id");
      return $stmt->execute(['id' => $id]);
   }

   public function setCategoryParticipants(int $categoryId, array $participantIds): bool
   {
      // Remove old entries that are not in the new list
      if (count($participantIds))
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
      if (count($participantIds))
      {
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

      return true;
   }

   /**
    * import list of participants
    */
   public function importParticipants(ParticipantCollection $participants): bool
   {
      $this->errors = [];
      $this->pdo->beginTransaction();
      $stmt_p = $this->pdo->prepare( "INSERT INTO participants (tournament_id, lastname, firstname, club, separation_flag) "
                                   . "VALUES (:tournament_id, :lastname, :firstname, :club, :separation_flag)");
      $stmt_c = $this->pdo->prepare("INSERT INTO participants_categories (participant_id, category_id) VALUES (?,?)");

      /** @var Participant $p */
      foreach ($participants as $p)
      {
         if( $stmt_p->execute($p->asArray(['tournament_id', 'lastname', 'firstname', 'club', 'separation_flag'])) )
         {
            $p->id = $this->pdo->lastInsertId();
            foreach( $p->categories as $c )
            {
               $stmt_c->execute([$p->id, $c->id]); // cannot fail
            }
         }
         else
         {
            $this->errors[] = $stmt_p->errorInfo()[2];
         }
      }
      $this->pdo->commit();
      return true;
   }
}
