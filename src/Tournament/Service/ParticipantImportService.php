<?php

namespace Tournament\Service;

use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Repository\ParticipantRepository;

class ParticipantImportService
{
   public function __construct(private ParticipantRepository $repo)
   {
   }

   public function import(string $text, int $tournamentId, CategoryCollection $categories, ?string $club = null): array
   {
      /* step 1: parse the input text into a list of participants */
      $parse_report = $this->parse($text, $tournamentId, $club);
      if( isset($parse_report['errors']) ) return $parse_report;
      /* step 2: duplicate detection: search for already existing participants with same name */
      $report = $this->split_duplicates($parse_report['participants'], $this->repo->getParticipantsByTournamentId($tournamentId));
      $report['participants'] = $parse_report['participants'];
      /* step 3: attach categories to new participants */
      $report['new']->walk(fn($p) => $p->categories = $categories);
      /* done */
      return $report;
   }

   /**
    * parse the file content into an array - one participant per line, either as:
    * "firstname lastname", or
    * "lastname, firstname"
    */
   private function parse(string $text, int $tournamentId, ?string $club = null): array
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

   private function split_duplicates(ParticipantCollection $new, ParticipantCollection $existing): array
   {
      $mapper = fn($p) => join("_", $p->asArray(['lastname', 'firstname', 'club']));
      $current_participants = $existing->map_keys($mapper);
      $report = ['new' => ParticipantCollection::new(), 'duplicate' => ParticipantCollection::new()];
      foreach ($new as $new_p)
      {
         $current = $current_participants[$mapper($new_p)]??null;
         if (isset($current))
         {
            $report['duplicate'][] = $current;
         }
         else
         {
            $report['new'][] = $new_p;
         }
      }
      return $report;
   }
}