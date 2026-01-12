<?php

namespace Base\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SpreadsheetImportService
{
   /**
    * parse an Spreadsheet file (.xls/.xlsx/.ods)
    */
   public function parse(string $filePath, array $expectedColumns = [], bool $importHidden = false): array
   {
      $spreadsheet = IOFactory::load($filePath);
      $result = [];

      foreach ($spreadsheet->getWorksheetIterator() as $worksheet)
      {
         /* skip hidden sheets if requested */
         if ($worksheet->getSheetState() === Worksheet::SHEETSTATE_HIDDEN && !$importHidden) continue;

         /* read in the worksheet and put it into our output */
         $sheetData = $this->parseWorksheet($worksheet, $expectedColumns);

         if ($sheetData !== null)
         {
            $result[] = $sheetData;
         }
      }

      return $result;
   }

   /**
    * read in a single Worksheet from such a file
    */
   private function parseWorksheet(Worksheet $worksheet, array $expectedColumns): ?array
   {
      $rows = $worksheet->toArray(null, true, false, false);

      /* delete all empty rows from $rows (= all rows that contain only empty values) */
      $rows = array_filter($rows, fn($row) => !empty(array_filter($row)));

      /* stop here if no content */
      if (empty($rows)) return null;

      /* delete all empty columns from all rows (=all columns that are empty in every row) */
      $columnIndexes = range(0, max(array_map('count', $rows)) - 1);
      $emptyColumns = array_filter($columnIndexes, fn($colIndex) => empty(array_filter(array_column($rows, $colIndex))));
      $rows = array_map(fn($row) => array_diff_key($row, array_flip($emptyColumns)), $rows);

      /* re-index array keys */
      $rows = array_values($rows);

      /* "guess" the header columns */
      $headers = $this->determineHeaders($rows, $expectedColumns);

      /* determine the row where the content starts:
       * get the highest row number from column, then find the first non-empty row below*/
      if($headers )
      {
         $content_row_number = max(array_column($headers, 'row')) + 1;
         $row_count = count($rows);
         for( $row_nr = $content_row_number; $row_nr < $row_count; ++$row_nr )
         {
            $relevant_row_content = array_map(fn($col) => $rows[$row_nr][$col], array_column($headers, 'column'));
            if( empty( array_filter($relevant_row_content, fn($cell) => empty($cell))) )
            {
               /* we found the first row past the headers where we have an entry for each identified column.
               * use this one as first content row */
               break;
            }
         }
         if( $row_nr < $row_count ) $content_row_number = $row_nr;
      }

      return [
         'name'        => $worksheet->getTitle(),
         'headers'     => $headers,
         'content_row' => $content_row_number ?? null,
         'rows'        => $rows,
      ];
   }

   /**
    * try to identify the header rows and assign the columns according $expectedColumns
    * $expectedColumns is an array of the format
    * [ <internal_column_name> => [<possible_names...>], ... ]
    * This function shall parse the whole worksheet on whether any possible column name is
    * is used in any cell.
    * If a possible column name is found, the corresponding cell shall be marked as beeing of <internal_column_name>.
    * This function returns the row and col number where the matched column name was found for each identified column
    * the returned structure shall be of the format
    * [ <internal_column_name> => ['text'   => <actual found name>,
    *                              'column' => <col number>,
    *                              'row'    => <row number>         ],
    *   ...
    * ]
    */
   private function determineHeaders(array $rows, array $expectedColumns): array
   {
      if (empty($expectedColumns)) return [];

      $result = [];
      foreach ($expectedColumns as $internalColumnName => $possibleNames)
      {
         foreach ($rows as $rowNumber => $row)
         {
            foreach ($row as $cellNumber => $cellValue)
            {
               $trimmedCell = trim((string)$cellValue);
               $normalizedCell = strtolower($trimmedCell);
               foreach ($possibleNames as $possibleName)
               {
                  if ($normalizedCell === strtolower($possibleName))
                  {
                     $result[$internalColumnName] = [
                        'text'   => $trimmedCell,
                        'column' => $cellNumber,
                        'row'    => $rowNumber
                     ];
                     break 3;
                  }
               }
            }
         }
      }

      return $result;
   }

}
