<?php

namespace Spatie\DbSnapshots;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Filesystem\Filesystem as Disk;

class SnapshotRepository
{
    /** @var \Illuminate\Filesystem\FilesystemAdapter */
    protected $disk;

    public function __construct(Disk $disk)
    {
        $this->disk = $disk;
    }

    public function getAll($path = ''): Collection
    {
        $files = $path !== '' ? $this->disk->allFiles($path)
            : $this->disk->allFiles();
        return collect($files)
            ->filter(function (string $fileName) {
                return pathinfo($fileName, PATHINFO_EXTENSION) === 'sql';
            })
            ->map(function (string $fileName) {
                return new Snapshot($this->disk, $fileName);
            })
            ->sortByDesc(function (Snapshot $snapshot) {
                return $snapshot->createdAt()->toDateTimeString();
            });
    }

    public function findByName(string $path = '', $name = '')
    {
        if ($name === '') {
            $name = $path;
            $path = '';
        }
        if (!is_array($name)) {
            return $this->getAll($path)->first(function (Snapshot $snapshot) use ($name) {
                return $snapshot->name === $name;
            });
        } else {
            return $this->getAll($path)->filter(function (Snapshot $snapshot) use ($name) {
                return in_array($snapshot->name, $name);
            });
        }

    }
}
