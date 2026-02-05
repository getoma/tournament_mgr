<?php

namespace Base\Service;

/**
 * Service to manage a temporary directory to park files (e.g. for file uploads)
 */
class TmpStorageService
{
   private string $basePath;

   function __construct($appName)
   {
      $this->basePath = sys_get_temp_dir() . "/$appName";
      if (!is_dir($this->basePath) && !mkdir($this->basePath, 0700, true))
      {
         throw new \RuntimeException("Could not create import storage directory ({$this->basePath}): " . error_get_last()['message']);
      }
   }

   public function exists(string $id, string $fname)
   {
      return file_exists($this->getPath($id, $fname));
   }

   public function store(string $id, string $fname, string $data): void
   {
      file_put_contents($this->getPath($id, $fname), $data);
   }

   public function load(string $id, string $fname): string
   {
      return file_get_contents($this->getPath($id, $fname));
   }

   public function drop(string $id, string $fname)
   {
      unlink($this->getPath($id, $fname));
   }

   public function cleanup($id): void
   {
      $dir = $this->getPath($id);
      if (!is_dir($dir)) return;

      $iterator = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
      $files = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST);

      foreach ($files as $file)
      {
         if ($file->isDir())
         {
            rmdir($file->getRealPath());
         }
         else
         {
            unlink($file->getRealPath());
         }
      }

      rmdir($dir);
   }

   private function getPath(string $id, ?string $fname = null): string
   {
      $path = $this->basePath . "/$id";
      if (!is_dir($path) && !mkdir($path, 0700, true))
      {
         throw new \RuntimeException("Could not create import storage directory for id $id: " . error_get_last()['message']);
      }
      if( $fname ) $path .= "/$fname";
      return $path;
   }
}