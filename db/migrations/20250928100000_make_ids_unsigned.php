<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MakeIdsUnsigned extends AbstractMigration
{
   public function up(): void
   {
      $tables = $this->query(<<<'ID_TABLES_QUERY'
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = (select database())
  AND COLUMN_NAME = 'id'
  AND COLUMN_TYPE = 'int(11)';
ID_TABLES_QUERY)->fetchAll(PDO::FETCH_COLUMN, 0);

      $constraints = $this->query(<<<'FOREIGN_KEY_QUERY'
SELECT
   rc.CONSTRAINT_NAME AS name,
   rc.TABLE_NAME AS child_table,
   kcu.COLUMN_NAME AS ref_col,
   cols.IS_NULLABLE AS nullable,
   rc.REFERENCED_TABLE_NAME AS tgt_table,
   rc.UPDATE_RULE AS update_rule,
   rc.DELETE_RULE AS delete_rule
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
LEFT JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
   ON  rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
LEFT JOIN INFORMATION_SCHEMA.COLUMNS cols
   ON  rc.CONSTRAINT_SCHEMA = cols.TABLE_SCHEMA
   AND rc.TABLE_NAME = cols.TABLE_NAME
   AND kcu.COLUMN_NAME = cols.COLUMN_NAME
WHERE  rc.CONSTRAINT_SCHEMA = (select database())
   AND kcu.REFERENCED_COLUMN_NAME='id'
   AND cols.COLUMN_TYPE="int(11)";
FOREIGN_KEY_QUERY)->fetchAll(PDO::FETCH_ASSOC);

      /* drop all foreign keys */
      foreach( $constraints as $constraint )
      {
         $this->table($constraint['child_table'])->dropForeignKey($constraint['ref_col'], $constraint['name'])->save();
      }

      /* update all id types */
      foreach( $tables as $table )
      {
         $this->table($table)->changeColumn('id', 'integer', ['signed' => false, 'identity' => true ])->save();
      }

      /* update all foreign key types and add the foreign key constraint again */
      foreach( $constraints as $constraint )
      {
         $this->table($constraint['child_table'])
            ->changeColumn($constraint['ref_col'], 'integer', ['signed' => false, 'null' => ($constraint['nullable']==='YES')])
            ->addForeignKeyWithName($constraint['name'], $constraint['ref_col'], $constraint['tgt_table'], 'id',
                                    ['delete' => $constraint['delete_rule'], 'update' => $constraint['update_rule'] ])
            ->save();
      }
   }

   public function down(): void
   {
      /* do nothing, keeping the unsigned ID columns is perfectly fine for all use cases */
   }
}
