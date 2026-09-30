<?php

namespace webdna\scheduler\tests;

use PHPUnit\Framework\TestCase;
use webdna\scheduler\OutputReplay;

class OutputReplayTest extends TestCase
{
    private string $file;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'scheduler');
        $this->written = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function replay(): OutputReplay
    {
        return new OutputReplay($this->file, function(string $bytes): void {
            $this->written[] = $bytes;
        });
    }

    public function testWhatWasAlreadyInTheFileIsNotReplayed(): void
    {
        file_put_contents($this->file, "yesterday\n");
        $replay = $this->replay();

        file_put_contents($this->file, "job one\n", FILE_APPEND);
        $replay();

        $this->assertSame(["job one\n"], $this->written);
    }

    public function testEachJobReplaysOnlyWhatItAppended(): void
    {
        $replay = $this->replay();

        file_put_contents($this->file, "one\n", FILE_APPEND);
        $replay();
        file_put_contents($this->file, "two\n", FILE_APPEND);
        $replay();
        $replay(); // a job that printed nothing

        $this->assertSame(["one\n", "two\n"], $this->written);
    }

    public function testATruncatedFileIsReadFromTheStart(): void
    {
        file_put_contents($this->file, str_repeat('x', 100));
        $replay = $this->replay();

        file_put_contents($this->file, "fresh\n");
        $replay();

        $this->assertSame(["fresh\n"], $this->written);
    }

    public function testAMissingFileIsNotAnError(): void
    {
        unlink($this->file);
        $replay = $this->replay();
        $replay();

        $this->assertSame([], $this->written);
    }
}
