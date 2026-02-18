<?php

namespace Tournament\Repository;

use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;

use PDO;

class MatchDataRepository
{
   private $buffer_by_id = [];
   private $buffer_by_name = [];

   public function __construct(
      private PDO $pdo,
      private ParticipantRepository $participant_repo,
      private TournamentRepository $tournament_repo
      )
   {
   }

   private function createMatchRecordInstance(array $data): MatchRecord
   {
      if( !isset($this->buffer_by_id[$data['id']]) )
      {
         /* create match record */
         $record = new MatchRecord(
            id:       $data['id'],
            name:     $data['name'],
            category: $this->tournament_repo->getCategoryById($data['category_id']),
            area:     $this->tournament_repo->getAreaById($data['area_id']),
            whiteParticipant: $this->participant_repo->getParticipantById($data['white_id']),
            redParticipant:   $this->participant_repo->getParticipantById($data['red_id']),
            winner:           isset($data['winner_id'])? $this->participant_repo->getParticipantById($data['winner_id']) : null,
            tie_break:    $data['tie_break'],
            created_at:   new \DateTime($data['created_at']),
            finalized_at: isset($data['finalized_at'])? new \DateTime($data['finalized_at']) : null,
         );

         /* load points for this match */
         $stmt = $this->pdo->prepare('SELECT * FROM match_points WHERE match_id = :match ORDER BY id ASC');
         $stmt->execute(['match' => $record->id]);
         foreach( $stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
         {
            $point = new \Tournament\Model\MatchRecord\MatchPoint(
               id: $row['id'],
               participant: $this->participant_repo->getParticipantById($row['participant_id']),
               point: $row['point'],
               given_at: new \DateTime($row['given_at']),
               caused_by: $row['caused_by']? $record->points[$row['caused_by']]??null : null
            );
            $record->points[$point->id] = $point;
         }

         /* update buffers */
         $this->buffer_by_id[$record->id] = $record;
         $this->buffer_by_name[$record->category->id] ??= [];
         $this->buffer_by_name[$record->category->id][$record->name] = $record;
      }

      return $this->buffer_by_id[$data['id']];
   }

   public function getMatchRecordsByCategoryId(int $categoryId): MatchRecordCollection
   {
      $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE category_id = :category ORDER BY id ASC');
      $stmt->execute(['category' => $categoryId]);
      foreach( $stmt->fetchAll(PDO::FETCH_ASSOC) as $row )
      {
         $this->createMatchRecordInstance($row);
      }
      return new MatchRecordCollection($this->buffer_by_name[$categoryId] ?? []);
   }

   public function getMatchRecordById(int $id): ?MatchRecord
   {
      if( isset($this->buffer_by_id[$id]) )
      {
         return $this->buffer_by_id[$id];
      }

      $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE id = :id');
      $stmt->execute(['id' => $id]);
      $data = $stmt->fetch(PDO::FETCH_ASSOC);
      return $data? $this->createMatchRecordInstance($data) : null;
   }

   public function getMatchRecordByName(int $categoryId, string $name): ?MatchRecord
   {
      if( isset($this->buffer_by_name[$categoryId][$name]) )
      {
         return $this->buffer_by_name[$categoryId][$name];
      }

      $stmt = $this->pdo->prepare('SELECT * FROM matches WHERE name = :name AND category_id = :category');
      $stmt->execute(['name' => $name, 'category' => $categoryId]);
      $data = $stmt->fetch(PDO::FETCH_ASSOC);
      return $data? $this->createMatchRecordInstance($data) : null;
   }

   public function getMatchRecordsByNameList(int $categoryId, iterable $names): MatchRecordCollection
   {
      /* for now, just fetch the whole category and filter afterwards
       * (optimization can be done later, if needed)
       * definitely avoid fetching one-by-one in a loop
       */
      $records = $this->getMatchRecordsByCategoryId($categoryId);
      $result = new MatchRecordCollection();
      foreach( $names as $name )
      {
         if( isset($records[$name]) )
         {
            $result[] = $records[$name];
         }
      }
      return $result;
   }

   public function saveMatchRecord(MatchRecord $record): bool
   {
      $result = true;

      if( isset($record->id) )
      {
         $stmt = $this->pdo->prepare('
            UPDATE matches SET
               winner_id = :winner_id,
               tie_break = :tie_break,
               finalized_at = :finalized_at
            WHERE id = :id
         ');
         $result = $stmt->execute([
            'id'          => $record->id,
            'winner_id'   => isset($record->winner)? $record->winner->id : null,
            'tie_break'   => $record->tie_break? 1 : 0,
            'finalized_at'=> isset($record->finalized_at)? $record->finalized_at->format('Y-m-d H:i:s') : null,
         ]);
      }
      else
      {
         $stmt = $this->pdo->prepare('
            INSERT INTO matches
               (name, category_id, area_id, red_id, white_id, winner_id, tie_break, created_at, finalized_at)
            VALUES
               (:name, :category_id, :area_id, :red_id, :white_id, :winner_id, :tie_break, :created_at, :finalized_at)
         ');

         $result = $stmt->execute([
            'name'        => $record->name,
            'category_id' => $record->category->id,
            'area_id'     => $record->area->id,
            'red_id'      => $record->redParticipant->id,
            'white_id'    => $record->whiteParticipant->id,
            'winner_id'   => isset($record->winner)? $record->winner->id : null,
            'tie_break'   => $record->tie_break? 1 : 0,
            'created_at'  => $record->created_at->format('Y-m-d H:i:s'),
            'finalized_at'=> isset($record->finalized_at)? $record->finalized_at->format('Y-m-d H:i:s') : null,
         ]);

         if( $result )
         {
            $record->id = (int)$this->pdo->lastInsertId();

            /* update buffers */
            $this->buffer_by_id[$record->id] = $record;
            $this->buffer_by_name[$record->category->id] ??= [];
            $this->buffer_by_name[$record->category->id][$record->name] = $record;
         }
      }

      /* update the associated points */
      if( $result )
      {
         $new_points = $record->points->filter(fn($p) => !isset($p->id));
         $removed_points = $record->points->getDropped();

         if ($new_points->count())
         {
            $stmt = $this->pdo->prepare('
               INSERT INTO match_points
                  (match_id, participant_id, point, given_at, caused_by)
               VALUES
                  (:match_id, :participant_id, :point, :given_at, :caused_by)
            ');

            foreach ($new_points as $point)
            {
               $res = $stmt->execute([
                  'match_id'       => $record->id,
                  'participant_id' => $point->participant->id,
                  'point'          => $point->point,
                  'given_at'       => $point->given_at->format('Y-m-d H:i:s'),
                  'caused_by'      => $point->caused_by?->id,
               ]);
               $result = $result && $res;
               if( $res )
               {
                  $point->id = (int)$this->pdo->lastInsertId();
               }
            }
         }

         if ($removed_points->count())
         {
            $ids = $removed_points->column('id');
            $in  = '?'.str_repeat(',?', count($ids) - 1);
            $stmt = $this->pdo->prepare("DELETE FROM match_points WHERE id IN ($in)");
            $res = $stmt->execute($ids);
            $result = $result && $res;
         }
      }

      return $result;
   }

   public function deleteMatchRecordsByCategoryId(int $categoryId): bool
   {
      $stmt = $this->pdo->prepare('DELETE FROM matches WHERE category_id = :category');
      return $stmt->execute(['category' => $categoryId]);
   }

   public function deleteMatchRecordById(int $matchId): bool
   {
      $stmt = $this->pdo->prepare('DELETE FROM matches WHERE id = :matchId');
      return $stmt->execute(['matchId' => $matchId]);
   }
}
