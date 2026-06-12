<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;

final class AddTeams extends AbstractMigration
{
   public function change()
   {
      $this->table('categories')
         ->addColumn('team_mode', 'boolean', ['null' => false, 'default' => false, 'after' => 'mode'])
         ->update();

      $this->table('teams')
         ->addColumn('name', 'string', ['null' => false, 'length' => 32])
         ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('withdrawn', 'boolean', ['null' => false, 'default' => false])
         ->addColumn('slot_name', 'string', ['length' => 127, 'null' => true])
         ->addColumn('pre_assign', 'string', ['length' => 127, 'null' => true])
         ->addIndex(['category_id', 'name'], ['unique' => true, 'name' => 'UC_TEAM_NAME']) // unique team names needed
         ->addIndex(['category_id', 'slot_name'], ['unique' => true, 'name' => 'UC_TEAM_SLOT_NAME']) // unique start slots per team
         ->addIndex(['category_id', 'pre_assign'], ['unique' => true, 'name' => 'UC_TEAM_PRE_ASSIGN']) // unique pre-assigned slots per team
         ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_teams_category_id'])
         ->create();

      $this->table('participants_teams', ['id' => false, 'primary_key' => ['participant_id', 'category_id']])
         // participant-assignment to teams is per category, so add it here as well
         ->addColumn('participant_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('team_id', 'integer', ['null' => false, 'signed' => false])
         ->addForeignKey('team_id', 'teams', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_participants_teams_team_id'])
         ->addForeignKey(['participant_id', 'category_id'], 'participants_categories', ['participant_id', 'category_id'],
                         ['delete' => 'CASCADE', 'constraint' => 'fk_participants_teams_participant_id'])
         ->create();

      $winner_values = array_map(fn($e) => $e->value, MatchSide::cases());

      $this->table('team_matches')
         ->addColumn('name', 'string', ['limit' => 127, 'null' => false])
         ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('red_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('white_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('winner', 'enum', ['null' => true, 'values' => $winner_values, 'default' => null])
         ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('finalized_at', 'datetime', ['null' => true, 'default' => null])
         ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_category'])
         ->addForeignKey('white_id', 'teams', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_white_participant'])
         ->addForeignKey('red_id', 'teams', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_red_participant'])
         ->addIndex(['category_id', 'name'], ['unique' => true, 'name' => 'unique_team_match_name'])
         ->create();

      $this->table('matches')
         ->addColumn('team_match_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'category_id'])
         ->addForeignKey('team_match_id', 'team_matches', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_match_matches_team_match_id'])
         ->update();
   }
}