<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

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
         ->addIndex(['id', 'category_id'],   ['name' => 'idx_team_id']) // so it can be referenced as FK
         ->addIndex(['category_id', 'name'], ['unique' => true, 'name' => 'unique_team_name']) // unique team names needed
         ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_teams_category_id'])
         ->create();

      $this->table('participants_teams', ['id' => false, 'primary_key' => ['participant_id', 'category_id']])
         // participant-assignment to teams is per category, so add it here as well
         ->addColumn('participant_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('team_id', 'integer', ['null' => false, 'signed' => false])
         ->addForeignKey(['team_id', 'category_id'], 'teams', ['id', 'category_id'],
                         ['delete' => 'CASCADE', 'constraint' => 'fk_participants_teams_team_id'])
         ->addForeignKey(['participant_id', 'category_id'], 'participants_categories', ['participant_id', 'category_id'],
                         ['delete' => 'CASCADE', 'constraint' => 'fk_participants_teams_participant_id'])
         ->create();

      $this->table('team_matches')
         ->addColumn('name', 'string', ['limit' => 127, 'null' => false])
         ->addColumn('category_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('red_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('white_id', 'integer', ['null' => false, 'signed' => false])
         ->addColumn('winner_id', 'integer', ['null' => true, 'signed' => false, 'default' => null])
         ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
         ->addColumn('finalized_at', 'datetime', ['null' => true, 'default' => null])
         ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_category'])
         ->addForeignKey('white_id', 'teams', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_white_participant'])
         ->addForeignKey('red_id', 'teams', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_matches_red_participant'])
         ->addForeignKey('winner_id', 'teams', 'id', ['delete' => 'RESTRICT', 'constraint' => 'fk_team_matches_winner'])
         ->addIndex(['category_id', 'name'], ['unique' => true, 'name' => 'unique_team_match_name'])
         ->create();

      $this->table('matches')
         ->addColumn('team_match_id', 'integer', ['null' => true, 'signed' => false, 'after' => 'category_id'])
         ->addForeignKey('team_match_id', 'team_matches', 'id', ['delete' => 'CASCADE', 'constraint' => 'fk_team_match_matches_team_match_id'])
         ->update();
   }
}