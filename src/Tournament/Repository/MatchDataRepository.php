<?php declare(strict_types=1);

namespace Tournament\Repository;

use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\MatchRecord\MatchPointCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;

class MatchDataRepository
{
   public function __construct(
      private \PDO $pdo,
      private ParticipantRepository $participant_repo,
      private TournamentRepository $tournament_repo
      )
   {
   }

   /**
    * get match records per category id
    * no buffering needed for now, there is only one path in the application
    * where this data is needed.
    */
   public function getMatchRecordsByCategoryId(int $categoryId): MatchRecordCollection
   {
      /* first fetch all reletated tournament-specific data */
      $category = $this->tournament_repo->getCategoryById($categoryId);
      $participants = $this->participant_repo->getParticipantsByCategoryId($category->id);
      $areas = $this->tournament_repo->getAreasByTournamentId($category->tournament_id);

      /* second fetch all relevant match points in one single big fetch for this category */
      $stmt = $this->pdo->prepare(<<<QUERY
         SELECT mp.*
         FROM match_points mp LEFT JOIN matches m ON mp.match_id = m.id
         WHERE m.category_id = ?
         ORDER BY m.id ASC, mp.id ASC
      QUERY);
      $stmt->execute([$category->id]);
      $points = [];
      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
      {
         $points[$row['match_id']] ??= MatchPointCollection::new();
         $points[$row['match_id']][] = new \Tournament\Model\MatchRecord\MatchPoint(
            id: (int)$row['id'],
            participant: $participants[$row['participant_id']],
            point: $row['point'],
            given_at: new \DateTime($row['given_at']),
            caused_by: $row['caused_by'] ? $points[$row['caused_by']] ?? null : null
         );
      }

      /* now fetch all match records for this category and assemble their objects */
      $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE category_id = ? ORDER BY id ASC');
      $stmt->execute([$category->id]);
      $result = MatchRecordCollection::new();
      while ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
      {
         $record = new MatchRecord(
            id: $row['id'],
            name: $row['name'],
            category: $category,
            area: $areas[$row['area_id']],
            whiteParticipant: $participants[$row['white_id']],
            redParticipant: $participants[$row['red_id']],
            winner: isset($row['winner']) ? MatchSide::from($row['winner']) : null,
            tie_break: (bool)$row['tie_break'],
            created_at: new \DateTime($row['created_at']),
            finalized_at: isset($row['finalized_at']) ? new \DateTime($row['finalized_at']) : null,
            points: $points[$row['id']] ?? MatchPointCollection::new(),
         );
         $result[] = $record;
      }
      return $result;
   }

   /**
    * sync a match record to the database
    */
   public function saveMatchRecord(MatchRecord $record): void
   {
      if( isset($record->id) )
      {
         $stmt = $this->pdo->prepare('
            UPDATE matches SET
               winner = :winner,
               tie_break = :tie_break,
               finalized_at = :finalized_at
            WHERE id = :id
         ');
         $stmt->execute([
            'id'          => $record->id,
            'winner'      => $record->winner->value,
            'tie_break'   => $record->tie_break? 1 : 0,
            'finalized_at'=> isset($record->finalized_at)? $record->finalized_at->format('Y-m-d H:i:s') : null,
         ]);
      }
      else
      {
         $stmt = $this->pdo->prepare('
            INSERT INTO matches
               (name, category_id, area_id, red_id, white_id, winner, tie_break, created_at, finalized_at)
            VALUES
               (:name, :category_id, :area_id, :red_id, :white_id, :winner, :tie_break, :created_at, :finalized_at)
         ');

         $stmt->execute([
            'name'        => $record->name,
            'category_id' => $record->category->id,
            'area_id'     => $record->area->id,
            'red_id'      => $record->redParticipant->id,
            'white_id'    => $record->whiteParticipant->id,
            'winner'      => $record->winner->value,
            'tie_break'   => $record->tie_break? 1 : 0,
            'created_at'  => $record->created_at->format('Y-m-d H:i:s'),
            'finalized_at'=> isset($record->finalized_at)? $record->finalized_at->format('Y-m-d H:i:s') : null,
         ]);

         $record->id = (int)$this->pdo->lastInsertId();
      }

      /* update the associated points */
      $new_points = $record->points->filter(fn($p) => !isset($p->id));
      $removed_points = $record->points->getDropped();

      if ($new_points->count())
      {
         $stmt = $this->pdo->prepare(<<<QUERY
            INSERT INTO match_points
               (match_id, participant_id, point, given_at, caused_by)
            VALUES
               (:match_id, :participant_id, :point, :given_at, :caused_by)
         QUERY);

         foreach ($new_points as $point)
         {
            $stmt->execute([
               'match_id'       => $record->id,
               'participant_id' => $point->participant->id,
               'point'          => $point->point,
               'given_at'       => $point->given_at->format('Y-m-d H:i:s'),
               'caused_by'      => $point->caused_by?->id,
            ]);
            $point->id = (int)$this->pdo->lastInsertId();
         }
      }

      if ($removed_points->count())
      {
         $ids = $removed_points->column('id');
         $in  = '?'.str_repeat(',?', count($ids) - 1);
         $stmt = $this->pdo->prepare("DELETE FROM match_points WHERE id IN ($in)");
         $stmt->execute($ids);
      }
   }

   /**
    * delete all match records of a category
    */
   public function deleteMatchRecordsByCategoryId(int $categoryId): void
   {
      $stmt = $this->pdo->prepare('DELETE FROM matches WHERE category_id = ?');
      $stmt->execute([$categoryId]);
   }

   /**
    * delete a specific match record
    */
   public function deleteMatchRecordById(int $matchId): void
   {
      $stmt = $this->pdo->prepare('DELETE FROM matches WHERE id = :matchId');
      $stmt->execute(['matchId' => $matchId]);
   }

   /**
    * delete all match records where a specific participant is involved
    */
   public function deleteMatchRecordsByParticipantId(int $id): void
   {
      $stmt = $this->pdo->prepare('DELETE FROM matches WHERE red_id = ? OR white_id = ?');
      $stmt->execute([$id, $id]);
   }

   /**
    * check if there are any points stored for a specific participant
    */
   public function hasParticipantPoints(int $id): bool
   {
      $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM match_points WHERE participant_id = :id');
      $stmt->execute(['id' => $id]);
      return (int)$stmt->fetchColumn() > 0;
   }
}
