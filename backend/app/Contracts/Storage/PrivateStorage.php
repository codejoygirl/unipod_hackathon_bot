<?php

namespace App\Contracts\Storage;

interface PrivateStorage
{
    /**
     * Store private binary contents and return the relative path.
     */
    public function put(string $path, string $contents): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function get(string $path): string;
}
