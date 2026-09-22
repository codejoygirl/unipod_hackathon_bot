<?php

namespace App\Services\Storage;

use App\Contracts\Storage\PrivateStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocalPrivateStorage implements PrivateStorage
{
    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('local');
    }

    public function put(string $path, string $contents): string
    {
        $stored = $this->disk->put($path, $contents);

        if ($stored === false) {
            throw new RuntimeException("Unable to store private file at [{$path}].");
        }

        return $path;
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk->delete($path);
    }

    public function get(string $path): string
    {
        return $this->disk->get($path);
    }
}
