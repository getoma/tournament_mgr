<?php

namespace Base\Service;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class DbUpdateService
{
   private \Phinx\Migration\Manager $phinx;
   private BufferedOutput $buf;

   const ENV = 'default';

   public function __construct(private array $db_connection_data, private string $db_path)
   {
      $config = [
         'paths' => [
            'migrations' => $this->db_path . '/migrations',
         ],
         'environments' => [
            'default_migration_table' => 'phinxlog',
            self::ENV => [
               'adapter' => 'mysql',
               'host' => $this->db_connection_data['server'] ?? 'localhost',
               'name' => $this->db_connection_data['db'],
               'user' => $this->db_connection_data['user'],
               'pass' => $this->db_connection_data['pw'] ?? '',
               'port' => $this->db_connection_data['port'] ?? 3306,
               'charset' => 'utf8mb4'
            ]
         ]
      ];

      $this->buf = new BufferedOutput();

      $this->phinx = new \Phinx\Migration\Manager(
         new \Phinx\Config\Config($config),
         new StringInput(''),
         $this->buf
      );
   }

   public function updateNeeded(): bool
   {
      /* fetch latest available migration, and the currently deployed version */
      $target_version = max(array_keys($this->phinx->getMigrations(self::ENV)));
      $current = $this->phinx->getEnvironment(self::ENV)->getCurrentVersion();
      /* purge the output buffer, which is needed because phinx was implemented by idiots */
      $this->buf->fetch();
      /* update is needed if target_version and current version don't match */
      return $target_version !== $current;
   }

   public function update(): string
   {
      $this->phinx->migrate(self::ENV);
      return $this->buf->fetch();
   }
}