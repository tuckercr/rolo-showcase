<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\AttachmentService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rolo-att-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function service(): AttachmentService
    {
        return new AttachmentService($this->dir);
    }

    private function tmpFileWith(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function testAcceptsRealPdf(): void
    {
        $tmp = $this->tmpFileWith("%PDF-1.4\n%stub content");

        $this->service()->validate('proposal.pdf', $tmp, 1000, UPLOAD_ERR_OK);
        $this->addToAssertionCount(1);
        unlink($tmp);
    }

    public function testRejectsFakePdfContainingScript(): void
    {
        $tmp = $this->tmpFileWith("<?php system('id');");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't look like a real .pdf");

        try {
            $this->service()->validate('evil.pdf', $tmp, 100, UPLOAD_ERR_OK);
        } finally {
            unlink($tmp);
        }
    }

    public function testRejectsDisallowedExtensions(): void
    {
        $tmp = $this->tmpFileWith('<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("isn't an allowed file type");

        try {
            $this->service()->validate('image.svg', $tmp, 100, UPLOAD_ERR_OK);
        } finally {
            unlink($tmp);
        }
    }

    public function testRejectsOversizedFiles(): void
    {
        $tmp = $this->tmpFileWith('%PDF-1.4');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('too large');

        try {
            $this->service()->validate('big.pdf', $tmp, AttachmentService::MAX_BYTES + 1, UPLOAD_ERR_OK);
        } finally {
            unlink($tmp);
        }
    }

    public function testRejectsFailedUploads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('failed to upload');

        $this->service()->validate('doc.pdf', '/nonexistent', 100, UPLOAD_ERR_PARTIAL);
    }

    public function testStoreUsesRandomNameAndKeepsOriginalAsMetadata(): void
    {
        $tmp = $this->tmpFileWith("%PDF-1.4\ncontent");

        $meta = $this->service()->store('Q3 Proposal (final).pdf', $tmp, 16);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $meta['stored_name']);
        $this->assertSame('Q3 Proposal (final).pdf', $meta['original_name']);
        $this->assertSame('application/pdf', $meta['mime_type']);
        $this->assertFileExists($this->dir . '/' . $meta['stored_name']);
    }

    public function testPathNeverEscapesStorageDir(): void
    {
        $path = $this->service()->path('../../.env');

        $this->assertSame($this->dir . '/.env', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    public function testNormalizeMultiUploadSkipsEmptySlots(): void
    {
        $files = [
            'name' => ['a.pdf', ''],
            'tmp_name' => ['/tmp/x', ''],
            'size' => [10, 0],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
        ];

        $normalized = AttachmentService::normalizeMultiUpload($files);

        $this->assertCount(1, $normalized);
        $this->assertSame('a.pdf', $normalized[0]['name']);
    }
}
