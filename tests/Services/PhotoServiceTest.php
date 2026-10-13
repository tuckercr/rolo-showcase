<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\PhotoService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhotoServiceTest extends TestCase
{
    private string $dir;

    private PhotoService $service;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rolo-photo-test-' . bin2hex(random_bytes(4));
        $this->service = new PhotoService($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function makeImage(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 120, 200));
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        imagepng($img, $tmp);

        return $tmp;
    }

    public function testStoresASquareJpegAtTheTargetSize(): void
    {
        $tmp = $this->makeImage(100, 50);
        $stored = $this->service->store($tmp);
        unlink($tmp);

        $path = $this->service->path($stored);
        $info = getimagesize($path);

        $this->assertStringEndsWith('.jpg', $stored);
        $this->assertSame(PhotoService::SIZE, $info[0]);
        $this->assertSame(PhotoService::SIZE, $info[1]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function testRejectsBytesThatAreNotAnImage(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($tmp, 'just some text');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service->store($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testValidateRejectsOversizedUploads(): void
    {
        $tmp = $this->makeImage(10, 10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        try {
            $this->service->validate($tmp, PhotoService::MAX_BYTES + 1, UPLOAD_ERR_OK);
        } finally {
            unlink($tmp);
        }
    }

    public function testValidateRejectsNonImageMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($tmp, '%PDF-1.4 fake');

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service->validate($tmp, 100, UPLOAD_ERR_OK);
        } finally {
            unlink($tmp);
        }
    }

    public function testDeleteRemovesTheStoredFile(): void
    {
        $tmp = $this->makeImage(60, 60);
        $stored = $this->service->store($tmp);
        unlink($tmp);

        $this->assertFileExists($this->service->path($stored));
        $this->service->delete($stored);
        $this->assertFileDoesNotExist($this->service->path($stored));
    }
}
