<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PoolnameMigration extends AbstractMigration
{
   public function up(): void
   {
      $this->execute(<<<'QUERY'
UPDATE participants_categories
SET slot_name = CONCAT("tmp-", slot_name)
WHERE slot_name REGEXP '^[0-9]+\\.[0-9]+$';

UPDATE participants_categories
SET slot_name = CONCAT(
        SUBSTRING_INDEX(SUBSTRING(slot_name,5), '.', 1) + 1,
        '.',
        SUBSTRING_INDEX(SUBSTRING(slot_name,5), '.', -1)
    )
WHERE slot_name LIKE 'tmp-%';

UPDATE matches
SET name = CONCAT("tmp-", name)
WHERE name REGEXP '^[0-9]+\\.[0-9]+$';

UPDATE matches
SET name = CONCAT(
        SUBSTRING_INDEX(SUBSTRING(name,5), '.', 1) + 1,
        '.',
        SUBSTRING_INDEX(SUBSTRING(name,5), '.', -1) + 1
    )
WHERE name LIKE 'tmp-%';
QUERY);
   }

   public function down(): void
   {
      $this->execute(<<<'QUERY'
UPDATE participants_categories
SET slot_name = CONCAT("tmp-", slot_name)
WHERE slot_name REGEXP '^[0-9]+\\.[0-9]+$';

UPDATE participants_categories
SET slot_name = CONCAT(
        SUBSTRING_INDEX(SUBSTRING(slot_name,5), '.', 1) - 1,
        '.',
        SUBSTRING_INDEX(SUBSTRING(slot_name,5), '.', -1)
    )
WHERE slot_name LIKE 'tmp-%';

UPDATE matches
SET name = CONCAT("tmp-", name)
WHERE name REGEXP '^[0-9]+\\.[0-9]+$';

UPDATE matches
SET name = CONCAT(
        SUBSTRING_INDEX(SUBSTRING(name,5), '.', 1) - 1,
        '.',
        SUBSTRING_INDEX(SUBSTRING(name,5), '.', -1) - 1
    )
WHERE name LIKE 'tmp-%';
QUERY);

   }
}