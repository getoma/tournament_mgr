<?php declare(strict_types=1);

namespace Base\Repository;

use Base\Model\ChangeLogCollection;
use Base\Model\ChangeLogEntry;

class ChangeLogRepository
{
   public function __construct(
      private \PDO $pdo,
   )
   {
   }

   /**
    * add new change logs to the database
    */
   public function storeChangeLog(ChangeLogCollection $logs)
   {
      $stmt = $this->pdo->prepare(<<<QUERY
         INSERT INTO change_log (entity_type, entity_id, group_id, change_type, changed_at, changed_by, details)
         VALUES (:entity_type, :entity_id, :group_id, :change_type, :changed_at, :user_id, :details)
      QUERY);

      foreach( $logs as $entry )
      {
         /** @var ChangeLogEntry $entry */
         $stmt->execute([
            ':entity_type' => $entry->entity_type,
            ':entity_id' => $entry->entity_id,
            ':group_id' => $entry->group_id,
            ':change_type' => $entry->change_type,
            ':changed_at' => $entry->changed_at->format('Y-m-d H:i:s'),
            ':user_id' => $entry->user_id,
            ':details' => json_encode($entry->details),
         ]);
      }
   }

   /**
    * retrieve all change logs for a specific entity
    */
   public function getChangeLogsById(string $entity_type, int $entity_id, ?string $change_type = null): ChangeLogCollection
   {
      $query = 'SELECT * FROM change_log WHERE entity_type = :entity_type AND entity_id = :entity_id';
      $params = [':entity_type' => $entity_type, ':entity_id' => $entity_id];
      if( $change_type !== null )
      {
         $query .= ' AND change_type = :change_type';
         $params[':change_type'] = $change_type;
      }
      $query .= ' ORDER BY changed_at ASC, id ASC';
      $stmt = $this->pdo->prepare($query);
      $stmt->execute($params);
      $result = ChangeLogCollection::new();
      while( $row = $stmt->fetch(\PDO::FETCH_ASSOC) )
      {
         $result[] = new ChangeLogEntry(
            id: (int)$row['id'],
            entity_type: $row['entity_type'],
            entity_id: (int)$row['entity_id'],
            group_id: (int)$row['group_id'],
            change_type: $row['change_type'],
            changed_at: new \DateTime($row['changed_at']),
            user_id: isset($row['changed_by']) ? (int)$row['changed_by'] : null,
            details: json_decode($row['details'], true) ?? [],
         );
      }
      return $result;
   }

   /**
    * retrieve all change logs for a specific group, optionally filter by entity type and/or change type
    */
   public function getChangeLogsByGroupId(int $group_id, ?string $entity_type = null, ?string $change_type = null): ChangeLogCollection
   {
      $query = 'SELECT * FROM change_log WHERE group_id = :group_id';
      $params = [':group_id' => $group_id];
      if( $entity_type !== null )
      {
         $query .= ' AND entity_type = :entity_type';
         $params[':entity_type'] = $entity_type;
      }
      if( $change_type !== null )
      {
         $query .= ' AND change_type = :change_type';
         $params[':change_type'] = $change_type;
      }
      $query .= ' ORDER BY changed_at ASC, id ASC';
      $stmt = $this->pdo->prepare($query);
      $stmt->execute($params);
      $result = ChangeLogCollection::new();
      while( $row = $stmt->fetch(\PDO::FETCH_ASSOC) )
      {
         $result[] = new ChangeLogEntry(
            id: (int)$row['id'],
            entity_type: $row['entity_type'],
            entity_id: (int)$row['entity_id'],
            group_id: (int)$row['group_id'],
            change_type: $row['change_type'],
            changed_at: new \DateTime($row['changed_at']),
            user_id: isset($row['changed_by']) ? (int)$row['changed_by'] : null,
            details: json_decode($row['details'], true) ?? [],
         );
      }
      return $result;
   }

   /**
    * delete all change logs for a specific group, optionally filter by entity type and/or change type
    */
   public function deleteChangeLogsByGroupId(int $group_id, ?string $entity_type = null, ?string $change_type = null): void
   {
      $query = 'DELETE FROM change_log WHERE group_id = :group_id';
      $params = [':group_id' => $group_id];
      if( $entity_type !== null )
      {
         $query .= ' AND entity_type = :entity_type';
         $params[':entity_type'] = $entity_type;
      }
      if( $change_type !== null )
      {
         $query .= ' AND change_type = :change_type';
         $params[':change_type'] = $change_type;
      }
      $this->pdo->prepare($query)->execute($params);
   }
}