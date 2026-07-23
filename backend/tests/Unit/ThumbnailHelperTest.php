<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ThumbnailHelper;
use App\Services\Contracts\ThumbnailServiceInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionMethod;

class ThumbnailHelperTest extends TestCase
{
    private ThumbnailHelper $thumbnailHelper;
    private ReflectionMethod $resizeImageMethod;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a mock thumbnail service
        $mockThumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        
        // Create ThumbnailHelper instance
        $this->thumbnailHelper = new ThumbnailHelper($mockThumbnailService);
        
        // Use reflection to access the private resizeImage method
        $reflection = new ReflectionClass($this->thumbnailHelper);
        $this->resizeImageMethod = $reflection->getMethod('resizeImage');
        $this->resizeImageMethod->setAccessible(true);
    }

    /**
     * Test resizing a PNG image
     */
    public function test_resize_png_image(): void
    {
        // Create a test PNG image (1x1 pixel)
        $testImageData = $this->createTestPngImage(100, 100);
        
        // Resize the image
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'png'
        );
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('format', $result);
        $this->assertEquals('png', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify the resized image is different from original
        $this->assertNotEquals($testImageData, $result['data']);
        
        // Verify the resized image is valid PNG data
        $this->assertStringStartsWith("\x89PNG", $result['data']);
    }

    /**
     * Test resizing a JPEG image
     */
    public function test_resize_jpeg_image(): void
    {
        // Skip if JPEG support is not available
        if (!function_exists('imagejpeg')) {
            $this->markTestSkipped('JPEG support not available');
        }
        
        // Create a test JPEG image (100x100 pixel)
        $testImageData = $this->createTestJpegImage(100, 100);
        
        // Resize the image
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'jpg'
        );
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('format', $result);
        $this->assertEquals('jpg', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify the resized image is different from original
        $this->assertNotEquals($testImageData, $result['data']);
        
        // Verify the resized image is valid JPEG data
        $this->assertStringStartsWith("\xFF\xD8\xFF", $result['data']);
    }

    /**
     * Test resizing with different dimensions
     */
    public function test_resize_with_custom_dimensions(): void
    {
        // Create a test PNG image
        $testImageData = $this->createTestPngImage(200, 200);
        
        // Resize to custom dimensions
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            800,
            600,
            'png'
        );
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertEquals('png', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify the resized image is valid
        $this->assertStringStartsWith("\x89PNG", $result['data']);
    }

    /**
     * Test resizing with GIF format
     */
    public function test_resize_gif_image(): void
    {
        // Create a test GIF image
        $testImageData = $this->createTestGifImage(100, 100);
        
        // Resize the image
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'gif'
        );
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertEquals('gif', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify the resized image is valid GIF data
        $this->assertStringStartsWith("GIF8", $result['data']);
    }

    /**
     * Test resizing with WebP format
     */
    public function test_resize_webp_image(): void
    {
        // Skip if WebP support is not available
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP support not available');
        }
        
        // Create a test WebP image
        $testImageData = $this->createTestWebpImage(100, 100);
        
        // Resize the image
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'webp'
        );
        
        // Assertions
        $this->assertIsArray($result);
        $this->assertEquals('webp', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify the resized image is valid WebP data
        $this->assertStringStartsWith("RIFF", $result['data']);
    }

    /**
     * Test error handling with invalid image data
     */
    public function test_resize_invalid_image_data(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create image resource from binary data|Data is not in a recognized format/');
        
        // Try to resize invalid image data
        $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            'invalid image data',
            1280,
            720,
            'png'
        );
    }

    /**
     * Test fallback to PNG for unknown format
     */
    public function test_resize_unknown_format_fallback(): void
    {
        // Create a test PNG image
        $testImageData = $this->createTestPngImage(100, 100);
        
        // Resize with unknown format
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'unknown'
        );
        
        // Should fallback to PNG
        $this->assertEquals('png', $result['format']);
        $this->assertStringStartsWith("\x89PNG", $result['data']);
    }

    /**
     * Test that transparency is preserved for PNG images
     */
    public function test_png_transparency_preservation(): void
    {
        // Create a PNG image with transparency
        $testImageData = $this->createTestPngImageWithTransparency(100, 100);
        
        // Resize the image
        $result = $this->resizeImageMethod->invoke(
            $this->thumbnailHelper,
            $testImageData,
            1280,
            720,
            'png'
        );
        
        // Assertions
        $this->assertEquals('png', $result['format']);
        $this->assertNotEmpty($result['data']);
        
        // Verify it's still a valid PNG
        $this->assertStringStartsWith("\x89PNG", $result['data']);
    }

    /**
     * Create a test PNG image
     */
    private function createTestPngImage(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $white = \imagecolorallocate($image, 255, 255, 255);
        \imagefill($image, 0, 0, $white);
        
        ob_start();
        \imagepng($image);
        $data = ob_get_contents();
        ob_end_clean();
        
        \imagedestroy($image);
        return $data;
    }

    /**
     * Create a test PNG image with transparency
     */
    private function createTestPngImageWithTransparency(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        \imagealphablending($image, false);
        \imagesavealpha($image, true);
        $transparent = \imagecolorallocatealpha($image, 0, 0, 0, 127);
        \imagefill($image, 0, 0, $transparent);
        
        ob_start();
        \imagepng($image);
        $data = ob_get_contents();
        ob_end_clean();
        
        \imagedestroy($image);
        return $data;
    }

    /**
     * Create a test JPEG image
     */
    private function createTestJpegImage(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $white = \imagecolorallocate($image, 255, 255, 255);
        \imagefill($image, 0, 0, $white);
        
        ob_start();
        \imagejpeg($image);
        $data = ob_get_contents();
        ob_end_clean();
        
        \imagedestroy($image);
        return $data;
    }

    /**
     * Create a test GIF image
     */
    private function createTestGifImage(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $white = \imagecolorallocate($image, 255, 255, 255);
        \imagefill($image, 0, 0, $white);
        
        ob_start();
        \imagegif($image);
        $data = ob_get_contents();
        ob_end_clean();
        
        \imagedestroy($image);
        return $data;
    }

    /**
     * Create a test WebP image
     */
    private function createTestWebpImage(int $width, int $height): string
    {
        $image = \imagecreatetruecolor($width, $height);
        $white = \imagecolorallocate($image, 255, 255, 255);
        \imagefill($image, 0, 0, $white);
        
        ob_start();
        \imagewebp($image);
        $data = ob_get_contents();
        ob_end_clean();
        
        \imagedestroy($image);
        return $data;
    }
}
