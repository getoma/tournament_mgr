<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitialSchema extends AbstractMigration
{
   public function up(): void
   {
      /* catch "initial" migration on already existing database content,
       * by checking whether a specific table already exists
       */
      if( !$this->hasTable('users') )
      {
         $this->execute(file_get_contents(__DIR__ . '/initial_schema.sql'));
      }
   }

   public function down(): void
   {
      $this->execute('DROP TABLE IF EXISTS areas, categories, participants, rounds, matches, tournaments, users, user_tournaments;');
   }
}
