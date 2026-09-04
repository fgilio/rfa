<?php

declare(strict_types=1);

namespace App\Support;

use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToReadFile;

final class NonBlockingLocalFilesystemAdapter extends LocalFilesystemAdapter
{
    private const FILE_TYPE_MASK = 0170000;

    private const REGULAR_FILE_TYPE = 0100000;

    private readonly PathPrefixer $pathPrefixer;

    public function __construct(string $location, int $linkHandling = self::DISALLOW_LINKS)
    {
        parent::__construct($location, linkHandling: $linkHandling);

        $this->pathPrefixer = new PathPrefixer($location, DIRECTORY_SEPARATOR);
    }

    /**
     * Open first, then validate the descriptor so a path replacement cannot
     * turn a prior regular-file check into a blocking FIFO read.
     *
     * @return resource
     */
    public function readStream(string $path)
    {
        $location = $this->pathPrefixer->prefixPath($path);
        error_clear_last();

        $stream = @fopen($location, 'rbn');
        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, error_get_last()['message'] ?? '');
        }

        $metadata = fstat($stream);
        if ($metadata === false || ($metadata['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE) {
            fclose($stream);

            throw UnableToReadFile::fromLocation($path, 'Path is not a regular file.');
        }

        return $stream;
    }
}
