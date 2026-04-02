<?php declare(strict_types=1);

namespace Tournament\Repository;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\CategoryAssignment;
use Tournament\Model\Participant\Team;
use Tournament\Model\Participant\TeamCollection;

use PDO;

class ParticipantRepository
{
   /** @var ParticipantCollection $participants
    * buffer all participant instances, to make sure that each participant is
    * represented by a unique instance
    */
   private $participants;

   /**
    * @var TeamCollection $teams
    */
   private $teams;

   /* buffer error messages */
   private $errors = [];

   public function __construct(private PDO $pdo)
   {
      $this->participants = ParticipantCollection::new();
   }

   public function getLastErrors(): array
   {
      return $this->errors;
   }

   /**
    * create a participant instance from fetched data, buffer each participant
    * and do not re-create any existing instance to preserve instance identity for each participant
    */
   private function getParticipantInstance(array $data): Participant
   {
      /* create the participant instance, if not there yet */
      $this->participants[$data['id']] ??= new Participant(
         id: (int)$data['id'],
         tournament_id: (int)$data['tournament_id'],
         lastname: $data['lastname'],
         firstname: $data['firstname'],
         club: $data['club'] ?: null,
         withdrawn: (bool)($data['withdrawn'] ?? false),
      );
      $participant = $this->participants[$data['id']];

      /* transform the category mapping from sql string output to our php data struct
       * this list might not exists or be incomplete also for pre-existing Participant instances,
       * depending on the context it was created before
       */
      $category_list = isset($data['category_id'])? array_map(fn($cid) => (int)$cid, explode(',', $data['category_id'])) : [];
      $pre_assign = explode(',', $data['pre_assign']??'');
      $slots      = explode(',', $data['slot_name']??'');
      foreach($category_list as $i => $cid)
      {
         $participant->categories[$cid] = new CategoryAssignment(
            categoryId: $cid,
            pre_assign: $pre_assign[$i] ?: null,
            slot_name: $slots[$i] ?: null
         );
      }

      /* done */
      return $participant;
   }

   /**
    * create a team instance from fetched data, buffer each team
    * and do not re-create any existing instance to preserve instance identity for each team
    */
   private function getTeamInstance(array $data): Team
   {
      if( !$this->teams->keyExists($data['id']) )
      {
         $team = Team::createFromArray($data['category_id'], $data);

         /* fetch participants */
         $stmt = $this->pdo->prepare(<<<QUERY
            SELECT p.*
            FROM team_participants tp LEFT JOIN participants p on tp.participant_id=p.id
            where team_id=?
         QUERY);
         $stmt->execute([$team->id]);
         foreach( $stmt->fetchAll(PDO::FETCH_ASSOC) as $p_row )
         {
            $team->members[] = $this->getParticipantInstance($p_row);
         }

         $this->teams[$data['id']] = $team;
      }
      return $this->teams[$data['id']];
   }

   /**
    * Get all participants for a tournament
    */
   public function getParticipantsByTournamentId(int $tournamentId): ParticipantCollection
   {
      /* fetch all participants for a tournament, with category ids */
      $stmt = $this->pdo->prepare(<<<QUERY
         SELECT p.*, GROUP_CONCAT(pc.category_id) AS category_id,
         GROUP_CONCAT(IFNULL(pc.pre_assign,'')) as pre_assign, GROUP_CONCAT(IFNULL(pc.slot_name,'')) as slot_name
         FROM participants p LEFT JOIN participants_categories pc ON p.id = pc.participant_id
         WHERE p.tournament_id = :tournament_id
         GROUP BY p.id
         ORDER BY p.lastname, p.firstname
      QUERY
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
    * get all participants for a specific category
    */
   public function getParticipantsByCategoryId(int $categoryId): ParticipantCollection
   {
      $stmt = $this->pdo->prepare(<<<QUERY
         SELECT p.*, CONCAT(pc.category_id) as category_id, pc.slot_name, pc.pre_assign
         FROM participants_categories pc LEFT JOIN participants p ON p.id = pc.participant_id
         WHERE pc.category_id = :category_id
         ORDER BY p.lastname, p.firstname
      QUERY);
      /* category_id is encased in "CONCAT" to make it a string for getParticipantInstance() compatibility,
       * this function expects a comma-separated list for this field
       */
      $stmt->execute(['category_id' => $categoryId]);

      $result = new ParticipantCollection();
      foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $result[$row['id']] = $this->getParticipantInstance($row);
      }
      return $result;
   }

   /**
    * Get all teams for a specific category
    */
   public function getTeamsByCategoryId(int $categoryId): TeamCollection
   {
      $stmt = $this->pdo->prepare(<<<QUERY
         SELECT t.id, t.name
         FROM teams t
         WHERE t.category_id=?
         ORDER BY t.id
      QUERY);
      $stmt->execute([$categoryId]);

      $result = new TeamCollection();
      foreach( $stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $result[] = $this->getTeamInstance($row);
      }
      return $result;
   }

   /**
    * update the slots for each participant
    */
   public function updateAllParticipantSlots(int $categoryId, ParticipantCollection $participants): void
   {
      $this->pdo->beginTransaction();
      $clear_stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=null WHERE category_id=:category_id"
      );
      $clear_stmt->execute(['category_id' => $categoryId]);

      $update_stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=:slot_name WHERE category_id=:category_id AND participant_id=:participant_id"
      );

      foreach( $participants as $p )
      {
         $update_stmt->execute([
            'slot_name' => $p->categories[$categoryId]->slot_name,
            'category_id' => $categoryId,
            'participant_id' => $p->id
         ]);
      }
      $this->pdo->commit();
   }

   /**
    * free participant slots for a single participant
    */
   public function freeParticipantSlots(int $participantId): void
   {
      $stmt = $this->pdo->prepare(
         "UPDATE participants_categories SET slot_name=null WHERE participant_id=:participant_id"
      );
      $stmt->execute(['participant_id' => $participantId]);
   }

   /**
    * Get a single participant by ID with category information
    */
   public function getParticipantById(int $id): ?Participant
   {
      $participant = $this->participants[$id] ?? null;
      if( !$participant )
      {
         $stmt = $this->pdo->prepare(<<<QUERY
            SELECT p.*, GROUP_CONCAT(pc.category_id) AS category_id,
            GROUP_CONCAT(IFNULL(pc.slot_name,'')) as slot_name, GROUP_CONCAT(IFNULL(pc.pre_assign,'')) as pre_assign
            FROM participants p LEFT JOIN participants_categories pc ON p.id = pc.participant_id
            WHERE p.id = :id
         QUERY);
         $stmt->execute(['id' => $id]);
         $row = $stmt->fetch(PDO::FETCH_ASSOC);
         $participant = $row? $this->getParticipantInstance($row) : null;
      }
      return $participant;
   }

   /**
    * get a single team by ID
    */
   public function getTeamById(int $id): ?Team
   {
      $team = $this->teams[$id] ?? null;
      if( !$team )
      {
         $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE id = ?");
         $stmt->execute([$id]);
         $row = $stmt->fetch(PDO::FETCH_ASSOC);
         $team = $row? $this->getTeamInstance($row) : null;
      }
      return $team;
   }

   /**
    * save a single participant into the database
    */
   public function saveParticipant(Participant $p): void
   {
      $this->pdo->beginTransaction();

      if ($p->id)
      {
         $this->pdo->prepare("UPDATE participants SET lastname=:lastname, firstname=:firstname, club=:club, withdrawn=:withdrawn WHERE id = :id")
                   ->execute($p->asArray(['id', 'lastname', 'firstname', 'club', 'withdrawn']));
      }
      else
      {
         $this->pdo->prepare(<<<QUERY
            INSERT INTO participants (tournament_id, lastname, firstname, club, withdrawn)
            VALUES (:tournament_id, :lastname, :firstname, :club, :withdrawn)
         QUERY)->execute($p->asArray(['tournament_id', 'lastname', 'firstname', 'club', 'withdrawn']));
         $p->id = (int)$this->pdo->lastInsertId();
         $this->participants[$p->id] = $p;
      }

      if ($p->categories->empty())
      {
         // If no categories set (anymore), just delete all assigments
         $this->pdo->prepare("DELETE FROM participants_categories WHERE participant_id = ?")
                   ->execute([$p->id]);
      }
      else
      {
         // otherwise, delete any category assignments that are not set (anymore)
         // DO NOT just delete everything and re-add, because that would also drop any
         // related data (e.g. start slot assignments)
         $placeholders = implode(',', array_fill(0, $p->categories->count(), '?'));
         $sql = "DELETE FROM participants_categories WHERE participant_id = ? AND category_id NOT IN ($placeholders)";
         $params = array_merge([$p->id], $p->categories->map(fn($ca) => $ca->categoryId));
         $this->pdo->prepare($sql)->execute($params);

         // then add all linked category ids, respectively update the pre_assign values
         $stmt = $this->pdo->prepare(<<<'QUERY'
            INSERT INTO participants_categories (participant_id, category_id, pre_assign) VALUES (:pid, :cid, :set_assign)
            ON DUPLICATE KEY UPDATE pre_assign=:update_assign
         QUERY);
         /** @var CategoryAssignment $ca */
         foreach ($p->categories as $ca)
         {
            $stmt->execute([
               ':pid' => $p->id,
               ':cid' => $ca->categoryId,
               ':set_assign'    => $ca->pre_assign,
               ':update_assign' => $ca->pre_assign
            ]);
         }
      }

      $this->pdo->commit();
   }

   /**
    * save a single team into the database
    */
   public function saveTeam(Team $team): void
   {
      $this->pdo->beginTransaction();
      if ($team->id)
      {
         $this->pdo->prepare("UPDATE teams SET name=:name, withdrawn=:withdrawn WHERE id = :id")
                   ->execute($team->asArray(['id', 'name', 'withdrawn']));
      }
      else
      {
         $this->pdo->prepare("INSERT INTO teams (category_id, name, withdrawn) VALUES (:category_id, :name, :withdrawn)")
                   ->execute($team->asArray(['category_id', 'name', 'withdrawn']));
         $team->id = (int)$this->pdo->lastInsertId();
         $this->teams[$team->id] = $team;
      }

      /* save team members */
      $this->pdo->prepare("DELETE FROM team_participants WHERE team_id = ?")
                ->execute([$team->id]);

      if( !$team->members->empty() )
      {
         $values = [];
         $params = [];
         foreach ($team->members as $p)
         {
            $values[] = "(?, ?, ?)";
            $params[] = $team->id;
            $params[] = $team->categoryId;
            $params[] = $p->id;
         }
         $sql = "INSERT INTO team_participants (team_id, category_id, participant_id) VALUES " . implode(',', $values);
         $this->pdo->prepare($sql)->execute($params);
      }

      $this->pdo->commit();
   }

   /**
    * delete a single participant from the database
    */
   public function deleteParticipant(int $id): void
   {
      $this->pdo->prepare("DELETE FROM participants WHERE id=?")
                ->execute([$id]);
   }

   /**
    * delete a single team from the database
    */
   public function deleteTeam(int $id): void
   {
      $this->pdo->prepare("DELETE FROM teams WHERE id=?")
                ->execute([$id]);
   }

   /**
    * import list of participants, ignore any duplicate additions
    */
   public function importParticipants(ParticipantCollection $participants): void
   {
      $this->errors = [];
      $this->pdo->beginTransaction();
      $stmt_p = $this->pdo->prepare( "INSERT INTO participants (tournament_id, lastname, firstname, club) "
                                   . "VALUES (:tournament_id, :lastname, :firstname, :club)");
      $stmt_c = $this->pdo->prepare("INSERT IGNORE INTO participants_categories (participant_id, category_id) VALUES (?,?)");

      /** @var Participant $p */
      foreach ($participants as $p)
      {
         if( !isset($p->id) ) // a really new participant, import the participant data itself
         {
            $stmt_p->execute($p->asArray(['tournament_id', 'lastname', 'firstname', 'club']));
            $p->id = (int)$this->pdo->lastInsertId();
         }

         /* also take over any category updates */
         foreach( $p->categories as $c )
         {
            $stmt_c->execute([$p->id, $c->categoryId]);
         }
      }
      $this->pdo->commit();
   }

   /**
    * get a list of current pre-assignments of starting slots
    * this is a minimal getter for just retrieving a list of current pre-assignments,
    * e.g. for checking it against a list of free slots or a single participant.
    * for getting more exhaustive data including participant data, please use the corresponding
    * functions (e.g. getParticipantsByTournamentId)
    */
   public function getPreAssignmentsByTournamentId(int $tournamentId): array
   {
      $stmt = $this->pdo->prepare(<<<QUERY
         SELECT pc.category_id, pc.pre_assign
         FROM participants_categories pc
         LEFT JOIN participants p ON p.id = pc.participant_id
         WHERE p.tournament_id = ?
         AND pc.pre_assign IS NOT NULL AND pc.pre_assign <> ''
      QUERY);
      $stmt->execute([$tournamentId]);

      $result = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $categoryId = (int)$row['category_id'];
         $result[$categoryId][] = $row['pre_assign'];
      }
      return $result;
   }
}
