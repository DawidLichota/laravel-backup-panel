<?php

namespace PavelMironchik\LaravelBackupPanel\Http\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Illuminate\Http\Response;
use Spatie\Backup\Helpers\Format;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Spatie\Backup\BackupDestination\Backup;
use Illuminate\Validation\ValidationException;
use PavelMironchik\LaravelBackupPanel\Rules\PathToZip;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PavelMironchik\LaravelBackupPanel\Rules\BackupDisk;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use PavelMironchik\LaravelBackupPanel\Jobs\CreateBackupJob;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;

class App extends Component
{
    public $backupStatuses = [];

    public $activeDisk = null;

    public $disks = [];

    public $files = [];

    public $deletingFile = null;

    public function updateBackupStatuses()
    {
        $this->backupStatuses = Cache::remember('backup-statuses', now()->addSeconds(4), function () {
            $monitoredBackupConfig = \Spatie\Backup\Config\MonitoredBackupsConfig::fromArray(config('backup.monitor_backups'));
            return BackupDestinationStatusFactory::createForMonitorConfig($monitoredBackupConfig)->map(function (BackupDestinationStatus $backupDestinationStatus){
                $destination = $backupDestinationStatus->backupDestination();
                return [
                    'name' => $destination->backupName(),
                    'disk' => $destination->diskName(),
                    'reachable' => $destination->isReachable(),
                    'healthy' => $backupDestinationStatus->isHealthy(),
                    'amount' => $destination->backups()->count(),
                    'newest' => $destination->newestBackup()
                        ? $backupDestinationStatus->backupDestination()->newestBackup()->date()->diffForHumans()
                        : 'No backups present',
                    'usedStorage' => Format::humanReadableSize($backupDestinationStatus->backupDestination()->usedStorage()),
                ];
            })->all();
        });
            
        if (! $this->activeDisk and count($this->backupStatuses)) {
            $this->activeDisk = $this->backupStatuses[0]['disk'];
        }

        $this->disks = collect($this->backupStatuses)
            ->map(function ($backupStatus) {
                return $backupStatus['disk'];
            })
            ->values()
            ->all();
        $this->dispatch('backupStatusesUpdated');
    }

    #[On('backupStatusesUpdated')]
    public function getFiles(string $disk = '')
    {
        if ($disk) {
            $this->activeDisk = $disk;
        }

        $this->validateActiveDisk();  

        $backupDestination = BackupDestination::create($this->activeDisk, config('backup.backup.name'));

        $this->files = Cache::remember("backups-{$this->activeDisk}", now()->addSeconds(4), function () use ($backupDestination) {
            return $backupDestination
                ->backups()
                ->map(function (Backup $backup) {
                    $size = method_exists($backup, 'sizeInBytes') ? $backup->sizeInBytes() : $backup->size();
                    return [
                        'path' => $backup->path(),
                        'date' => $backup->date()->format('Y-m-d H:i:s'),
                        'size' => Format::humanReadableSize($size),
                    ];
                })
                ->toArray();
        });
    }

    public function showDeleteModal($fileIndex)
    {
        $this->deletingFile = $this->files[$fileIndex];

        $this->dispatch('showDeleteModal');
    }

    public function deleteFile()
    {
        $deletingFile = $this->deletingFile;
        $this->deletingFile = null;

        $this->dispatch('hideDeleteModal');

        $this->validateActiveDisk();
        $this->validateFilePath($deletingFile ? $deletingFile['path'] : '');

        $backupDestination = BackupDestination::create($this->activeDisk, config('backup.backup.name'));

        $backupDestination
            ->backups()
            ->first(function (Backup $backup) use ($deletingFile) {
                return $backup->path() === $deletingFile['path'];
            })
            ->delete();

        $this->files = collect($this->files)
            ->reject(function ($file) use ($deletingFile) {
                return $file['path'] === $deletingFile['path']
                    && $file['date'] === $deletingFile['date']
                    && $file['size'] === $deletingFile['size'];
            })
            ->values()
            ->all();
    }

    public function downloadFile(string $filePath)
    {
        $this->validateActiveDisk();
        $this->validateFilePath($filePath);

        $backupDestination = BackupDestination::create($this->activeDisk, config('backup.backup.name'));

        $backup = $backupDestination->backups()->first(function (Backup $backup) use ($filePath) {
            return $backup->path() === $filePath;
        });

        if (! $backup) {
            return response('Backup not found', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->respondWithBackupStream($backup);
    }

    public function respondWithBackupStream(Backup $backup): StreamedResponse
    {
        $fileName = pathinfo($backup->path(), PATHINFO_BASENAME);
        $size = method_exists($backup, 'sizeInBytes') ? $backup->sizeInBytes() : $backup->size();

        $downloadHeaders = [
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type' => 'application/zip',
            'Content-Length' => $size,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Pragma' => 'public',
        ];

        return response()->stream(function () use ($backup) {
            $stream = $backup->stream();

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, $downloadHeaders);
    }

    public function createBackup(string $option = '')
    {
        dispatch(new CreateBackupJob($option))
            ->onQueue(config('laravel_backup_panel.queue'));
    }

    public function render()
    {
        return view('laravel_backup_panel::livewire.app');
    }

    public function funBackup($option = ''){
        $this->js("        
            Toastify({
                text: 'Creating a new backup in the background... ($option)',
                duration: 5000,
                gravity: 'bottom',
                position: 'right',
                backgroundColor: '#1fb16e',
                className: 'toastify-custom',
            }).showToast()
        ");
        $this->createBackup($option);
    }

    protected function validateActiveDisk()
    {
        try {
            Validator::make(
                ['activeDisk' => $this->activeDisk],
                [
                    'activeDisk' => ['required', new BackupDisk()],
                ],
                [
                    'activeDisk.required' => 'Select a disk',
                ]
            )->validate();
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->get('activeDisk')[0];
            $this->dispatch('showErrorToast', $message);

            throw $e;
        }
    }

    protected function validateFilePath(string $filePath)
    {
        try {
            Validator::make(
                ['file' => $filePath],
                [
                    'file' => ['required', new PathToZip()],
                ],
                [
                    'file.required' => 'Select a file',
                ]
            )->validate();
        } catch (ValidationException $e) {
            $message = $e->validator->errors()->get('file')[0];
            $this->dispatch('showErrorToast', $message);

            throw $e;
        }
    }
}
