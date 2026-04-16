<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;

final class MatchesDependencyFix extends AbstractMigration
{
   public function up()
   {
      $this->table('matches')
         ->dropForeignKey('white_id')
         ->dropForeignKey('red_id')
         ->dropForeignKey('winner_id')
         ->removeIndex('white_id')
         ->removeIndex('red_id')
         ->removeIndex('winner_id')
         ->update();

      $winner_values = array_map(fn($e) => $e->value, MatchSide::cases());

      $this->table('matches')
         ->addColumn('winner', 'enum', ['null' => true, 'values' => $winner_values, 'default' => null, 'after' => 'red_id'])
         ->addForeignKey(['red_id', 'category_id'], 'participants_categories', ['participant_id', 'category_id'],
                         ['delete' => 'restrict', 'constraint' => 'fk_matches_red_participant'])
         ->addForeignKey(['white_id', 'category_id'], 'participants_categories', ['participant_id', 'category_id'],
                         ['delete' => 'restrict', 'constraint' => 'fk_matches_white_participant'])
         ->update();

      $this->execute(<<<QUERY
         UPDATE matches SET winner=IF( ISNULL(winner_id), NULL, IF( winner_id=red_id, 'red', 'white'));
      QUERY);

      $this->table('matches')
         ->removeColumn('winner_id')
         ->update();
   }

   public function down()
   {
      $this->table('matches')
         ->dropForeignKey(['red_id', 'category_id'])
         ->dropForeignKey(['white_id', 'category_id', ])
         ->removeIndex(['red_id', 'category_id'])
         ->removeIndex(['white_id', 'category_id',])
         ->update();

      $this->table('matches')
         ->addColumn('winner_id', 'integer', ['null' => true, 'signed' => false, 'default' => null, 'after' => 'red_id'])
         ->addForeignKey('white_id', 'participants', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'matches_white_participant_fk'])
         ->addForeignKey('red_id', 'participants', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'matches_red_participant_fk'])
         ->addForeignKey('winner_id', 'participants', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'matches_winner_fk'])
         ->update();

      $this->execute(<<<QUERY
         UPDATE matches SET winner_id=IF( ISNULL(winner), NULL, IF( winner="red", red_id, white_id ));
      QUERY);

      $this->table('matches')
         ->removeColumn('winner')
         ->update();
   }
}