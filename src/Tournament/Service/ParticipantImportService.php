<?php

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\CategoryAssignment;
use Tournament\Model\Participant\CategoryAssignmentCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;

use Tournament\Repository\ParticipantRepository;

use Base\Service\SpreadsheetImportService;

class ParticipantImportService
{
   /** list of known columns, and how they might be named in the spreadsheet */
   public const EXPECTED_COLUMN_NAMES = [
      'firstname' => ['Vorname', 'first name', 'firstname'],
      'lastname'  => ['Nachname', 'Name', 'last name', 'lastname'],
      'club'      => ['Verein', 'Verband', 'Landesverband', 'Club', 'Association'],
      'category'  => ['Kategorie', 'Category', 'Teilnahme'],
   ];

   /** list of columns we need at minimum to import */
   public const NEEDED_COLUMN_NAMES = ['firstname', 'lastname'];

   public function __construct(
      private ParticipantRepository $repo,
      private SpreadsheetImportService $xlsImportService,
   )
   {}

   /**
    * parse a spreadsheet and return it as an import data structure
    */
   public function parseSpreadsheet(string $file_path): array
   {
      return $this->xlsImportService->parse($file_path, static::EXPECTED_COLUMN_NAMES);
   }

   /**
    * identify all rows that we would import as participants according
    * the current $import_data structure
    */
   public function findParticipantRows(array $import_data): array
   {
      /* check if all needed columns identified, early abort if not */
      if (array_diff_key(array_flip(static::NEEDED_COLUMN_NAMES), $import_data['headers'])) return [];

      /* go through all content and check if all needed columns are filled with data */
      $result = [];
      $rowCount = count($import_data['rows']);
      for ($row = $import_data['content_row']; $row < $rowCount; ++$row)
      {
         // check for each needed column whether it has content in this row
         foreach (static::NEEDED_COLUMN_NAMES as $colname)
         {
            $col = $import_data['headers'][$colname]['column'];
            if (empty($import_data['rows'][$row][$col])) continue 2; // no content, go to next row
         }
         $result[] = $row;
      }
      return $result;
   }

   /**
    * parse $import_data structure into a list of participants.
    * returns the list of participants that were found in this report, as well as a second list that only
    * lists the participants that were already existing.
    * @return array - the import report: [ 'participants' => ParticipantCollection, 'duplicates' => ParticipantCollection ]
    */
   public function import(array $import_data, int $tournamentId, ?Category $category, array $category_column_mapping, ?string $club = null): array
   {
      $participants = ParticipantCollection::new();

      // short-cut the column names
      $lastname_col = $import_data['headers']['lastname']['column'];
      $firstname_col = $import_data['headers']['firstname']['column'];
      $club_col = $import_data['headers']['club']['column'] ?? null;

      // extract the actual relevant rows, and parse each one
      $row_numbers = $this->findParticipantRows($import_data);
      foreach( array_intersect_key($import_data['rows'], array_flip($row_numbers)) as $row )
      {
         // create the participant from the corresponding column
         $p = new Participant(null, $tournamentId, $row[$lastname_col], $row[$firstname_col], $club ?: $row[$club_col] ?? null);

         // and attach the categories:
         if( $category ) // global category set, use this one and ignore any columns
         {
            $p->categories[] = $category;
         }
         else // check the columns for any category mappings
         {
            foreach( $category_column_mapping as $col => $column_category )
            {
               if( !$row[$col] ) continue; // if empty, then no mapping set here
               if($column_category instanceof CategoryCollection )
               {
                  // this column selects any category from this collection, find it
                  $column_category = $column_category->find(fn($c) => strtolower($c->name) === strtolower($row[$col]));
               }
               if( isset($column_category) )
               {
                  // this column has content and is mapped to a single category, set it.
                  $p->categories[] = new CategoryAssignment($column_category);
               }
            }
         }
         $participants[] = $p;
      }
      $duplicates = $this->findDuplicates($participants, $tournamentId);
      return [ 'participants' => $participants, 'duplicates' => $duplicates ];
   }

   /**
    * parse a list of participants from a multiline string and turn it into a ParticipantCollection
    * returns the list of participants that were found in this string, as well as a second list that only
    * lists the participants that were already existing.
    * @return array - the import report: [ 'participants' => ParticipantCollection, 'duplicates' => ParticipantCollection ]
    */
   public function importText(string $text, int $tournamentId, CategoryCollection $categories, ?string $club = null): array
   {
      /* step 1: parse the input text into a list of participants */
      $report = $this->parseText($text, $tournamentId, $club);
      if (isset($report['errors'])) return $report;
      /* step 2: attach categories to participants */
      $assignments = $categories->map(fn($c) => new CategoryAssignment($c));
      $report['participants']->walk(fn($p) => $p->categories = new CategoryAssignmentCollection($assignments));
      /* step 3: duplicate detection: search for already existing participants with same name and replace/update them */
      $report['duplicates'] = $this->findDuplicates($report['participants'], $tournamentId);
      /* done */
      return $report;
   }

   /**
    * parse the file content into an array - one participant per line, either as:
    * "firstname lastname", or
    * "lastname, firstname"
    */
   private function parseText(string $text, int $tournamentId, ?string $club = null): array
   {
      $participants = ParticipantCollection::new();
      $unparsed = [];
      foreach (explode("\n", $text) as $line)
      {
         $line = trim($line);
         if (empty($line)) continue; // skip empty lines

         // Split by comma if present, otherwise treat as "firstname lastname"
         if (strpos($line, ',') !== false)
         {
            list($lastname, $firstname) = explode(',', $line, 2);
         }
         elseif (strpos($line, ' ') !== false)
         {
            list($firstname, $lastname) = explode(' ', $line, 2);
         }
         else
         {
            $unparsed[] = $line;
            continue; // skip invalid lines
         }
         $participants[] = new Participant(null, $tournamentId, trim($lastname), trim($firstname), $club);
      }
      if( empty($unparsed) ) $unparsed = null;
      return [ 'participants' => $participants, 'errors' => $unparsed ];
   }

   /**
    * parse the given collection and check for any entry that already exists in our data base.
    * if any found, replace the corresponding entry in $new. Additionally, return a list of all duplicates, only
    * @return the list of all already existing participants
    */
   private function findDuplicates(ParticipantCollection $new, int $tournamentId): ParticipantCollection
   {
      $existing = $this->repo->getParticipantsByTournamentId($tournamentId);
      $mapper = fn($p) => join("_", $p->asArray(['lastname', 'firstname', 'club']));
      $current_participants = $existing->map_keys($mapper);
      $duplicates = ParticipantCollection::new();
      foreach ($new as $new_p)
      {
         /** @var Participant $current */
         $current = $current_participants[$mapper($new_p)]??null;
         if (isset($current))
         {
            // add the new category assignments to the existing participant
            $current->categories->mergeInPlace($new_p->categories, false);
            // replace the new participant instance with the found one
            $new->drop($new_p);
            $new[] = $current;
            // report it as duplicate
            $duplicates[] = $current;
         }
      }
      return $duplicates;
   }
}