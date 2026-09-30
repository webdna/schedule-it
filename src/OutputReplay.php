<?php

namespace webdna\scheduler;

/**
 * Replays what each job appended to the log file, once the job has finished.
 *
 * The file on its own is worthless on a host like Servd, for two reasons that stack: a
 * scheduled task runs in a short-lived container whose filesystem goes with it, and the
 * host collects logs from stdout only. Pointing the job at /dev/stdout does not help
 * either — every job runs through Symfony Process, which buffers the child's output into
 * a stream the library never reads. Only the runner's own output reaches the host.
 *
 * So the job writes to the file and the runner echoes the new bytes: one code path, both
 * destinations. Jobs run one after another, so a single high-water mark is enough.
 */
final class OutputReplay
{
    private int $offset;

    /** @var callable(string): void */
    private $write;

    /**
     * @param callable(string): void $write
     */
    public function __construct(private readonly string $file, callable $write)
    {
        $this->write = $write;
        $this->offset = $this->size();
    }

    public function __invoke(): void
    {
        $size = $this->size();

        // Something truncated or replaced the file between jobs: start from the top rather
        // than reading a negative slice.
        if ($size < $this->offset) {
            $this->offset = 0;
        }

        if ($size > $this->offset && ($handle = fopen($this->file, 'rb')) !== false) {
            fseek($handle, $this->offset);
            $bytes = stream_get_contents($handle);
            fclose($handle);

            if ($bytes !== false && $bytes !== '') {
                ($this->write)($bytes);
            }
        }

        $this->offset = $size;
    }

    private function size(): int
    {
        clearstatcache(true, $this->file);

        return is_file($this->file) ? (int)filesize($this->file) : 0;
    }
}
