<?php

namespace Tournament\Repository;

use PDO;
use Tournament\Model\Data\MatchRecord;
use Tournament\Model\Data\MatchRecordCollection;

class MatchDataRepository
{
   private $buffer_by_id = [];
   private $buffer_by_name = [];

   public function __construct(
      private PDO $pdo,
      private ParticipantRepository $participant_repo,
      private AreaRepository $area_repo,
      private CategoryRepository $category_repository)
   {
   }

   private function createMatchRecordInstance(array $data): MatchRecord
   {
      if( !isset($this->buffer_by_id[$data['id']]) )
      {
         $record = new MatchRecord(
            id:       $data['id'],
            name:     $data['name'],
            category: $this->category_repository->getCategoryById($data['category_id']),
            area:     $this->area_repo->getAreaById($data['area_id']),
            whiteParticipant: $this->participant_repo->getParticipantById($data['white_id']),
            redParticipant:   $this->participant_repo->getParticipantById($data['red_id']),
            winner:           isset($data['winner_id'])? $this->participant_repo->getParticipantById($data['winner_id']) : null,
            tie_break:    $data['tie_break'],
            created_at:   new \DateTime($data['created_at']),
            finalized_at: isset($data['finalized_at'])? new \DateTime($data['finalized_at']) : null,
         );

         $this->buffer_by_id[$record->id] = $record;
         $this->buffer_by_name[$record->category->id] ??= [];
         $this->buffer_by_name[$record->category->id][$record->name] = $record;
      }

      return $this->buffer_by_id[$record->id];
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
}
