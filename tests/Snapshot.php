<?php

namespace App\Services;

use Chumper\Zipper\Facades\Zipper;
use Illuminate\Support\Facades\File;
use Spatie\DbSnapshots\SnapshotFactory;
use Spatie\DbSnapshots\SnapshotRepository;

class Snapshot
{
    /*
     * $command = 'mysql --user=homestead --password=secret hearmeplease < /home/vagrant/projects/hearmeplease/database/snapshots/28.01.2018/rests.sql';
     */

    /**
     * Имя файла
     *
     * tmp/zip
     * zip
     * users
     *
     * @var string
     */
    private $name = '';

    /**
     * Выбрать .zip файл для распаковки
     *
     * @var string
     */
    private $loadFromZip = '';

    /**
     * Временная директория распоковать куда
     *
     * @var string
     */
    private $loadToPath = '';

    /**
     * Ключ для директории для распаковки
     *
     * @var null|string
     */
    private $loadPathKey = null;

    /**
     * Упаковывать ли файлы в архив?
     * при 400MiB:
     * - с true ошибка упаковки по скорости, мб больше 30 секунд потому что
     * - с false время создания ~ 16 секунд
     *
     * @var bool
     */
    private $withZip = false;

    private $start = null;

    public function __construct($name)
    {
        $this->name = $name;

        $this->loadFromZip = database_path('snapshots/'.$this->name.'.zip');
        $this->loadPathKey = str_random('16');
        $this->loadToPath = database_path('snapshots/'.$this->loadPathKey);


        $this->start = microtime(true);
    }

    /**
     * Загруть таблицы в .zip
     *
     * (new Snapshot('27.07.2018'))->create('*', ['listings_*']);
     *
     * @param array $tables таблицы которые надо загрузить или *
     * @param array $reject таблицы которые не надо загружать ['listings_*', 'listings*'] или пустой
     * @return $this
     */
    public function create($tables = [], $reject = [])
    {
        app(SnapshotFactory::class)->create(
            $this->name,
            config('db-snapshots.disk'),
            config('database.default'),
            $tables,
            $reject,
            $this->withZip
        );

        $this->start = microtime(true) - $this->start;
        $this->start = gmdate("H:i:s", $this->start);

        dd($this->start);

        return $this;
    }

    /**
     * Загрузить sql в систему
     *
     * 1. Распаковать архив в папку /snapshots/{str_random('16')}/
     * 2.1 Выбрать файлы которые надо загрузить
     * 2.2 Выбрать все файлы из папки
     *
     * (new Snapshot('26.07.2018'))->load(['users']);
     *
     * @param array $tables таблицы которые надо загрузить
     * @return $this
     */
    public function load($tables = [])
    {
        if ($this->withZip) {
            Zipper::make($this->loadFromZip)->extractTo($this->loadToPath);
        } else {
            $this->loadToPath = database_path('snapshots/'.$this->name);
            $this->loadPathKey = $this->name;
        }
        if (!empty($tables)) {
            foreach ($tables as $key => $table) {
                $snapshot = app(SnapshotRepository::class)->findByName($this->loadPathKey, $table);
                $snapshot->load($table);
            }
        } else {
            $files = File::allFiles($this->loadToPath);
            foreach ($files as $file) {
                $table = str_replace('.sql', '', $file->getFileName());
                $snapshot = app(SnapshotRepository::class)->findByName($this->loadPathKey, $table);
                $snapshot->load($table);
            }
        }

        if ($this->withZip) {
            File::deleteDirectory($this->loadToPath);
        }

        $this->start = microtime(true) - $this->start;
        $this->start = gmdate("H:i:s", $this->start);

        dd($this->start);

        return $this;
    }
}