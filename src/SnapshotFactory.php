<?php

namespace Spatie\DbSnapshots;

use Chumper\Zipper\Facades\Zipper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\DbDumper\DbDumper;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Filesystem\FilesystemAdapter;
use Spatie\DbSnapshots\Events\CreatedSnapshot;
use Spatie\DbSnapshots\Events\CreatingSnapshot;
use Spatie\TemporaryDirectory\TemporaryDirectory;
use Spatie\DbSnapshots\Exceptions\CannotCreateDisk;

class SnapshotFactory
{
    /** @var \Spatie\DbSnapshots\DbDumperFactory */
    protected $dumperFactory;

    /** @var \Illuminate\Contracts\Filesystem\Factory */
    protected $filesystemFactory;

    public function __construct(DbDumperFactory $dumperFactory, Factory $filesystemFactory)
    {
        $this->dumperFactory = $dumperFactory;

        $this->filesystemFactory = $filesystemFactory;
    }

    public function create(string $snapshotName, string $diskName, string $connectionName, $tables = '*', $reject = [], $withZip = true): Snapshot
    {
        $disk = $this->getDisk($diskName);

        $path = $snapshotName;

        $fileName = $snapshotName.'.sql';
        $fileName = pathinfo($fileName, PATHINFO_BASENAME);

        event(new CreatingSnapshot(
            $fileName,
            $disk,
            $connectionName
        ));

        $this->createDump($connectionName, $fileName, $disk, $path, $tables, $reject, $withZip);

        $snapshot = new Snapshot($disk, $fileName);

        event(new CreatedSnapshot($snapshot));

        return $snapshot;
    }

    protected function getDisk(string $diskName): FilesystemAdapter
    {
        if (is_null(config("filesystems.disks.{$diskName}"))) {
            throw CannotCreateDisk::diskNotDefined($diskName);
        }

        return $this->filesystemFactory->disk($diskName);
    }

    protected function getDbDumper(string $connectionName): DbDumper
    {
        $factory = $this->dumperFactory;

        return $factory::createForConnection($connectionName);
    }

    protected function createDump(string $connectionName, string $fileName, FilesystemAdapter $disk, $path = '', $tables, $reject, $withZip)
    {
        $aTables = $this->columns($tables, $reject);

        $directory = (new TemporaryDirectory(config('db-snapshots.temporary_directory_path')))->create();

        $fileName = str_replace('.sql', '', $fileName);

        if ($withZip) {
            foreach ($aTables as $key => $aTable) {
                $name = $aTable.'.sql';
                $dumpPath = $directory->path($name);
                $this->getDbDumper($connectionName)->includeTables([$aTable])->dumpToFile($dumpPath);
            }
            $zip = $directory->path().'/'.$fileName.'.zip';

            Zipper::make($zip)->add($directory->path())->close();

            $to = database_path('snapshots').'/'.$path;

            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0777, true);
            }

            File::move($zip, database_path('snapshots').'/'.$path.'.zip');
        } else {
            $to = database_path('snapshots').'/'.$path;
            if (!is_dir($to)) {
                File::makeDirectory($to, 0777, true);
            }

            foreach ($aTables as $key => $aTable) {
                $name = $aTable.'.sql';
                $dumpPath = $to.'/'.$name;
                $this->getDbDumper($connectionName)->includeTables([$aTable])->dumpToFile($dumpPath);
            }
        }

        $directory->delete();
    }


    private function columns($tables, $reject)
    {
        $aTables = array_map('reset', DB::select('SHOW TABLES'));

        if (!empty($tables) && $tables !== '*') {
            $aTables = collect($aTables)->reject(function ($name) use ($tables) {
                return !in_array($name, $tables);
            })->toArray();
        }
        if (!empty($reject)) {
            foreach ($reject as $table) {
                $pos = strpos($table, '*');
                if ($pos !== false) {
                    $aTables = collect($aTables)->reject(function ($name) use ($table) {
                        return starts_with($name, str_replace('*', '', $table));
                    })->toArray();
                }
            }
            $aTables = collect($aTables)->reject(function ($name) use ($reject) {
                return in_array($name, $reject);
            })->toArray();
        }

        return $aTables;
    }
}
