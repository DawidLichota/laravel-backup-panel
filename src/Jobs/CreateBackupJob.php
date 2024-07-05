<?php

namespace PavelMironchik\LaravelBackupPanel\Jobs;

use Illuminate\Bus\Queueable;
use Spatie\Backup\Config\Config;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected $option;

    public function __construct($option = '')
    {
        $this->option = $option;
    }

    public function handle()
    {

        $backupConfig = Config::fromArray(config('backup'));

        $backupJob = BackupJobFactory::createFromConfig($backupConfig);

        if ($this->option === 'only-db') {
            $backupJob->dontBackupFilesystem();
        }

        if ($this->option === 'only-files') {
            $backupJob->dontBackupDatabases();
        }

        if (! empty($this->option)) {
            $prefix = str_replace('_', '-', $this->option).'-';

            $backupJob->setFilename($prefix.date('Y-m-d-H-i-s').'.zip');
        }

        $backupJob->run();
    }
}
